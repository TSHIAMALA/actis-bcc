<?php

namespace App\Controller;

use App\Security\DemoAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DemoController extends AbstractController
{
    /**
     * Connexion en un clic via l'annuaire BCC simulé (mode démonstration uniquement).
     */
    #[Route('/demo/connexion/{profil}', name: 'app_demo_login', methods: ['POST'])]
    public function login(string $profil, Request $request, DemoAccess $demoAccess, Security $security): Response
    {
        // Hors mode démo, la route n'existe pas
        if (!$demoAccess->isEnabled()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('demo_login', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('app_login');
        }

        $user = $demoAccess->findUser($profil);
        if (!$user) {
            throw $this->createNotFoundException('Profil de démonstration introuvable ou inactif.');
        }

        $security->login($user, 'form_login', 'main');

        return $this->redirectToRoute('app_dashboard');
    }
}
