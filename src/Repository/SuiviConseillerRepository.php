<?php

namespace App\Repository;

use App\Entity\Action;
use App\Entity\ConseillerGouverneur;
use App\Entity\SuiviConseiller;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SuiviConseiller>
 */
class SuiviConseillerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuiviConseiller::class);
    }

    /**
     * Recherche et filtre pour la vue suivi conseillers
     */
    public function searchAndFilter(array $filters = [], ?ConseillerGouverneur $conseillerFiltre = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.action', 'a')->addSelect('a')
            ->leftJoin('a.instruction', 'i')->addSelect('i')
            ->leftJoin('a.statut', 'astatut')->addSelect('astatut')
            ->leftJoin('a.entiteResponsable', 'e')->addSelect('e')
            ->join('s.conseiller', 'c')->addSelect('c')
            ->leftJoin('c.utilisateur', 'cu')->addSelect('cu')
            ->leftJoin('s.affectePar', 'ap')->addSelect('ap')
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('s.createdAt', 'DESC');

        if ($conseillerFiltre) {
            $qb->andWhere('s.conseiller = :conseiller')
                ->setParameter('conseiller', $conseillerFiltre);
        } elseif (!empty($filters['conseiller_id'])) {
            $qb->andWhere('s.conseiller = :conseillerId')
                ->setParameter('conseillerId', $filters['conseiller_id']);
        }

        if (!empty($filters['statut_suivi'])) {
            $qb->andWhere('s.statutSuivi = :statutSuivi')
                ->setParameter('statutSuivi', $filters['statut_suivi']);
        }

        if (!empty($filters['priorite_cabinet'])) {
            $qb->andWhere('s.prioriteCabinet = :prio')
                ->setParameter('prio', $filters['priorite_cabinet']);
        }

        if (!empty($filters['domaine'])) {
            $qb->andWhere('c.domaineCompetence LIKE :domaine OR c.codeDomaine LIKE :domaine')
                ->setParameter('domaine', '%' . trim($filters['domaine']) . '%');
        }

        if (!empty($filters['q'])) {
            $qb->andWhere('a.libelle LIKE :q OR a.reference LIKE :q OR i.reference LIKE :q OR s.avis LIKE :q OR s.recommandations LIKE :q')
                ->setParameter('q', '%' . trim($filters['q']) . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Obtenir les suivis pour une action spécifique
     */
    public function findByAction(Action $action): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.conseiller', 'c')->addSelect('c')
            ->leftJoin('c.utilisateur', 'u')->addSelect('u')
            ->andWhere('s.action = :action')
            ->setParameter('action', $action)
            ->orderBy('c.ordreAffichage', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifier si un conseiller est déjà affecté à une action
     */
    public function findOneByActionAndConseiller(Action $action, ConseillerGouverneur $conseiller): ?SuiviConseiller
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.action = :action')
            ->andWhere('s.conseiller = :conseiller')
            ->setParameter('action', $action)
            ->setParameter('conseiller', $conseiller)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Statistiques globales du cabinet
     */
    public function getGlobalCabinetStats(): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.statutSuivi, COUNT(s.id) as total')
            ->join('s.action', 'a')
            ->andWhere('a.deletedAt IS NULL')
            ->groupBy('s.statutSuivi');

        $results = $qb->getQuery()->getArrayResult();
        $stats = [
            'total' => 0,
            'EN_ATTENTE' => 0,
            'EN_COURS' => 0,
            'APPROUVE' => 0,
            'AVEC_RESERVES' => 0,
            'REJETE' => 0,
        ];

        foreach ($results as $row) {
            $statut = $row['statutSuivi'];
            $count = (int)$row['total'];
            $stats[$statut] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }
}
