<?php

namespace App\Repository;

use App\Entity\ConseillerGouverneur;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConseillerGouverneur>
 */
class ConseillerGouverneurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConseillerGouverneur::class);
    }

    /**
     * @return ConseillerGouverneur[]
     */
    public function findActifs(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.utilisateur', 'u')->addSelect('u')
            ->andWhere('c.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('c.ordreAffichage', 'ASC')
            ->addOrderBy('c.domaineCompetence', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Conseillers proposés comme responsables : les actifs, plus ceux déjà désignés
     * (même désactivés depuis, pour ne pas les perdre en modifiant l'instruction).
     *
     * @param ConseillerGouverneur[] $dejaDesignes
     */
    public function createChoicesQueryBuilder(array $dejaDesignes = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.utilisateur', 'u')->addSelect('u')
            ->where('c.actif = true')
            ->orderBy('c.ordreAffichage', 'ASC')
            ->addOrderBy('c.domaineCompetence', 'ASC');

        $ids = array_filter(array_map(fn (ConseillerGouverneur $c) => $c->getId(), $dejaDesignes));
        if ($ids) {
            $qb->orWhere('c.id IN (:dejaDesignes)')->setParameter('dejaDesignes', $ids);
        }

        return $qb;
    }

    public function findByUtilisateur(Utilisateur $utilisateur): ?ConseillerGouverneur
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.utilisateur', 'u')->addSelect('u')
            ->andWhere('c.utilisateur = :user')
            ->setParameter('user', $utilisateur)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Stats globales par conseiller
     */
    public function getStatsConseillers(): array
    {
        $conseillers = $this->findActifs();
        $stats = [];

        foreach ($conseillers as $conseiller) {
            $suivis = $conseiller->getSuivis();
            $total = count($suivis);
            $enAttente = 0;
            $enCours = 0;
            $approuves = 0;
            $avecReserves = 0;
            $rejetes = 0;

            foreach ($suivis as $suivi) {
                match ($suivi->getStatutSuivi()) {
                    'EN_ATTENTE' => $enAttente++,
                    'EN_COURS' => $enCours++,
                    'APPROUVE' => $approuves++,
                    'AVEC_RESERVES' => $avecReserves++,
                    'REJETE' => $rejetes++,
                    default => null,
                };
            }

            $stats[] = [
                'conseiller' => $conseiller,
                'total' => $total,
                'en_attente' => $enAttente,
                'en_cours' => $enCours,
                'approuves' => $approuves,
                'avec_reserves' => $avecReserves,
                'rejetes' => $rejetes,
            ];
        }

        return $stats;
    }
}
