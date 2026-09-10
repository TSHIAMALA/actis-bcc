<?php

namespace App\Repository;

use App\Entity\Action;
use App\Entity\Statut;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Action>
 */
class ActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Action::class);
    }

    public function searchAndFilter(array $filters = [], ?Utilisateur $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.instruction', 'i')->addSelect('i')
            ->leftJoin('a.statut', 's')->addSelect('s')
            ->leftJoin('a.priorite', 'p')->addSelect('p')
            ->leftJoin('a.entiteResponsable', 'e')->addSelect('e')
            ->leftJoin('a.responsable', 'r')->addSelect('r')
            ->orderBy('a.dateEcheance', 'ASC');

        if (!empty($filters['show_deleted']) && $filters['show_deleted'] === '1') {
            $qb->andWhere('a.deletedAt IS NOT NULL');
        } else {
            $qb->andWhere('a.deletedAt IS NULL AND (i.deletedAt IS NULL OR i.id IS NULL)');
        }

        if (!empty($filters['q'])) {
            $qb->andWhere('a.libelle LIKE :q OR a.reference LIKE :q OR a.description LIKE :q OR i.reference LIKE :q')
                ->setParameter('q', '%' . trim($filters['q']) . '%');
        }

        if (!empty($filters['statut'])) {
            $qb->andWhere('s.id = :statutId')
                ->setParameter('statutId', $filters['statut']);
        }

        if (!empty($filters['entite'])) {
            $qb->andWhere('e.id = :entiteId')
                ->setParameter('entiteId', $filters['entite']);
        }

        if (!empty($filters['responsable'])) {
            $qb->andWhere('r.id = :respId')
                ->setParameter('respId', $filters['responsable']);
        }

        if (!empty($filters['instruction'])) {
            $qb->andWhere('i.id = :instructionId')
                ->setParameter('instructionId', $filters['instruction']);
        }

        if (!empty($filters['retard']) && $filters['retard'] === '1') {
            $qb->andWhere('a.dateEcheance < :today')
                ->andWhere('s.code NOT IN (:closedStatuses)')
                ->setParameter('today', new \DateTime('today'))
                ->setParameter('closedStatuses', [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE]);
        }

        if ($user !== null && !empty($filters['my_actions'])) {
            $qb->andWhere('r.id = :userId OR e.id = :userEntiteId')
                ->setParameter('userId', $user->getId())
                ->setParameter('userEntiteId', $user->getEntite()?->getId());
        }

        return $qb->getQuery()->getResult();
    }

    public function countEnRetard(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.statut', 's')
            ->where('a.deletedAt IS NULL')
            ->andWhere('a.dateEcheance < :today')
            ->andWhere('s.code NOT IN (:closedStatuses)')
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('closedStatuses', [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getUpcomingEcheances(int $days = 7): array
    {
        $today = new \DateTime('today');
        $maxDate = (clone $today)->modify("+$days days");

        return $this->createQueryBuilder('a')
            ->leftJoin('a.instruction', 'i')->addSelect('i')
            ->leftJoin('a.statut', 's')->addSelect('s')
            ->leftJoin('a.responsable', 'r')->addSelect('r')
            ->leftJoin('a.entiteResponsable', 'e')->addSelect('e')
            ->where('a.deletedAt IS NULL')
            ->andWhere('a.dateEcheance >= :today AND a.dateEcheance <= :maxDate')
            ->andWhere('s.code NOT IN (:closedStatuses)')
            ->setParameter('today', $today)
            ->setParameter('maxDate', $maxDate)
            ->setParameter('closedStatuses', [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE])
            ->orderBy('a.dateEcheance', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
