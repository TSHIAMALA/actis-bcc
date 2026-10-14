<?php

namespace App\Command;

use App\Entity\Entite;
use App\Entity\TypeEntite;
use App\Service\XlsxReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synchronise les entités de la BCC à partir du fichier Excel officiel
 * (colonnes : « Entité », « acronym Entité », « acronym DG »).
 *
 * Synchronisation par code, jamais de suppression :
 *  - entité existante (même code) : nom, rattachement et type mis à jour, son id ne change pas,
 *    donc utilisateurs, instructions, actions et historique restent rattachés ;
 *  - entité nouvelle : créée ;
 *  - entité de la base absente du fichier : désactivée.
 *
 * --dry-run exécute tout dans une transaction annulée à la fin : le rapport est exact, la base intacte.
 */
#[AsCommand(
    name: 'app:import-entites',
    description: 'Synchronise les entités de la BCC depuis le fichier Excel officiel'
)]
class ImportEntitesCommand extends Command
{
    /** Directions générales : acronyme du fichier => [code ACTIS, libellé] */
    private const DIRECTIONS_GENERALES = [
        'GOUVERNORAT' => ['GOUV', 'Gouvernorat', 'Structures rattachées directement au Gouverneur'],
        'DGAS' => ['DGAS', 'Direction Générale de l’Administration et Support', null],
        'DGC' => ['DGC', 'Direction Générale de Contrôle', null],
        'DGCEP' => ['DGCEP', 'Direction Générale de la Coordination des Entités Provinciales', null],
        'DGHDM' => ['DGHDM', 'Direction Générale de l’Hôtel des Monnaies', null],
        'DGPM' => ['DGPM', 'Direction Générale de la Politique Monétaire', null],
        'DGSBF' => ['DGSBF', 'Direction Générale de la Supervision Bancaire et Financière', null],
    ];

    /** Codes du fichier réutilisés par deux entités : remplacés par le code déjà en usage dans ACTIS */
    private const CODE_ALIASES = [
        'DC|DGC' => 'DCONF',  // Direction de la Conformité
        'DC|GOUV' => 'DCOM',  // Direction de la Communication
    ];

    /** Anciens codes ACTIS renommés : ancien => nouveau */
    private const CODE_RENAMES = [
        'DGHM' => 'DGHDM',
        'DEONTOLOGUE' => 'DEONT',
    ];

    /** Types particuliers ; toutes les autres entités sont de type DIRECTION */
    private const TYPES_PAR_CODE = [
        'SGQ' => 'SERVICE',
        'SPCPMP' => 'SECRETARIAT',
    ];

    /** Nouveaux types nécessaires au réseau provincial : code => [libellé, niveau, ordre] */
    private const TYPES_A_CREER = [
        'DIRECTION_PROVINCIALE' => ['Direction provinciale', 2, 7],
        'AGENCE' => ['Agence', 3, 8],
    ];

    /** Orthographe des villes quand la mise en forme automatique ne suffit pas */
    private const VILLES = [
        'MBUJIMAYI' => 'Mbuji-Mayi',
        'KIKIWIT' => 'Kikwit',
    ];

    private array $types = [];
    private bool $reactiver = false;
    private array $report = ['created' => [], 'updated' => [], 'renamed' => [], 'merged' => [], 'deactivated' => [], 'kept_inactive' => [], 'types' => []];

    public function __construct(
        private EntityManagerInterface $em,
        private XlsxReader $xlsxReader
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Chemin du fichier Excel (.xlsx)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : affiche les changements sans modifier la base')
            ->addOption('reactiver', null, InputOption::VALUE_NONE, 'Réactive les entités du fichier actuellement désactivées (par défaut elles restent inactives)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $this->reactiver = (bool) $input->getOption('reactiver');
        $io->title(($dryRun ? '[SIMULATION] ' : '') . 'Synchronisation des entités de la BCC');

        try {
            $lignes = $this->xlsxReader->read($input->getArgument('fichier'), [
                'Entité' => 'nom',
                'acronym Entité' => 'code',
                'acronym DG' => 'dg',
            ]);
            $cibles = $this->preparerEntites($lignes);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $this->chargerTypes();
            $this->renommerAnciensCodes();
            $existantes = $this->indexerEntitesExistantes();

            // 1. Directions générales (sans parent)
            $parents = [];
            foreach (self::DIRECTIONS_GENERALES as [$code, $nom, $description]) {
                $parents[$code] = $this->synchroniser($existantes, $code, $nom, 'DIRECTION_GENERALE', null, $description);
            }
            $this->em->flush();

            // 2. Directions, services, directions provinciales et agences
            foreach ($cibles as $code => $cible) {
                $this->synchroniser($existantes, $code, $cible['nom'], $cible['type'], $parents[$cible['dg']], null);
            }
            $this->em->flush();

            // 3. Entités absentes du fichier : désactivées, jamais supprimées
            $codesFichier = array_merge(array_column(self::DIRECTIONS_GENERALES, 0), array_keys($cibles));
            foreach ($existantes as $code => $entite) {
                if (!in_array($code, $codesFichier, true) && $entite->isActif()) {
                    $entite->setActif(false);
                    $this->report['deactivated'][] = [$code, $entite->getNom(), $this->compterUsages($entite)];
                }
            }
            $this->em->flush();

            $dryRun ? $conn->rollBack() : $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $io->error('Import annulé, aucune modification enregistrée : ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->afficherRapport($io, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Normalise les lignes du fichier : codes en majuscules, noms lisibles, doublons fusionnés.
     *
     * @return array<string, array{nom: string, type: string, dg: string}>
     */
    private function preparerEntites(array $lignes): array
    {
        $cibles = [];
        foreach ($lignes as $numero => $ligne) {
            $dgFichier = mb_strtoupper($ligne['dg']);
            if (!isset(self::DIRECTIONS_GENERALES[$dgFichier])) {
                throw new \RuntimeException("Ligne $numero : direction générale inconnue « {$ligne['dg']} ».");
            }
            $dg = self::DIRECTIONS_GENERALES[$dgFichier][0];

            $code = mb_strtoupper($ligne['code']);
            if ($code === '') {
                throw new \RuntimeException("Ligne $numero : l'entité « {$ligne['nom']} » n'a pas d'acronyme.");
            }
            $code = self::CODE_ALIASES["$code|$dg"] ?? $code;

            [$nom, $type] = $this->normaliserNom($ligne['nom'], $code);

            if (isset($cibles[$code])) {
                if ($cibles[$code]['dg'] !== $dg) {
                    throw new \RuntimeException("Ligne $numero : le code $code est utilisé dans deux directions générales ({$cibles[$code]['dg']} et $dg).");
                }
                $this->report['merged'][] = [$code, $nom, $dg];
                continue;
            }

            $cibles[$code] = ['nom' => $nom, 'type' => $type, 'dg' => $dg];
        }

        return $cibles;
    }

    /**
     * @return array{0: string, 1: string} [nom lisible, code du type d'entité]
     */
    private function normaliserNom(string $nom, string $code): array
    {
        if (preg_match('/^(DP|AA)--(.+)$/u', $nom, $m)) {
            $ville = self::VILLES[mb_strtoupper($m[2])] ?? mb_convert_case(mb_strtolower($m[2]), MB_CASE_TITLE);
            $de = preg_match('/^[AEIOUYÉÈ]/u', mb_strtoupper($ville)) ? 'd’' : 'de ';

            return $m[1] === 'DP'
                ? ["Direction Provinciale {$de}{$ville}", 'DIRECTION_PROVINCIALE']
                : ["Agence {$de}{$ville}", 'AGENCE'];
        }

        return [rtrim($nom, ' .'), self::TYPES_PAR_CODE[$code] ?? 'DIRECTION'];
    }

    private function chargerTypes(): void
    {
        foreach ($this->em->getRepository(TypeEntite::class)->findAll() as $type) {
            $this->types[$type->getCode()] = $type;
        }

        foreach (self::TYPES_A_CREER as $code => [$libelle, $niveau, $ordre]) {
            if (!isset($this->types[$code])) {
                $type = (new TypeEntite())->setCode($code)->setLibelle($libelle)->setNiveau($niveau)->setOrdreAffichage($ordre)->setActif(true);
                $this->em->persist($type);
                $this->types[$code] = $type;
                $this->report['types'][] = $libelle;
            }
        }
        $this->em->flush();
    }

    private function renommerAnciensCodes(): void
    {
        $repo = $this->em->getRepository(Entite::class);
        foreach (self::CODE_RENAMES as $ancien => $nouveau) {
            $entite = $repo->findOneBy(['code' => $ancien]);
            if ($entite && !$repo->findOneBy(['code' => $nouveau])) {
                $entite->setCode($nouveau);
                $this->report['renamed'][] = [$ancien, $nouveau];
            }
        }
        $this->em->flush();
    }

    /** @return array<string, Entite> */
    private function indexerEntitesExistantes(): array
    {
        $index = [];
        foreach ($this->em->getRepository(Entite::class)->findAll() as $entite) {
            $index[$entite->getCode()] = $entite;
        }

        return $index;
    }

    private function synchroniser(array &$existantes, string $code, string $nom, string $typeCode, ?Entite $parent, ?string $description): Entite
    {
        $type = $this->types[$typeCode];
        $entite = $existantes[$code] ?? null;

        if (!$entite) {
            $entite = (new Entite())->setCode($code)->setNom($nom)->setTypeEntite($type)->setParent($parent)->setActif(true);
            if ($description) {
                $entite->setDescription($description);
            }
            $this->em->persist($entite);
            $existantes[$code] = $entite;
            $this->report['created'][] = [$code, $nom, $parent?->getCode() ?? '—', $type->getLibelle()];

            return $entite;
        }

        $changes = [];
        if ($entite->getNom() !== $nom) {
            $changes[] = sprintf('nom : « %s » → « %s »', $entite->getNom(), $nom);
            $entite->setNom($nom);
        }
        if ($entite->getParent()?->getCode() !== $parent?->getCode()) {
            $changes[] = sprintf('rattachement : %s → %s', $entite->getParent()?->getCode() ?? '—', $parent?->getCode() ?? '—');
            $entite->setParent($parent);
        }
        if ($entite->getTypeEntite()?->getCode() !== $typeCode) {
            $changes[] = sprintf('type : %s → %s', $entite->getTypeEntite()?->getLibelle() ?? '—', $type->getLibelle());
            $entite->setTypeEntite($type);
        }
        if ($description && $entite->getDescription() !== $description) {
            $entite->setDescription($description);
        }
        // Une entité désactivée à la main (ex. réseau provincial) reste inactive, sauf demande explicite
        if (!$entite->isActif()) {
            if ($this->reactiver) {
                $changes[] = 'réactivée';
                $entite->setActif(true);
            } else {
                $this->report['kept_inactive'][] = $code;
            }
        }

        if ($changes) {
            $entite->setUpdatedAt(new \DateTime());
            $this->report['updated'][] = [$code, implode("\n", $changes)];
        }

        return $entite;
    }

    private function compterUsages(Entite $entite): string
    {
        $conn = $this->em->getConnection();
        $id = $entite->getId();
        $usages = array_filter([
            'utilisateurs' => (int) $conn->fetchOne('SELECT COUNT(*) FROM utilisateurs WHERE entite_id = ?', [$id]),
            'instructions' => (int) $conn->fetchOne('SELECT COUNT(*) FROM instructions WHERE entite_pilote_id = ?', [$id]),
            'actions' => (int) $conn->fetchOne('SELECT COUNT(*) FROM actions WHERE entite_responsable_id = ?', [$id]),
        ]);

        return $usages ? implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($usages), $usages)) : 'aucune donnée liée';
    }

    private function afficherRapport(SymfonyStyle $io, bool $dryRun): void
    {
        if ($this->report['types']) {
            $io->section('Types d\'entité créés');
            $io->listing($this->report['types']);
        }
        if ($this->report['renamed']) {
            $io->section('Codes renommés (' . count($this->report['renamed']) . ')');
            $io->table(['Ancien code', 'Nouveau code'], $this->report['renamed']);
        }
        if ($this->report['merged']) {
            $io->section('Doublons du fichier fusionnés (' . count($this->report['merged']) . ')');
            $io->table(['Code', 'Ligne ignorée', 'DG'], $this->report['merged']);
        }
        $io->section('Entités mises à jour (' . count($this->report['updated']) . ')');
        $this->report['updated'] ? $io->table(['Code', 'Changements'], $this->report['updated']) : $io->text('Aucune.');

        $io->section('Entités créées (' . count($this->report['created']) . ')');
        $this->report['created'] ? $io->table(['Code', 'Nom', 'Rattachement', 'Type'], $this->report['created']) : $io->text('Aucune.');

        if ($this->report['kept_inactive']) {
            $io->section('Entités laissées inactives (' . count($this->report['kept_inactive']) . ')');
            $io->text(implode(', ', $this->report['kept_inactive']));
            $io->text('<comment>Utilisez --reactiver pour les réactiver.</comment>');
        }

        $io->section('Entités désactivées (' . count($this->report['deactivated']) . ')');
        $this->report['deactivated'] ? $io->table(['Code', 'Nom', 'Données liées'], $this->report['deactivated']) : $io->text('Aucune.');

        if ($dryRun) {
            $io->warning('SIMULATION : aucune modification n\'a été enregistrée. Relancez sans --dry-run pour appliquer.');
        } else {
            $io->success('Entités synchronisées avec succès.');
        }
    }
}
