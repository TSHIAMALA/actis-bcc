<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;

/**
 * Mode démonstration : « annuaire BCC simulé » permettant de se connecter
 * en un clic avec un profil type, sans mot de passe.
 *
 * Entièrement désactivé si APP_DEMO_MODE=0 (valeur par défaut, obligatoire en production) :
 * le bouton disparaît de la page de connexion et la route de connexion renvoie une 404.
 */
class DemoAccess
{
    /**
     * Profils présentés dans l'annuaire simulé, du plus élevé au plus opérationnel.
     */
    private const PROFILES = [
        'admin' => [
            'email' => 'admin.actis@bcc.local',
            'icon' => 'bi-shield-lock',
            'titre' => 'Administrateur',
            'description' => 'Paramétrage, référentiels et gestion des utilisateurs',
        ],
        'cabinet' => [
            'email' => 'paul.kabeya@bcc.local',
            'icon' => 'bi-bank2',
            'titre' => 'Cabinet du Gouverneur',
            'description' => 'Émet les instructions et suit leur exécution',
        ],
        'dg' => [
            'email' => 'marie.mukendi@bcc.local',
            'icon' => 'bi-briefcase',
            'titre' => 'Direction générale',
            'description' => 'Supervise les directions et valide les prorogations',
        ],
        'directeur' => [
            'email' => 'jean.tshibanda@bcc.local',
            'icon' => 'bi-diagram-3',
            'titre' => 'Directeur',
            'description' => 'Pilote les instructions confiées à sa direction',
        ],
        'responsable' => [
            'email' => 'patrick.ilunga@bcc.local',
            'icon' => 'bi-person-gear',
            'titre' => 'Responsable d\'action',
            'description' => 'Exécute les actions et déclare l\'avancement',
        ],
    ];

    public function __construct(
        private bool $enabled,
        private UtilisateurRepository $utilisateurRepo
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Profils disponibles (uniquement ceux dont le compte existe et est actif).
     *
     * @return array<string, array{icon: string, titre: string, description: string, user: Utilisateur}>
     */
    public function getProfiles(): array
    {
        if (!$this->enabled) {
            return [];
        }

        $profiles = [];
        foreach (self::PROFILES as $key => $profile) {
            $user = $this->findActiveUser($profile['email']);
            if ($user) {
                $profiles[$key] = $profile + ['user' => $user];
            }
        }

        return $profiles;
    }

    public function findUser(string $profileKey): ?Utilisateur
    {
        if (!$this->enabled || !isset(self::PROFILES[$profileKey])) {
            return null;
        }

        return $this->findActiveUser(self::PROFILES[$profileKey]['email']);
    }

    private function findActiveUser(string $email): ?Utilisateur
    {
        $user = $this->utilisateurRepo->findOneBy(['email' => $email]);

        return $user && $user->isActif() ? $user : null;
    }
}
