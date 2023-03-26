<?php

namespace AppBundle\Repository;

use AppBundle\Entity\AvangateProduct;
use AppBundle\Payment\Avangate\Processor\AvangatePaymentProcessor;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use MovaviShopBundle\Entity\PriceOption;

/**
 * @template-extends EntityRepository<AvangateProduct>
 */
class AvangateProductRepository extends EntityRepository
{
    /**
     * @throws NonUniqueResultException
     */
    public function findByPriceOption(PriceOption $priceOption): ?AvangateProduct
    {
        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata(AvangateProduct::class, 'ap');

        $qb = $this->_em->createNativeQuery(
            <<<SQL
                SELECT ap.* FROM avangate_product ap
                    LEFT JOIN external_id_reference eir ON ap.id::text = eir.external_id
                    LEFT JOIN payment_processor pp ON eir.payment_processor_id = pp.id
                    WHERE pp.name = :processorName
                        AND eir.price_option_id = :priceOptionId
            SQL,
            $rsm,
        )
            ->setParameters([
                'processorName' => AvangatePaymentProcessor::NAME,
                'priceOptionId' => $priceOption->getId(),
            ]);

        return $qb->getOneOrNullResult();
    }
}
