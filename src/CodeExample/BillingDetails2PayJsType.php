<?php

namespace MovaviShopBundle\Form\Type\Checkout;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

class BillingDetails2PayJsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('country', HiddenType::class)
            ->add('email', HiddenType::class)
            ->add('zip', HiddenType::class)
            ->add('state', HiddenType::class)
            ->add('city', HiddenType::class)
            ->add('address', HiddenType::class)
            ->add('companyName', HiddenType::class)
            ->add('firstName', HiddenType::class)
            ->add('lastName', HiddenType::class)
            ->add('phone', HiddenType::class);
    }
}
