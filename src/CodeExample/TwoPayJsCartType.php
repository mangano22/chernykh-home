<?php

namespace MovaviShopBundle\Form\Type\Checkout;

use MovaviShopBundle\Controller\PaymentController;
use MovaviShopBundle\Form\Mapper\Checkout\TwoPayJsFormToCartMapper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Constraint;

class TwoPayJsCartType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emailSubscription', HiddenType::class, [
                'constraints' => [
                    new Constraint\Choice(['choices' => ['1', '0']]),
                ],
            ])
            ->add('collectionMethod', HiddenType::class, [
                'constraints' => [
                    new Constraint\Choice(['choices' => [
                        PaymentController::COLLECTION_METHOD_SEND_INVOICE,
                        PaymentController::COLLECTION_METHOD_CHARGE_AUTOMATICALLY,
                    ]]),
                ],
            ])
            ->add('2payjsToken', HiddenType::class, [
                'required'    => true,
                'constraints' => [
                    new Constraint\NotBlank(),
                ],
            ])
            ->add('billingDetails', BillingDetails2PayJsType::class, [
                'required'    => true,
                'constraints' => [
                    new Constraint\NotBlank(),
                ],
            ])
            ->setDataMapper(new TwoPayJsFormToCartMapper());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['allow_extra_fields' => true, 'csrf_protection' => false]);
    }
}
