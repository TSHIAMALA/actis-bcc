<?php

namespace App\Command;

use App\Entity\Entite;
use App\Entity\TypeEntite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-bcc-entites',
    description: 'Synchronise l\'organigramme officiel de la Banque Centrale du Congo (7 DG et 22 Directions)'
)]
class SyncBccEntitesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synchronisation de l\'Organigramme Officiel de la Banque Centrale du Congo (BCC)');

        $conn = $this->em->getConnection();

        // 1. Récupérer les identifiants cibles
        $dobmId = (int) $conn->fetchOne("SELECT id FROM entites WHERE code = 'DOBM'");
        $dsiId = (int) $conn->fetchOne("SELECT id FROM entites WHERE code = 'DSI'");
        $drhId = (int) $conn->fetchOne("SELECT id FROM entites WHERE code = 'DRH'");
        $gouvId = (int) $conn->fetchOne("SELECT id FROM entites WHERE code = 'GOUV'");

        // 2. Mettre à jour les utilisateurs vers les directions officielles
        $conn->executeStatement("UPDATE utilisateurs SET entite_id = :dsi WHERE entite_id IN (SELECT id FROM entites WHERE code IN ('SAPPLI', 'SINFRA', 'C_SECURITE', 'DG_SI', 'B_CORE'))", ['dsi' => $dsiId]);
        $conn->executeStatement("UPDATE utilisateurs SET entite_id = :dobm WHERE entite_id IN (SELECT id FROM entites WHERE code IN ('DOPM', 'SM', 'SOB', 'B_MARCHES', 'DG_OP'))", ['dobm' => $dobmId]);
        $conn->executeStatement("UPDATE utilisateurs SET entite_id = :drh WHERE entite_id IN (SELECT id FROM entites WHERE code IN ('DG_ADMIN'))", ['drh' => $drhId]);
        $conn->executeStatement("UPDATE utilisateurs SET entite_id = :gouv WHERE entite_id IN (SELECT id FROM entites WHERE code IN ('SEC_DG_OP'))", ['gouv' => $gouvId]);

        // 3. Mettre à jour les instructions et actions
        $conn->executeStatement("UPDATE instructions SET entite_pilote_id = :dobm WHERE entite_pilote_id IN (SELECT id FROM entites WHERE code IN ('DOPM', 'SM', 'SOB', 'B_MARCHES', 'DG_OP'))", ['dobm' => $dobmId]);
        $conn->executeStatement("UPDATE instructions SET entite_pilote_id = :dsi WHERE entite_pilote_id IN (SELECT id FROM entites WHERE code IN ('SAPPLI', 'SINFRA', 'C_SECURITE', 'DG_SI', 'B_CORE'))", ['dsi' => $dsiId]);
        $conn->executeStatement("UPDATE instructions SET entite_pilote_id = :drh WHERE entite_pilote_id IN (SELECT id FROM entites WHERE code IN ('DG_ADMIN'))", ['drh' => $drhId]);

        $conn->executeStatement("UPDATE actions SET entite_responsable_id = :dobm WHERE entite_responsable_id IN (SELECT id FROM entites WHERE code IN ('DOPM', 'SM', 'SOB', 'B_MARCHES', 'DG_OP'))", ['dobm' => $dobmId]);
        $conn->executeStatement("UPDATE actions SET entite_responsable_id = :dsi WHERE entite_responsable_id IN (SELECT id FROM entites WHERE code IN ('SAPPLI', 'SINFRA', 'C_SECURITE', 'DG_SI', 'B_CORE'))", ['dsi' => $dsiId]);
        $conn->executeStatement("UPDATE actions SET entite_responsable_id = :drh WHERE entite_responsable_id IN (SELECT id FROM entites WHERE code IN ('DG_ADMIN'))", ['drh' => $drhId]);

        $conn->executeStatement("UPDATE instruction_entites SET entite_id = :dobm WHERE entite_id IN (SELECT id FROM entites WHERE code IN ('DOPM', 'SM', 'SOB', 'B_MARCHES', 'DG_OP'))", ['dobm' => $dobmId]);
        $conn->executeStatement("UPDATE instruction_entites SET entite_id = :dsi WHERE entite_id IN (SELECT id FROM entites WHERE code IN ('SAPPLI', 'SINFRA', 'C_SECURITE', 'DG_SI', 'B_CORE'))", ['dsi' => $dsiId]);

        // 4. Supprimer les anciennes entités fictives
        $obsoleteCodes = ['DG_OP', 'DG_SI', 'DG_ADMIN', 'DOPM', 'SM', 'SOB', 'SINFRA', 'SAPPLI', 'B_MARCHES', 'B_CORE', 'C_SECURITE', 'SEC_DG_OP'];
        
        // Retirer les parents d'abord
        $conn->executeStatement("UPDATE entites SET parent_id = NULL WHERE code IN ('" . implode("','", $obsoleteCodes) . "')");
        
        foreach ($obsoleteCodes as $code) {
            try {
                $conn->executeStatement("DELETE FROM entites WHERE code = :code", ['code' => $code]);
                $io->writeln(sprintf(' <fg=red>[SUPPRIMÉE]</> Entité de test retirée : %s', $code));
            } catch (\Exception $e) {
                $conn->executeStatement("UPDATE entites SET actif = 0 WHERE code = :code", ['code' => $code]);
                $io->writeln(sprintf(' <fg=yellow>[DÉSACTIVÉE]</> Entité obsolète désactivée : %s', $code));
            }
        }

        $totalDG = (int) $conn->fetchOne("SELECT COUNT(*) FROM entites WHERE parent_id IS NULL AND actif = 1");
        $totalDir = (int) $conn->fetchOne("SELECT COUNT(*) FROM entites WHERE parent_id IS NOT NULL AND actif = 1");

        $io->success(sprintf('Organigramme officiel synchronisé avec succès ! (%d Directions Générales / Gouvernance et %d Directions opérationnelles)', $totalDG, $totalDir));

        return Command::SUCCESS;
    }
}
