<?php

namespace App\Repository;

use App\Entity\Entite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
            ORDER BY total_instructions DESC, total_actions DESC, e.nom ASC
        ";

        return $conn->executeQuery($sql)->fetchAllAssociative();
    }
}
