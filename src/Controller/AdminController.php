<?php

namespace App\Controller;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Form\EntiteType;
use App\Form\UtilisateurType;
use App\Repository\EntiteRepository;
use App\Repository\HistoriqueRepository;
use App\Repository\PrioriteRepository;
use App\Repository\RoleRepository;
use App\Repository\StatutRepository;
use App\Repository\TypeEntiteRepository;
use App\Repository\TypeInstructionRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('', name: 'app_admin_index', methods: ['GET'])]
    public function index(
        UtilisateurRepository $userRepo,
        EntiteRepository $entiteRepo,
        HistoriqueRepository $histRepo
    ): Response {
        return $this->render('admin/index.html.twig', [
            'totalUsers' => $userRepo->count([]),
            'totalEntites' => $entiteRepo->count([]),
            'recentAudit' => $histRepo->findBy([], ['createdAt' => 'DESC'], 15),
        ]);
    }

    #[Route('/utilisateurs', name: 'app_admin_utilisateurs', methods: ['GET'])]
    public function utilisateurs(UtilisateurRepository $userRepo): Response
    {
        return $this->render('admin/utilisateurs.html.twig', [
            'users' => $userRepo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/utilisateurs/nouveau', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function userNew(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = new Utilisateur();
        $form = $this->createForm(UtilisateurType::class, $user, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $user->setMotDePasseHash($hasher->hashPassword($user, $plainPassword));
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', sprintf('L\'utilisateur %s a été créé avec succès.', $user->getNomComplet()));
            return $this->redirectToRoute('app_admin_utilisateurs');
        }

        return $this->render('admin/user_form.html.twig', [
            'form' => $form,
            'isEdit' => false,
            'user' => $user,
        ]);
    }

    #[Route('/utilisateurs/{id}/modifier', name: 'app_admin_user_edit', methods: ['GET', 'POST'])]
    public function userEdit(
        Utilisateur $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $form = $this->createForm(UtilisateurType::class, $user, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if (!empty($plainPassword)) {
                $user->setMotDePasseHash($hasher->hashPassword($user, $plainPassword));
            }

            $user->setUpdatedAt(new \DateTime());
            $em->flush();

            $this->addFlash('success', sprintf('L\'utilisateur %s a été mis à jour.', $user->getNomComplet()));
            return $this->redirectToRoute('app_admin_utilisateurs');
        }

        return $this->render('admin/user_form.html.twig', [
            'form' => $form,
            'isEdit' => true,
            'user' => $user,
        ]);
    }

    #[Route('/entites', name: 'app_admin_entites', methods: ['GET'])]
    public function entites(EntiteRepository $entiteRepo): Response
    {
        return $this->render('admin/entites.html.twig', [
            'entites' => $entiteRepo->findBy([], ['nom' => 'ASC']),
        ]);
    }

    #[Route('/entites/nouvelle', name: 'app_admin_entite_new', methods: ['GET', 'POST'])]
    public function entiteNew(Request $request, EntityManagerInterface $em): Response
    {
        $entite = new Entite();
        $form = $this->createForm(EntiteType::class, $entite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entite);
            $em->flush();

            $this->addFlash('success', sprintf('L\'entité %s a été créée avec succès.', $entite->getNomComplet()));
            return $this->redirectToRoute('app_admin_entites');
        }

        return $this->render('admin/entite_form.html.twig', [
            'form' => $form,
            'isEdit' => false,
            'entite' => $entite,
        ]);
    }

    #[Route('/entites/{id}/modifier', name: 'app_admin_entite_edit', methods: ['GET', 'POST'])]
    public function entiteEdit(Entite $entite, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EntiteType::class, $entite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entite->setUpdatedAt(new \DateTime());
            $em->flush();

            $this->addFlash('success', sprintf('L\'entité %s a été mise à jour.', $entite->getNomComplet()));
            return $this->redirectToRoute('app_admin_entites');
        }

        return $this->render('admin/entite_form.html.twig', [
            'form' => $form,
            'isEdit' => true,
            'entite' => $entite,
        ]);
    }

    #[Route('/journal-audit', name: 'app_admin_audit', methods: ['GET'])]
    public function audit(HistoriqueRepository $histRepo): Response
    {
        $logs = $histRepo->findBy([], ['createdAt' => 'DESC'], 100);

        return $this->render('admin/audit.html.twig', [
            'logs' => $logs,
        ]);
    }
}
