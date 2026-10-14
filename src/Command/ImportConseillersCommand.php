<?php

namespace App\Command;

use App\Entity\ConseillerGouverneur;
use App\Entity\Entite;
use App\Entity\Role;
use App\Entity\Utilisateur;
use App\Repository\ConseillerGouverneurRepository;
use App\Repository\UtilisateurRepository;
use App\Service\XlsxReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Importe le cabinet et les conseillers du Gouverneur depuis le fichier Excel
 * (colonnes : « N° », « Noms », « Fonction », « Attributions », « N° de téléphone »).
 *
 *  - Dircab / Dircaba : rôle Cabinet ; Conseillers : rôle Consultation ;
 *  - une fiche ConseillerGouverneur pour chaque personne ayant des attributions ;
 *  - email : prénom.nom@bcc.cd, ou nom.postnom@bcc.cd si le prénom n'est pas identifiable ;
 *  - les fiches conseillers des comptes de démonstration (@bcc.local) sont désactivées.
 *
 * Rejouable : les personnes sont retrouvées par email, rien n'est créé en double
 * et les mots de passe existants ne sont jamais modifiés.
 */
#[AsCommand(
    name: 'app:import-conseillers',
    description: 'Importe le cabinet et les conseillers du Gouverneur depuis le fichier Excel'
)]
class ImportConseillersCommand extends Command
{
    private const DOMAINE_EMAIL = 'bcc.cd';
    private const ENTITE = 'GOUV';

    /** Fonction du fichier => [rôle ACTIS, libellé de la fonction] */
    private const FONCTIONS = [
        'DIRCAB' => ['CABINET', 'Directeur de Cabinet du Gouverneur'],
        'DIRCABA' => ['CABINET', 'Directeur de Cabinet Adjoint du Gouverneur'],
        'CONSEILLER' => ['CONSULTATION', 'Conseiller du Gouverneur'],
    ];

    /** Codes de domaine (courts et uniques), par adresse email */
    private const CODES_DOMAINE = [
        'jody.ngongo' => 'ECO_MON',
        'gogo.tshipeta' => 'TRANSF_GOUV',
        'baharanyi.naciyimba' => 'CIRC_FID',
        'hermann.mulatu' => 'STRAT',
        'jose.nyimi' => 'SI_PROJ',
        'charles.bakaly' => 'OBM',
        'bibiche.waka' => 'GEST_PERF',
        'aline.buhendwa' => 'JURIDIQUE',
        'leonel.matondo' => 'STAB_FIN',
        'patrice.buabua' => 'CONF_LBC',
        'gabriel.ndundu' => 'SECU_SI',
        'adriel.elongo' => 'COMM',
        'shabani.anifa' => 'PORTEF',
        'siasi.mamonekene' => 'COOP_REG',
        'kony.beya' => 'REL_GOUV',
        'bazu.difuana' => 'SUP_BANC',
        'kakanda.selemani' => 'STAT',
        'talebuyi.tayeye' => 'POL_MON',
    ];

    private AsciiSlugger $slugger;

    public function __construct(
        private EntityManagerInterface $em,
        private XlsxReader $xlsxReader,
        private UtilisateurRepository $utilisateurRepo,
        private ConseillerGouverneurRepository $conseillerRepo,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
        $this->slugger = new AsciiSlugger();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Chemin du fichier Excel (.xlsx)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe par défaut des comptes créés', 'Actis@2026')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation : affiche les changements sans modifier la base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $io->title(($dryRun ? '[SIMULATION] ' : '') . 'Import du cabinet et des conseillers du Gouverneur');

        try {
            $lignes = $this->xlsxReader->read($input->getArgument('fichier'), [
                'N°' => 'numero',
                'Noms' => 'noms',
                'Fonction' => 'fonction',
                'Attributions' => 'attributions',
                'N° de téléphone' => 'telephone',
            ]);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $gouv = $this->em->getRepository(Entite::class)->findOneBy(['code' => self::ENTITE]);
        $roles = [];
        foreach ($this->em->getRepository(Role::class)->findAll() as $role) {
            $roles[$role->getCode()] = $role;
        }
        if (!$gouv || !isset($roles['CABINET'], $roles['CONSULTATION'])) {
            $io->error('Entité GOUV ou rôles CABINET / CONSULTATION introuvables.');
            return Command::FAILURE;
        }

        $conn = $this->em->getConnection();

        // Garde-fou : la simulation et l'annulation en cas d'erreur reposent sur une transaction,
        // impossible sur une table MyISAM (les écritures y seraient conservées)
        $nonTransactionnelles = $conn->fetchFirstColumn(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME IN ('utilisateurs', 'utilisateur_roles', 'conseillers_gouverneur') AND ENGINE <> 'InnoDB'"
        );
        if ($nonTransactionnelles) {
            $io->error(sprintf(
                "Tables non transactionnelles : %s. Convertissez-les d'abord : ALTER TABLE <table> ENGINE=InnoDB;",
                implode(', ', $nonTransactionnelles)
            ));
            return Command::FAILURE;
        }

        $rapport = [];
        $demo = [];
        $conn->beginTransaction();
        try {
            // 1. Fiches conseillers de démonstration : désactivées, et leur code libéré (préfixe DEMO_)
            foreach ($this->conseillerRepo->findAll() as $fiche) {
                if (!str_ends_with((string) $fiche->getUtilisateur()?->getEmail(), '@bcc.local')) {
                    continue;
                }
                $ancien = $fiche->getCodeDomaine();
                $changement = [];
                if ($fiche->isActif()) {
                    $fiche->setActif(false);
                    $changement[] = 'désactivée';
                }
                if (!str_starts_with($ancien, 'DEMO_')) {
                    $fiche->setCodeDomaine(substr('DEMO_' . $ancien, 0, 50));
                    $changement[] = 'code ' . $ancien . ' → ' . $fiche->getCodeDomaine();
                }
                if ($changement) {
                    $fiche->setUpdatedAt(new \DateTime());
                    $demo[] = [$fiche->getUtilisateur()->getEmail(), $ancien, implode(', ', $changement)];
                }
            }
            $this->em->flush();

            // 2. Cabinet et conseillers
            foreach ($lignes as $numeroLigne => $ligne) {
                $fonctionCle = mb_strtoupper(trim($ligne['fonction']));
                if (!isset(self::FONCTIONS[$fonctionCle])) {
                    throw new \RuntimeException("Ligne $numeroLigne : fonction inconnue « {$ligne['fonction']} ».");
                }
                [$roleCode, $fonctionLibelle] = self::FONCTIONS[$fonctionCle];

                $personne = $this->decouperNom($ligne['noms']);
                $email = $personne['identifiant'] . '@' . self::DOMAINE_EMAIL;
                $telephone = $this->normaliserTelephone($ligne['telephone']);

                $user = $this->utilisateurRepo->findOneBy(['email' => $email]);
                $statut = 'mis à jour';
                if (!$user) {
                    $user = (new Utilisateur())
                        ->setMatricule(sprintf('CONS%03d', (int) $ligne['numero']))
                        ->setEmail($email)
                        ->setActif(true);
                    $user->setMotDePasseHash($this->passwordHasher->hashPassword($user, $input->getOption('password')));
                    $this->em->persist($user);
                    $statut = 'créé';
                }
                $user->setPrenom($personne['prenom'])
                    ->setNom($personne['nom'])
                    ->setPostnom($personne['postnom'])
                    ->setTelephone($telephone)
                    ->setEntite($gouv)
                    ->setUpdatedAt(new \DateTime());
                if (!$user->hasRoleCode($roleCode)) {
                    $user->addAppRole($roles[$roleCode]);
                }

                // Fiche conseiller : uniquement pour les personnes ayant des attributions
                $code = '—';
                $attributions = rtrim(trim($ligne['attributions']), '.');
                if ($attributions !== '') {
                    $code = self::CODES_DOMAINE[$personne['identifiant']] ?? sprintf('CONS%03d', (int) $ligne['numero']);
                    $this->em->flush();
                    $fiche = $user->getId() ? $this->conseillerRepo->findByUtilisateur($user) : null;
                    $autre = $this->conseillerRepo->findOneBy(['codeDomaine' => $code]);
                    if ($autre && $autre !== $fiche) {
                        throw new \RuntimeException("Le code de domaine $code est déjà utilisé par une autre fiche conseiller.");
                    }
                    if (!$fiche) {
                        $fiche = (new ConseillerGouverneur())->setUtilisateur($user);
                        $this->em->persist($fiche);
                    }
                    $fiche->setCodeDomaine($code)
                        ->setDomaineCompetence(mb_substr($attributions, 0, 255))
                        ->setDescription($fonctionLibelle)
                        ->setOrdreAffichage((int) $ligne['numero'])
                        ->setActif(true)
                        ->setUpdatedAt(new \DateTime());
                }
                $this->em->flush();

                $rapport[] = [
                    $user->getMatricule(),
                    $user->getNomComplet(),
                    $email,
                    $telephone ?? '—',
                    $roleCode === 'CABINET' ? 'Cabinet' : 'Consultation',
                    $code,
                    $statut,
                ];
            }

            $dryRun ? $conn->rollBack() : $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $io->error('Import annulé, aucune modification enregistrée : ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($demo) {
            $io->section('Fiches conseillers de démonstration (' . count($demo) . ')');
            $io->table(['Compte', 'Ancien code', 'Changement'], $demo);
        }

        $io->section('Cabinet et conseillers (' . count($rapport) . ')');
        $io->table(['Matricule', 'Nom', 'Email', 'Téléphone', 'Rôle', 'Domaine', 'Statut'], $rapport);
        $io->text('Mot de passe des comptes créés : <info>' . $input->getOption('password') . '</info> (les comptes existants gardent le leur).');

        $dryRun
            ? $io->warning('SIMULATION : aucune modification n\'a été enregistrée. Relancez sans --dry-run pour appliquer.')
            : $io->success('Cabinet et conseillers importés.');

        return Command::SUCCESS;
    }

    /**
     * « NGONGO NGOY Jody » / « Hermann MULATU TULA » : le prénom est le mot écrit en minuscules,
     * les mots en MAJUSCULES forment le nom puis le postnom.
     * « KONY BEYA » (tout en majuscules) : pas de prénom identifiable, nom puis postnom.
     *
     * @return array{prenom: string, nom: string, postnom: ?string, identifiant: string}
     */
    private function decouperNom(string $noms): array
    {
        $mots = preg_split('/\s+/u', trim($noms));
        $majuscules = array_values(array_filter($mots, fn ($m) => $m === mb_strtoupper($m)));
        $prenoms = array_values(array_filter($mots, fn ($m) => $m !== mb_strtoupper($m)));

        if ($prenoms && $majuscules) {
            $prenom = implode(' ', $prenoms);
            $nom = $majuscules[0];
            $postnom = implode(' ', array_slice($majuscules, 1)) ?: null;
            $identifiant = $this->slug($prenoms[0]) . '.' . $this->slug($nom);
        } else {
            $prenom = '';
            $nom = $mots[0];
            $postnom = implode(' ', array_slice($mots, 1)) ?: null;
            $identifiant = $this->slug($nom) . ($postnom ? '.' . $this->slug($mots[1]) : '');
        }

        return ['prenom' => $prenom, 'nom' => $nom, 'postnom' => $postnom, 'identifiant' => $identifiant];
    }

    private function slug(string $mot): string
    {
        return $this->slugger->slug($mot, '')->lower()->toString();
    }

    /**
     * Numéros congolais à 9 chiffres : +243 829 902 070 ; numéros internationaux conservés tels quels.
     */
    private function normaliserTelephone(string $telephone): ?string
    {
        $telephone = trim($telephone);
        if ($telephone === '') {
            return null;
        }
        if (str_starts_with($telephone, '+')) {
            return $telephone;
        }

        $chiffres = preg_replace('/\D/', '', $telephone);
        if (strlen($chiffres) === 10 && $chiffres[0] === '0') {
            $chiffres = substr($chiffres, 1);
        }

        return strlen($chiffres) === 9
            ? '+243 ' . implode(' ', str_split($chiffres, 3))
            : $telephone;
    }
}
