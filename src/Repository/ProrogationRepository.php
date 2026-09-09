<?php

namespace App\Repository;

use App\Entity\Prorogation;
use App\Entity\StatutProrogation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prorogation>
 */
class ProrogationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prorogation::class);
    }

    public function findPending(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.statutProrogation', 'sp')->addSelect('sp')
            ->leftJoin('p.instruction', 'i')->addSelect('i')
            ->leftJoin('p.action', 'a')->addSelect('a')
            ->leftJoin('p.demandePar', 'u')->addSelect('u')
            ->where('sp.code = :code')
            ->setParameter('code', StatutProrogation::CODE_EN_ATTENTE)
            ->orderBy('p.dateDemande', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
