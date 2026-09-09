<?php

namespace App\Repository;

use App\Entity\Instruction;
use App\Entity\Statut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Instruction>
 */
class InstructionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instruction::class);
    }

    public function searchAndFilter(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.typeInstruction', 't')->addSelect('t')
            ->leftJoin('i.statut', 's')->addSelect('s')
            ->leftJoin('i.priorite', 'p')->addSelect('p')
            ->leftJoin('i.entitePilote', 'e')->addSelect('e')
            ->leftJoin('i.responsable', 'r')->addSelect('r')
            ->leftJoin('i.actions', 'a')->addSelect('a')
            ->orderBy('i.createdAt', 'DESC');

        if (!empty($filters['q'])) {
            $qb->andWhere('i.reference LIKE :q OR i.objet LIKE :q OR i.description LIKE :q OR i.emetteur LIKE :q')
                ->setParameter('q', '%' . trim($filters['q']) . '%');
        }

        if (!empty($filters['statut'])) {
            $qb->andWhere('s.id = :statutId')
                ->setParameter('statutId', $filters['statut']);
        }

        if (!empty($filters['priorite'])) {
            $qb->andWhere('p.id = :prioriteId')
                ->setParameter('prioriteId', $filters['priorite']);
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('t.id = :typeId')
                ->setParameter('typeId', $filters['type']);
        }

        if (!empty($filters['entite'])) {
            $qb->andWhere('e.id = :entiteId')
                ->setParameter('entiteId', $filters['entite']);
        }

        if (!empty($filters['responsable'])) {
            $qb->andWhere('r.id = :respId')
                ->setParameter('respId', $filters['responsable']);
        }

        if (!empty($filters['retard']) && $filters['retard'] === '1') {
            $qb->andWhere('i.dateEcheance < :today')
                ->andWhere('s.code NOT IN (:closedStatuses)')
                ->setParameter('today', new \DateTime('today'))
                ->setParameter('closedStatuses', [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE]);
        }

        if (!empty($filters['date_debut'])) {
            $qb->andWhere('i.dateInstruction >= :dateDebut')
                ->setParameter('dateDebut', new \DateTime($filters['date_debut']));
        }

        if (!empty($filters['date_fin'])) {
            $qb->andWhere('i.dateInstruction <= :dateFin')
                ->setParameter('dateFin', new \DateTime($filters['date_fin']));
        }

        return $qb->getQuery()->getResult();
    }

    public function getCountsByStatut(): array
    {
        $res = $this->createQueryBuilder('i')
            ->select('s.code, s.libelle, s.couleur, COUNT(i.id) as total')
            ->join('i.statut', 's')
            ->groupBy('s.id, s.code, s.libelle, s.couleur')
            ->getQuery()
            ->getResult();

        return $res;
    }

    public function getCountsByPriorite(): array
    {
        $res = $this->createQueryBuilder('i')
            ->select('p.code, p.libelle, COUNT(i.id) as total')
            ->join('i.priorite', 'p')
            ->groupBy('p.id, p.code, p.libelle')
            ->orderBy('p.niveau', 'DESC')
            ->getQuery()
            ->getResult();

        return $res;
    }

    public function getCountsByType(): array
    {
        $res = $this->createQueryBuilder('i')
            ->select('t.code, t.libelle, COUNT(i.id) as total')
            ->join('i.typeInstruction', 't')
            ->groupBy('t.id, t.code, t.libelle')
            ->getQuery()
            ->getResult();

        return $res;
    }

    public function countEnRetard(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->join('i.statut', 's')
            ->where('i.dateEcheance < :today')
            ->andWhere('s.code NOT IN (:closedStatuses)')
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('closedStatuses', [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getRecent(int $limit = 5): array
    {
        return $this->createQueryBuilder('i')
            ->leftJoin('i.typeInstruction', 't')->addSelect('t')
            ->leftJoin('i.statut', 's')->addSelect('s')
            ->leftJoin('i.priorite', 'p')->addSelect('p')
            ->leftJoin('i.entitePilote', 'e')->addSelect('e')
            ->leftJoin('i.responsable', 'r')->addSelect('r')
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
