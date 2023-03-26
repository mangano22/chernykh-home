<?php

namespace MovaviShopBundle\Form\Mapper\Checkout;

use AppBundle\Payment\Avangate\Processor\TwoPayJsByAvangatePaymentProcessor;
use MovaviShopBundle\Controller\PaymentController;
use MovaviShopBundle\Payment\Model\Cart;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\FormInterface;
use Traversable;

class TwoPayJsFormToCartMapper implements DataMapperInterface
{
    /**
     * @param Cart $viewData
     */
    public function mapDataToForms(mixed $viewData, Traversable $forms): void
    {
        /** @var FormInterface[] $formsArray */
        $formsArray = iterator_to_array($forms);

        $formsArray['emailSubscription']->setData($viewData->isEmailSubscription());
        $formsArray['collectionMethod']->setData(
            $viewData->getContextVariable(PaymentController::COLLECTION_METHOD_PARAM)
        );
        $formsArray[TwoPayJsByAvangatePaymentProcessor::TWO_PAY_JS_TOKEN_PARAM]->setData(
            $viewData->getContextVariable(TwoPayJsByAvangatePaymentProcessor::TWO_PAY_JS_TOKEN_PARAM)
        );

        $billingDetails = $formsArray['billingDetails'];
        $billingDetails->get('email')->setData($viewData->getEmail());
        $billingDetails->get('zip')->setData($viewData->getZIP());
        $billingDetails->get('state')->setData($viewData->getState());
        $billingDetails->get('city')->setData($viewData->getCity());
        $billingDetails->get('address')->setData($viewData->getAddress());
        $billingDetails->get('companyName')->setData($viewData->getCompany());
        $billingDetails->get('firstName')->setData($viewData->getName());
        $billingDetails->get('lastName')->setData($viewData->getLastName());
        $billingDetails->get('phone')->setData($viewData->getPhone());
        $billingDetails->get(TwoPayJsByAvangatePaymentProcessor::COUNTRY_CODE_PARAM)->setData(
            $viewData->getContextVariable(TwoPayJsByAvangatePaymentProcessor::COUNTRY_CODE_PARAM)
        );
        $billingDetails->get(TwoPayJsByAvangatePaymentProcessor::FISCAL_CODE_PARAM)->setData(
            $viewData->getContextVariable(TwoPayJsByAvangatePaymentProcessor::FISCAL_CODE_PARAM)
        );
    }

    /**
     * @param Cart $viewData
     */
    public function mapFormsToData(Traversable $forms, mixed &$viewData): void
    {
        /** @var FormInterface[] $formsArray */
        $formsArray = iterator_to_array($forms);

        $viewData->setEmailSubscription((bool) $formsArray['emailSubscription']->getData());
        $collectionMethod = $formsArray['collectionMethod']->getData() ?: PaymentController::COLLECTION_METHOD_CHARGE_AUTOMATICALLY;
        $viewData->setContextVariable(PaymentController::COLLECTION_METHOD_PARAM, $collectionMethod);
        $viewData->setContextVariable(
            TwoPayJsByAvangatePaymentProcessor::TWO_PAY_JS_TOKEN_PARAM,
            $formsArray[TwoPayJsByAvangatePaymentProcessor::TWO_PAY_JS_TOKEN_PARAM]->getData()
        );

        $billingDetails = $formsArray['billingDetails'];
        $viewData->setEmail($billingDetails->get('email')->getData());
        $viewData->setZIP($billingDetails->get('zip')->getData());
        $viewData->setState($billingDetails->get('state')->getData());
        $viewData->setCity($billingDetails->get('city')->getData());
        $viewData->setAddress($billingDetails->get('address')->getData());
        $viewData->setCompany($billingDetails->get('companyName')->getData());
        $viewData->setName($billingDetails->get('firstName')->getData());
        $viewData->setLastName($billingDetails->get('lastName')->getData());
        $viewData->setPhone($billingDetails->get('phone')->getData());
        $viewData->setContextVariable(
            TwoPayJsByAvangatePaymentProcessor::COUNTRY_CODE_PARAM,
            $billingDetails->get(TwoPayJsByAvangatePaymentProcessor::COUNTRY_CODE_PARAM)->getData()
        );
        $viewData->setContextVariable(
            TwoPayJsByAvangatePaymentProcessor::FISCAL_CODE_PARAM,
            $billingDetails->get(TwoPayJsByAvangatePaymentProcessor::FISCAL_CODE_PARAM)->getData()
        );
    }
}
