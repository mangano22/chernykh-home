<?php

namespace AppBundle\Payment\Avangate\Processor;

use AppBundle\Checker\QAChecker;
use AppBundle\Entity\AvangateProduct;
use AppBundle\Payment\AbstractPaymentProcessor;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use MovaviAnalyticsBundle\Customer\CustomerIdProviderInterface;
use MovaviShopBundle\Currency\CurrencyDetector;
use MovaviShopBundle\Form\Type\Checkout\TwoPayJsCartType;
use MovaviShopBundle\Payment\CartStorage\ChainCartStorage;
use MovaviShopBundle\Payment\InHousePaymentProcessorInterface;
use MovaviShopBundle\Payment\Method\MethodInterface;
use MovaviShopBundle\Payment\Model\CartInterface;
use MovaviShopBundle\Payment\Model\CartProduct;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use stdClass;
use JsonException;

class TwoPayJsByAvangatePaymentProcessor extends AbstractPaymentProcessor implements InHousePaymentProcessorInterface
{
    public const NAME = '2pay.js by Avangate';

    public const COUNTRY_CODE_PARAM     = 'countryCode';
    public const FISCAL_CODE_PARAM      = 'fiscalCode';
    public const TWO_PAY_JS_TOKEN_PARAM = '2payjsToken';
    public const REF_NO_PARAM           = 'RefNo';

    private const PAYMENT_TYPE      = 'EES_TOKEN_PAYMENT';
    private const TEST_PAYMENT_TYPE = 'TEST';
    private const DEFAULT_VALUE     = 'N/A';

    private const ORDER_STATUS_PENDING         = 'PENDING';
    private const ORDER_STATUS_AUTHRECEIVED    = 'AUTHRECEIVED';
    private const ORDER_APPROVE_STATUS_WAITING = 'WAITING';

    public function __construct(
        string $defaultCurrencyCode,
        CurrencyDetector $currencyDetector,
        EntityManagerInterface $entityManager,
        CustomerIdProviderInterface $customerIdProvider,
        private readonly ClientInterface $client,
        private readonly RequestStack $requestStack,
        private readonly QAChecker $qaChecker,
        private readonly ChainCartStorage $chainCartStorage,
        private readonly string $payUrl,
        LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($defaultCurrencyCode, $currencyDetector, $logger, $entityManager, $customerIdProvider);
    }

    public function getProcessorName(): string
    {
        return self::NAME;
    }

    public function isSupportedSubscriptionSale(): bool
    {
        return true;
    }

    public function getFormTypeForCheckoutByPaymentMethod(string $paymentMethodCode): string
    {
        return TwoPayJsCartType::class;
    }

    /**
     * @throws JsonException
     */
    public function processCart(CartInterface $cart, string $transactionId, ?MethodInterface $paymentMethod = null): JsonResponse
    {
        /** @var Request $request */
        $request  = $this->requestStack->getMainRequest();
        $order    = $this->prepareOrderObject($request, $cart);

        return $this->placeOrder($order, $cart);
    }

    private function prepareOrderObject(Request $request, CartInterface $cart): stdClass
    {
        $currencyCode = $this->getCurrencyCode();
        $countryCode  = $cart->getContextVariable(self::COUNTRY_CODE_PARAM);

        $order           = new stdClass();
        $order->Country  = $countryCode;
        $order->Currency = $currencyCode;
        $order->Language = $request->getLocale();

        foreach ($cart->getProducts() as $product) {
            $avangateProduct = $this->getAvangateProduct($product);

            $item           = new stdClass();
            $item->Code     = $avangateProduct->getCode();
            $item->Quantity = $product->getCount();
            $order->Items[] = $item;
        }

        foreach ($cart->getCoupons() as $coupon) {
            $order->Promotions[] = $coupon->getName();
        }

        $order->BillingDetails              = new stdClass();
        $order->BillingDetails->Address1    = $cart->getAddress() ?: self::DEFAULT_VALUE;
        $order->BillingDetails->City        = $cart->getCity() ?: self::DEFAULT_VALUE;
        $order->BillingDetails->CountryCode = $countryCode;
        $order->BillingDetails->Email       = $cart->getEmail();
        $order->BillingDetails->FirstName   = $cart->getName();
        $order->BillingDetails->LastName    = $cart->getLastName();
        $order->BillingDetails->FiscalCode  = $cart->getContextVariable(self::FISCAL_CODE_PARAM);
        $order->BillingDetails->Phone       = $cart->getPhone();
        $order->BillingDetails->State       = $cart->getState() ?: self::DEFAULT_VALUE;
        $order->BillingDetails->Zip         = $cart->getZIP() ?: self::DEFAULT_VALUE;

        $order->PaymentDetails                                    = new stdClass();
        $order->PaymentDetails->Currency                          = $currencyCode;
        $order->PaymentDetails->Type                              = $this->qaChecker->isEnabled() ? self::TEST_PAYMENT_TYPE : self::PAYMENT_TYPE;
        $order->PaymentDetails->PaymentMethod                     = new stdClass();
        $order->PaymentDetails->PaymentMethod->EesToken           = $cart->getContextVariable(self::TWO_PAY_JS_TOKEN_PARAM);
        $order->PaymentDetails->PaymentMethod->Vendor3DSReturnURL = $this->getReturnUrl($request, $cart);
        $order->PaymentDetails->PaymentMethod->Vendor3DSCancelURL = $this->getCancelUrl($request, $cart);

        return $order;
    }

    private function getAvangateProduct(CartProduct $product): AvangateProduct
    {
        $priceOption     = $product->getPriceOption();
        $avangateProduct = $this->entityManager->getRepository(AvangateProduct::class)->findByPriceOption($priceOption);
        if (!$avangateProduct instanceof AvangateProduct) {
            $this->logger->error(sprintf('AvangateProduct not found for priceOption: %s', $priceOption->getId()));

            throw new UnexpectedTypeException($avangateProduct, AvangateProduct::class);
        }

        return $avangateProduct;
    }

    private function getReturnUrl(Request $request, CartInterface $cart): string
    {
        $query = $this->buildCartQuery($request, $cart, ['result' => 'success']);

        return sprintf('%s?%s', $this->payUrl, $query);
    }

    private function buildCartQuery(Request $request, CartInterface $cart, array $additionalParams = []): string
    {
        return http_build_query(
            array_merge(
                [
                    'tid'       => $cart->getTransactionId(),
                    'setLocale' => $request->getLocale(),
                    'fr'        => $request->get('fr') ?: '',
                ],
                $additionalParams
            )
        );
    }

    private function getCancelUrl(Request $request, CartInterface $cart): string
    {
        $query = $this->buildCartQuery($request, $cart, ['result' => 'error3DS']);

        return sprintf('%s?%s', $this->payUrl, $query);
    }

    /**
     * @throws JsonException
     */
    private function placeOrder(stdClass $order, CartInterface $cart): JsonResponse
    {
        $options                          = [];
        $options[RequestOptions::BODY]    = json_encode($order, JSON_THROW_ON_ERROR);
        $options[RequestOptions::HEADERS] = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        try {
            $response = $this->client->request(Request::METHOD_POST, 'orders/', $options);
            $data     = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $this->processOrderData($cart, $data, $order);
        } catch (GuzzleException $exception) {
            /** @phpstan-ignore-next-line  */
            $response  = $exception->getResponse();
            $errorData = json_decode((string) $response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $this->logger->info('Error placeOrder by 2Pay.js.', ['order' => $order, 'response' => $errorData]);

            return $this->buildErrorResponse($errorData);
        }

        return $this->buildSuccessResponse($data);
    }

    private function processOrderData(CartInterface $cart, array $data, stdClass $order): void
    {
        $msg = 'Success placeOrder by 2Pay.js.';
        if (self::ORDER_STATUS_AUTHRECEIVED === $data['Status'] && self::ORDER_APPROVE_STATUS_WAITING === $data['ApproveStatus']) {
            $this->logger->warning($msg, ['order' => $order, 'status' => self::ORDER_APPROVE_STATUS_WAITING]);
        } else {
            $this->logger->info($msg, ['order' => $order, 'status' => $data['Status']]);
        }

        $this->saveCart($cart, $data);
    }

    private function saveCart(CartInterface $cart, array $content): void
    {
        $cart->setContextVariable(self::REF_NO_PARAM, $content[self::REF_NO_PARAM]);
        $this->chainCartStorage->save($cart->getStoredCart());
    }

    private function buildErrorResponse(array $errorData): JsonResponse
    {
        $response = [
            'type'    => $errorData['error_code'] ?? 'UNKNOWN_ERROR',
            'message' => $errorData['message'] ?? '',
        ];

        return new JsonResponse($response, Response::HTTP_BAD_REQUEST);
    }

    private function buildSuccessResponse(array $data): JsonResponse
    {
        $response = [
            self::REF_NO_PARAM  => $data[self::REF_NO_PARAM],
            'Status'            => [
                'Name' => $data['Status'],
            ],
        ];

        if (self::ORDER_STATUS_AUTHRECEIVED === $data['Status']) {
            $response['Status']['ApproveStatus'] = $data['ApproveStatus'];
        }

        if (self::ORDER_STATUS_PENDING === $data['Status']) {
            $response['Status']['Authorize3DS'] = $data['PaymentDetails']['PaymentMethod']['Authorize3DS'];
        }

        return new JsonResponse($response, Response::HTTP_OK);
    }
}
