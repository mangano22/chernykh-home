<?php

namespace MovaviShopBundle\Controller;

use AppBundle\Payment\Exception\DuplicateCartProcessingException;
use AppBundle\Payment\ExternalPaymentProcessor;
use AppBundle\Routing\CustomCMSPageRouter;
use Exception;
use Google\Cloud\Core\Exception\NotFoundException;
use MovaviCMSBundle\Entity\Page;
use MovaviCMSBundle\Entity\Site;
use MovaviCMSBundle\GeoIP\CountryDetector;
use MovaviCMSBundle\Repository\PageRepository;
use MovaviCMSBundle\Repository\SiteRepository;
use MovaviShopBundle\Currency\CurrencyDetector;
use MovaviShopBundle\Entity\Coupon;
use MovaviShopBundle\Entity\Currency;
use MovaviShopBundle\Entity\PaymentMethod;
use MovaviShopBundle\Entity\PriceOption;
use MovaviShopBundle\Entity\ProductBuyLink;
use MovaviShopBundle\Entity\ShopParam;
use MovaviShopBundle\Enums\CartPopup;
use MovaviShopBundle\Event\CrossSellEvent;
use MovaviShopBundle\Event\CrossSellEvents;
use MovaviShopBundle\Form\Type\CartType;
use MovaviShopBundle\Form\Type\Checkout\BaseCartType;
use MovaviShopBundle\Form\Type\InHouseCartType;
use MovaviShopBundle\Payment\AbstractPaymentProcessor;
use MovaviShopBundle\Payment\Affiliate\Affiliate;
use MovaviShopBundle\Payment\Affiliate\Extractor\AffiliateInfoExtractorChain;
use MovaviShopBundle\Payment\CartStorage\CartId;
use MovaviShopBundle\Payment\CartStorage\CartStorageInterface;
use MovaviShopBundle\Payment\CartStorage\ChainCartStorage;
use MovaviShopBundle\Payment\Event\CartEvent;
use MovaviShopBundle\Payment\Event\PaymentEvents;
use MovaviShopBundle\Payment\Exception\CartEmptyException;
use MovaviShopBundle\Payment\Exception\InvoiceException;
use MovaviShopBundle\Payment\Exception\PopupNotFoundException;
use MovaviShopBundle\Payment\Exception\SberbankException;
use MovaviShopBundle\Payment\ExternalPopupPaymentProcessorInterface;
use MovaviShopBundle\Payment\Factory\CartFactory;
use MovaviShopBundle\Payment\Factory\CartProductFactoryInterface;
use MovaviShopBundle\Payment\Factory\StoredCartFactory;
use MovaviShopBundle\Payment\HasFormRedirectProcessorInterface;
use MovaviShopBundle\Payment\HasOwnAdditionalHandlerPaymentProcessorInterface;
use MovaviShopBundle\Payment\InHousePaymentProcessorInterface;
use MovaviShopBundle\Payment\JsonResponseAwareInterface;
use MovaviShopBundle\Payment\ManualCurrencyAwareProcessorInterface;
use MovaviShopBundle\Payment\Method\MethodInterface;
use MovaviShopBundle\Payment\Model\Cart;
use MovaviShopBundle\Payment\Model\CartInterface;
use MovaviShopBundle\Payment\Model\CartProduct;
use MovaviShopBundle\Payment\Model\StoredCart;
use MovaviShopBundle\Payment\Model\StoredCartInterface;
use MovaviShopBundle\Payment\PaymentManager;
use MovaviShopBundle\Payment\PaymentMethodCollectionInterface;
use MovaviShopBundle\Payment\PaymentProcessor\PaymentProcessorProvider;
use MovaviShopBundle\Payment\PaymentProcessorInterface;
use MovaviShopBundle\Payment\PopupPaymentProcessorInterface;
use MovaviShopBundle\Payment\Transaction\TransactionIdAware;
use MovaviShopBundle\Payment\Transaction\TransactionInfoManager;
use MovaviShopBundle\Repository\ProductBuyLinkRepository;
use MovaviShopBundle\Repository\ShopParamRepository;
use MovaviShopBundle\WebUid\WebUid;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use WhichBrowser\Parser;
use OutOfBoundsException;
use LogicException;

class PaymentController extends AbstractController
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CurrencyDetector $currencyDetector,
        private readonly CartFactory $cartFactory,
        private readonly StoredCartFactory $storedCartFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PopupNotFoundException
     * @throws Exception
     */
    public function checkoutAction(Request $request): Response
    {
        try {
            $content    = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $currency   = $this->currencyDetector->detect();
            $storedCart = $this->storedCartFactory->create();
            $cart       = $this->cartFactory->create($storedCart, $currency);

            if ($response = $this->validateContentByForm(BaseCartType::class, $cart, $content, $request)) {
                return $response;
            }

            if ($cart->isEmpty()) {
                $this->logger->error('Cart is empty', ['content' => $content]);

                return new JsonResponse($this->getErrorBody('Cart is empty'), 400);
            }

            $paymentMethodCode = $cart->getPaymentMethodCode() ?: '';
            $methods           = $this->getPaymentMethods($request, $cart);
            $paymentMethod     = $this->findPaymentMethodByCode($methods, $paymentMethodCode);
            $processor         = $this->getProcessorByPaymentMethod($methods, $paymentMethodCode);
            if (
                !$processor instanceof AbstractPaymentProcessor
                || null === $paymentMethod
            ) {
                $this->logger->error('Payment method not allowed', ['content' => $content]);

                return new JsonResponse($this->getErrorBody('Payment method not allowed'), 400);
            }

            $extendedFormClass = $processor->getFormTypeForCheckoutByPaymentMethod($paymentMethodCode);
            if (
                BaseCartType::class !== $extendedFormClass
                && $response = $this->validateContentByForm($extendedFormClass, $cart, $content, $request)
            ) {
                return $response;
            }

            $this->saveCartStored($storedCart);

            $transactionId = $cart->getTransactionId() ?: '';
            $response      = $this->doProcessCart($processor, $cart, $transactionId, $paymentMethod, true);
            if ($response instanceof RedirectResponse) {
                return new JsonResponse(['redirectUrl' => $response->getTargetUrl()]);
            }

            return $response;
        } catch (LogicException|OutOfBoundsException $exception) {
            $this->logger->error(
                'Checkout failed',
                [
                    'message' => $exception->getMessage(),
                    'content' => $content ?? $request->getContent(),
                ]
            );

            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }
    }

    private function validateContentByForm(string $formType, Cart $cart, array $content, Request $request): ?Response
    {
        $form = $this->createForm($formType, $cart);
        $form->submit($content);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->isInvalidFormResponse($request, $form)
                ?? new JsonResponse(null, Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /**
     * @param array<string, array{processor: PaymentProcessorInterface, payment_methods: array<int, MethodInterface>}> $methods
     */
    private function findPaymentMethodByCode(array $methods, string $paymentMethodCode): ?MethodInterface
    {
        foreach ($methods as $processorInfo) {
            foreach ($processorInfo['payment_methods'] as $paymentMethod) {
                if ($paymentMethod->getCode() === $paymentMethodCode) {
                    return $paymentMethod;
                }
            }
        }

        return null;
    }

    /**
     * @throws PopupNotFoundException
     */
    private function doProcessCart(
        PaymentProcessorInterface $processor,
        Cart $cart,
        string $transactionId,
        mixed $paymentMethod,
        bool $isRedirect = false,
    ): Response {
        try {
            $processedCart = $processor->processCart($cart, $transactionId, $paymentMethod);

            if (!($processedCart instanceof Response && Response::HTTP_BAD_REQUEST === $processedCart->getStatusCode())) {
                $this->eventDispatcher->dispatch(
                    new CartEvent(
                        $cart,
                        $processor,
                        $processedCart instanceof FormInterface ? $processedCart : null,
                        $transactionId
                    ),
                    PaymentEvents::POST_CART
                );
            }

            if ($processedCart instanceof Response) {
                return $processedCart;
            }
        } catch (DuplicateCartProcessingException $e) {
            $processedCart = $e->getPaymentForm();
        }

        return $this->renderFormOrBuildRedirectResponse($processedCart, $processor, $isRedirect);
    }
}
