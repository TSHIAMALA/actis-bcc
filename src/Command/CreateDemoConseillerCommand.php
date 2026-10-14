<?php

namespace App\Command;

use App\Entity\Action;
use App\Entity\ConseillerGouverneur;
use App\Entity\Entite;
use App\Entity\Role;
use App\Entity\SuiviConseiller;
use App\Entity\Utilisateur;
use App\Repository\ConseillerGouverneurRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée le compte de démonstration d'un Conseiller du Gouverneur :
 *  - utilisateur rattaché au Gouverneur (GOUV), rôle Consultation (lecture seule) ;
 *  - fiche ConseillerGouverneur (domaine de compétence), qui lui donne le droit
 *    de rendre ses avis sur les actions que le Cabinet lui confie ;
 *  - une action confiée en attente d'avis, pour que « Mes suivis » ne soit pas vide en démo.
 *
 * Idempotente : relancer la commande ne crée pas de doublon.
 */
#[AsCommand(
    name: 'app:demo:create-conseiller',
    description: 'Crée le compte de démonstration d\'un Conseiller du Gouverneur'
)]
class CreateDemoConseillerCommand extends Command
{
    public const EMAIL = 'joseph.mbala@bcc.local';
    private const MATRICULE = 'ACTIS008';
    // Préfixe DEMO_ : le code SUP_BANC appartient au vrai conseiller (app:import-conseillers)
    private const CODE_DOMAINE = 'DEMO_SUP_BANC';
    private const DOMAINE = 'Supervision bancaire & Stabilité financière';
    private const ACTION_DEMO = 'OSG-2026-001-A01';

    public function __construct(
        private EntityManagerInterface $em,
        private UtilisateurRepository $utilisateurRepo,
        private ConseillerGouverneurRepository $conseillerRepo,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe du compte (uniquement à la création)', 'password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Compte de démonstration : Conseiller du Gouverneur');

        $gouv = $this->em->getRepository(Entite::class)->findOneBy(['code' => 'GOUV']);
        $roleConsultation = $this->em->getRepository(Role::class)->findOneBy(['code' => 'CONSULTATION']);
        if (!$gouv || !$roleConsultation) {
            $io->error('Entité GOUV ou rôle CONSULTATION introuvable dans la base.');
            return Command::FAILURE;
        }

        // 1. Utilisateur
        $user = $this->utilisateurRepo->findOneBy(['email' => self::EMAIL]);
        if ($user) {
            $io->writeln('• Utilisateur déjà présent : ' . self::EMAIL);
        } else {
            $user = (new Utilisateur())
                ->setMatricule(self::MATRICULE)
                ->setPrenom('Joseph')
                ->setNom('Mbala')
                ->setPostnom('Nsimba')
                ->setEmail(self::EMAIL)
                ->setEntite($gouv)
                ->setActif(true)
                ->addAppRole($roleConsultation);
            $user->setMotDePasseHash($this->passwordHasher->hashPassword($user, $input->getOption('password')));
            $this->em->persist($user);
            $io->writeln('• Utilisateur créé : ' . self::EMAIL . ' (rôle Consultation, entité GOUV)');
        }

        // 2. Fiche conseiller
        $conseiller = $this->conseillerRepo->findOneBy(['codeDomaine' => self::CODE_DOMAINE]);
        if ($conseiller) {
            $io->writeln('• Fiche conseiller déjà présente : ' . self::CODE_DOMAINE);
        } else {
            $conseiller = (new ConseillerGouverneur())
                ->setUtilisateur($user)
                ->setCodeDomaine(self::CODE_DOMAINE)
                ->setDomaineCompetence(self::DOMAINE)
                ->setDescription('Conseiller du Gouverneur chargé de la supervision des établissements de crédit et de la stabilité du système financier.')
                ->setOrdreAffichage(5)
                // Fiche de démo inactive : elle n'apparaît pas parmi les vrais conseillers
                // (le suivi déjà confié reste consultable par le compte de démo)
                ->setActif(false);
            $this->em->persist($conseiller);
            $io->writeln('• Fiche conseiller créée : ' . self::CODE_DOMAINE . ' — ' . self::DOMAINE);
        }

        $this->em->flush();

        // 3. Une action confiée pour avis (démo)
        $action = $this->em->getRepository(Action::class)->findOneBy(['reference' => self::ACTION_DEMO]);
        if (!$action) {
            $io->note('Action ' . self::ACTION_DEMO . ' introuvable : aucun suivi de démonstration créé.');
        } elseif ($this->em->getRepository(SuiviConseiller::class)->findOneBy(['action' => $action, 'conseiller' => $conseiller])) {
            $io->writeln('• Suivi de démonstration déjà présent sur ' . self::ACTION_DEMO);
        } else {
            $cabinet = $this->utilisateurRepo->findOneBy(['email' => 'paul.kabeya@bcc.local']);
            $suivi = (new SuiviConseiller())
                ->setAction($action)
                ->setConseiller($conseiller)
                ->setStatutSuivi(SuiviConseiller::STATUT_EN_ATTENTE)
                ->setPrioriteCabinet(SuiviConseiller::PRIORITE_URGENTE)
                ->setPointAttention('Vérifier la cohérence de la situation consolidée avec les ratios prudentiels avant transmission au Gouverneur.')
                ->setAffectePar($cabinet);
            $this->em->persist($suivi);
            $this->em->flush();
            $io->writeln('• Action ' . self::ACTION_DEMO . ' confiée au conseiller pour avis (priorité urgente)');
        }

        $io->success('Compte conseiller prêt : ' . self::EMAIL);

        return Command::SUCCESS;
    }
}
