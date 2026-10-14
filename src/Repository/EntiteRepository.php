<?php

namespace App\Repository;

use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entite>
 */
class EntiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entite::class);
    }

    /**
     * Entités proposées dans les listes de choix : uniquement les actives,
     * triées par Direction Générale (Gouvernorat en premier), la DG puis ses directions.
     * $valeurActuelle reste proposée même si elle a été désactivée, pour qu'un dossier
     * existant puisse être modifié sans perdre (ni invalider) son rattachement.
     */
    public function createChoicesQueryBuilder(?Entite $valeurActuelle = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.parent', 'dg')->addSelect('dg')
            ->addSelect("CASE WHEN COALESCE(dg.code, e.code) = 'GOUV' THEN 0 ELSE 1 END AS HIDDEN gouvernoratEnPremier")
            ->addSelect('COALESCE(dg.code, e.code) AS HIDDEN codeDg')
            ->addSelect('CASE WHEN e.parent IS NULL THEN 0 ELSE 1 END AS HIDDEN dgAvantDirections')
            ->where('e.actif = true')
            ->orderBy('gouvernoratEnPremier', 'ASC')
            ->addOrderBy('codeDg', 'ASC')
            ->addOrderBy('dgAvantDirections', 'ASC')
            ->addOrderBy('e.code', 'ASC');

        if ($valeurActuelle?->getId()) {
            $qb->orWhere('e.id = :valeurActuelle')->setParameter('valeurActuelle', $valeurActuelle->getId());
        }

        return $qb;
    }

    /**
     * Entités actives, dans l'ordre des listes groupées par Direction Générale.
     *
     * @return Entite[]
     */
    public function findActivesGroupees(): array
    {
        return $this->createChoicesQueryBuilder()->getQuery()->getResult();
    }

    public function getPerformanceDirections(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        
        $sql = "
            SELECT 
                e.id,
                e.code,
                e.nom,
                te.libelle AS type_entite,
                COUNT(DISTINCT i.id) AS total_instructions,
                COUNT(DISTINCT CASE WHEN s_i.code IN ('EXECUTEE', 'CLOTUREE') THEN i.id END) AS instructions_executees,
                COUNT(DISTINCT CASE WHEN s_i.code = 'EN_COURS' THEN i.id END) AS instructions_en_cours,
                COUNT(DISTINCT CASE WHEN i.date_echeance < CURDATE() AND s_i.code NOT IN ('EXECUTEE', 'CLOTUREE', 'ANNULEE') THEN i.id END) AS instructions_en_retard,
                COUNT(DISTINCT a.id) AS total_actions,
                COUNT(DISTINCT CASE WHEN s_a.code IN ('EXECUTEE', 'CLOTUREE') THEN a.id END) AS actions_executees,
                COUNT(DISTINCT CASE WHEN s_a.code = 'EN_COURS' THEN a.id END) AS actions_en_cours,
                COUNT(DISTINCT CASE WHEN a.date_echeance < CURDATE() AND s_a.code NOT IN ('EXECUTEE', 'CLOTUREE', 'ANNULEE') THEN a.id END) AS actions_en_retard,
                ROUND(COALESCE(AVG(a.taux_avancement), 0), 1) AS taux_moyen_actions
            FROM entites e
            JOIN types_entite te ON e.type_entite_id = te.id
            LEFT JOIN instructions i ON i.entite_pilote_id = e.id
            LEFT JOIN statuts s_i ON i.statut_id = s_i.id
            LEFT JOIN actions a ON a.entite_responsable_id = e.id
            LEFT JOIN statuts s_a ON a.statut_id = s_a.id
            WHERE e.actif = 1
            GROUP BY e.id, e.code, e.nom, te.libelle, te.ordre_affichage, e.code
            HAVING total_actions > 0
            ORDER BY total_actions DESC, taux_moyen_actions DESC, e.nom ASC
        ";

        return $conn->executeQuery($sql)->fetchAllAssociative();
    }
}
