<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private UtilisateurRepository $utilisateurRepository)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->utilisateurRepository->findByEmailOrMatricule($identifier);

        if (!$user) {
            $e = new UserNotFoundException(sprintf('Utilisateur non trouvé avec l\'identifiant "%s".', $identifier));
            $e->setUserIdentifier($identifier);
            throw $e;
        }

        if (!$user->isActif()) {
            $e = new UserNotFoundException('Ce compte utilisateur a été désactivé.');
            $e->setUserIdentifier($identifier);
            throw $e;
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return Utilisateur::class === $class || is_subclass_of($class, Utilisateur::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $this->utilisateurRepository->upgradePassword($user, $newHashedPassword);
    }
}
