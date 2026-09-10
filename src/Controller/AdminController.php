<?php

namespace App\Controller;

use App\Entity\CanalNotification;
use App\Entity\Entite;
use App\Entity\Priorite;
use App\Entity\TypeEntite;
use App\Entity\TypeInstruction;
use App\Entity\TypeRelance;
use App\Entity\Utilisateur;
use App\Form\CanalNotificationType;
use App\Form\EntiteType;
use App\Form\PrioriteType;
use App\Form\TypeEntiteType;
use App\Form\TypeInstructionType;
use App\Form\TypeRelanceType;
use App\Form\UtilisateurType;
use App\Repository\CanalNotificationRepository;
use App\Repository\EntiteRepository;
use App\Repository\HistoriqueRepository;
use App\Repository\PrioriteRepository;
use App\Repository\RoleRepository;
use App\Repository\StatutRepository;
use App\Repository\TypeEntiteRepository;
use App\Repository\TypeInstructionRepository;
use App\Repository\TypeRelanceRepository;
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
        HistoriqueRepository $histRepo,
        TypeInstructionRepository $typeRepo,
        PrioriteRepository $prioriteRepo
    ): Response {
        return $this->render('admin/index.html.twig', [
            'totalUsers' => $userRepo->count([]),
            'totalEntites' => $entiteRepo->count([]),
            'totalTypes' => $typeRepo->count([]),
            'totalPriorites' => $prioriteRepo->count([]),
            'recentAudit' => $histRepo->findBy([], ['createdAt' => 'DESC'], 15),
        ]);
    }

    // ==========================================
    // UTILISATEURS (CRUD + Toggle / Soft Delete)
    // ==========================================

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

    #[Route('/utilisateurs/{id}/toggle-actif', name: 'app_admin_user_toggle_actif', methods: ['POST'])]
    public function userToggleActif(
        Utilisateur $user,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('toggle_user_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_utilisateurs');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('warning', 'Vous ne pouvez pas désactiver votre propre compte administrateur.');
            return $this->redirectToRoute('app_admin_utilisateurs');
        }

        $user->setActif(!$user->isActif());
        $user->setUpdatedAt(new \DateTime());
        $em->flush();

        $statusText = $user->isActif() ? 'activé' : 'désactivé (suspendu)';
        $this->addFlash('success', sprintf('L\'utilisateur %s a été %s.', $user->getNomComplet(), $statusText));

        return $this->redirectToRoute('app_admin_utilisateurs');
    }

    #[Route('/utilisateurs/{id}/supprimer', name: 'app_admin_user_delete', methods: ['POST'])]
    public function userDelete(
        Utilisateur $user,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_utilisateurs');
        }

        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'Action impossible : vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('app_admin_utilisateurs');
        }

        // Soft delete / désactivation logique
        $user->setActif(false);
        $user->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', sprintf('L\'utilisateur %s a été archivé/désactivé du système.', $user->getNomComplet()));
        return $this->redirectToRoute('app_admin_utilisateurs');
    }

    // ==========================================
    // ENTITÉS & ORGANIGRAMME (CRUD + Soft Delete)
    // ==========================================

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

    #[Route('/entites/{id}/toggle-actif', name: 'app_admin_entite_toggle_actif', methods: ['POST'])]
    public function entiteToggleActif(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('toggle_entite_' . $entite->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_entites');
        }

        $entite->setActif(!$entite->isActif());
        $entite->setUpdatedAt(new \DateTime());
        $em->flush();

        $statusText = $entite->isActif() ? 'activée' : 'désactivée (archivée)';
        $this->addFlash('success', sprintf('L\'entité %s a été %s.', $entite->getNomComplet(), $statusText));

        return $this->redirectToRoute('app_admin_entites');
    }

    #[Route('/entites/{id}/supprimer', name: 'app_admin_entite_delete', methods: ['POST'])]
    public function entiteDelete(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('delete_entite_' . $entite->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_admin_entites');
        }

        // Soft delete / désactivation logique
        $entite->setActif(false);
        $entite->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', sprintf('L\'entité %s a été désactivée/archivée.', $entite->getNomComplet()));
        return $this->redirectToRoute('app_admin_entites');
    }

    // ==========================================
    // RÉFÉRENTIELS & PARAMÈTRES SYSTÈME (CRUD)
    // ==========================================

    #[Route('/referentiels', name: 'app_admin_referentiels', methods: ['GET'])]
    public function referentiels(
        TypeInstructionRepository $typeRepo,
        PrioriteRepository $prioriteRepo,
        TypeEntiteRepository $typeEntiteRepo,
        TypeRelanceRepository $typeRelanceRepo,
        CanalNotificationRepository $canalRepo,
        Request $request
    ): Response {
        $activeTab = $request->query->get('tab', 'types_instruction');

        return $this->render('admin/referentiels/index.html.twig', [
            'activeTab' => $activeTab,
            'typesInstruction' => $typeRepo->findBy([], ['ordreAffichage' => 'ASC', 'libelle' => 'ASC']),
            'priorites' => $prioriteRepo->findBy([], ['ordreAffichage' => 'ASC', 'niveau' => 'DESC']),
            'typesEntite' => $typeEntiteRepo->findBy([], ['ordreAffichage' => 'ASC', 'niveau' => 'ASC']),
            'typesRelance' => $typeRelanceRepo->findBy([], ['ordreAffichage' => 'ASC']),
            'canaux' => $canalRepo->findBy([], ['ordreAffichage' => 'ASC']),
        ]);
    }

    // --- Types d'Instruction ---
    #[Route('/referentiels/types-instruction/nouveau', name: 'app_admin_type_instruction_new', methods: ['GET', 'POST'])]
    public function typeInstructionNew(Request $request, EntityManagerInterface $em): Response
    {
        $type = new TypeInstruction();
        $form = $this->createForm(TypeInstructionType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($type);
            $em->flush();
            $this->addFlash('success', sprintf('Type d\'instruction "%s" créé.', $type->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_instruction']);
        }

        return $this->render('admin/referentiels/type_instruction_form.html.twig', [
            'form' => $form,
            'isEdit' => false,
            'type' => $type,
        ]);
    }

    #[Route('/referentiels/types-instruction/{id}/modifier', name: 'app_admin_type_instruction_edit', methods: ['GET', 'POST'])]
    public function typeInstructionEdit(TypeInstruction $type, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TypeInstructionType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Type d\'instruction "%s" mis à jour.', $type->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_instruction']);
        }

        return $this->render('admin/referentiels/type_instruction_form.html.twig', [
            'form' => $form,
            'isEdit' => true,
            'type' => $type,
        ]);
    }

    #[Route('/referentiels/types-instruction/{id}/toggle', name: 'app_admin_type_instruction_toggle', methods: ['POST'])]
    public function typeInstructionToggle(TypeInstruction $type, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle_type_' . $type->getId(), $request->request->get('_token'))) {
            $type->setActif(!$type->isActif());
            $em->flush();
            $this->addFlash('success', sprintf('Statut du type "%s" modifié.', $type->getLibelle()));
        }
        return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_instruction']);
    }

    // --- Priorités ---
    #[Route('/referentiels/priorites/nouvelle', name: 'app_admin_priorite_new', methods: ['GET', 'POST'])]
    public function prioriteNew(Request $request, EntityManagerInterface $em): Response
    {
        $priorite = new Priorite();
        $form = $this->createForm(PrioriteType::class, $priorite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($priorite);
            $em->flush();
            $this->addFlash('success', sprintf('Priorité "%s" créée.', $priorite->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'priorites']);
        }

        return $this->render('admin/referentiels/priorite_form.html.twig', [
            'form' => $form,
            'isEdit' => false,
            'priorite' => $priorite,
        ]);
    }

    #[Route('/referentiels/priorites/{id}/modifier', name: 'app_admin_priorite_edit', methods: ['GET', 'POST'])]
    public function prioriteEdit(Priorite $priorite, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(PrioriteType::class, $priorite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Priorité "%s" mise à jour.', $priorite->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'priorites']);
        }

        return $this->render('admin/referentiels/priorite_form.html.twig', [
            'form' => $form,
            'isEdit' => true,
            'priorite' => $priorite,
        ]);
    }

    #[Route('/referentiels/priorites/{id}/toggle', name: 'app_admin_priorite_toggle', methods: ['POST'])]
    public function prioriteToggle(Priorite $priorite, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle_priorite_' . $priorite->getId(), $request->request->get('_token'))) {
            $priorite->setActif(!$priorite->isActif());
            $em->flush();
            $this->addFlash('success', sprintf('Statut de la priorité "%s" modifié.', $priorite->getLibelle()));
        }
        return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'priorites']);
    }

    // --- Types d'Entité ---
    #[Route('/referentiels/types-entite/nouveau', name: 'app_admin_type_entite_new', methods: ['GET', 'POST'])]
    public function typeEntiteNew(Request $request, EntityManagerInterface $em): Response
    {
        $type = new TypeEntite();
        $form = $this->createForm(TypeEntiteType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($type);
            $em->flush();
            $this->addFlash('success', sprintf('Type d\'entité "%s" créé.', $type->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_entite']);
        }

        return $this->render('admin/referentiels/type_entite_form.html.twig', [
            'form' => $form,
            'isEdit' => false,
            'type' => $type,
        ]);
    }

    #[Route('/referentiels/types-entite/{id}/modifier', name: 'app_admin_type_entite_edit', methods: ['GET', 'POST'])]
    public function typeEntiteEdit(TypeEntite $type, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TypeEntiteType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Type d\'entité "%s" mis à jour.', $type->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_entite']);
        }

        return $this->render('admin/referentiels/type_entite_form.html.twig', [
            'form' => $form,
            'isEdit' => true,
            'type' => $type,
        ]);
    }

    #[Route('/referentiels/types-entite/{id}/toggle', name: 'app_admin_type_entite_toggle', methods: ['POST'])]
    public function typeEntiteToggle(TypeEntite $type, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle_type_entite_' . $type->getId(), $request->request->get('_token'))) {
            $type->setActif(!$type->isActif());
            $em->flush();
            $this->addFlash('success', sprintf('Statut du type d\'entité "%s" modifié.', $type->getLibelle()));
        }
        return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_entite']);
    }

    // --- Types de Relance ---
    #[Route('/referentiels/types-relance/nouveau', name: 'app_admin_type_relance_new', methods: ['GET', 'POST'])]
    public function typeRelanceNew(Request $request, EntityManagerInterface $em): Response
    {
        $type = new TypeRelance();
        $form = $this->createForm(TypeRelanceType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($type);
            $em->flush();
            $this->addFlash('success', sprintf('Type de relance "%s" créé.', $type->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_relance']);
        }

        return $this->render('admin/referentiels/type_relance_form.html.twig', [
            'form' => $form,
            'isEdit' => false,
            'type' => $type,
        ]);
    }

    #[Route('/referentiels/types-relance/{id}/modifier', name: 'app_admin_type_relance_edit', methods: ['GET', 'POST'])]
    public function typeRelanceEdit(TypeRelance $type, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TypeRelanceType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Type de relance "%s" mis à jour.', $type->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_relance']);
        }

        return $this->render('admin/referentiels/type_relance_form.html.twig', [
            'form' => $form,
            'isEdit' => true,
            'type' => $type,
        ]);
    }

    #[Route('/referentiels/types-relance/{id}/toggle', name: 'app_admin_type_relance_toggle', methods: ['POST'])]
    public function typeRelanceToggle(TypeRelance $type, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle_type_relance_' . $type->getId(), $request->request->get('_token'))) {
            $type->setActif(!$type->isActif());
            $em->flush();
            $this->addFlash('success', sprintf('Statut du type de relance "%s" modifié.', $type->getLibelle()));
        }
        return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'types_relance']);
    }

    // --- Canaux de Notification ---
    #[Route('/referentiels/canaux/nouveau', name: 'app_admin_canal_new', methods: ['GET', 'POST'])]
    public function canalNew(Request $request, EntityManagerInterface $em): Response
    {
        $canal = new CanalNotification();
        $form = $this->createForm(CanalNotificationType::class, $canal);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($canal);
            $em->flush();
            $this->addFlash('success', sprintf('Canal "%s" créé.', $canal->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'canaux']);
        }

        return $this->render('admin/referentiels/canal_form.html.twig', [
            'form' => $form,
            'isEdit' => false,
            'canal' => $canal,
        ]);
    }

    #[Route('/referentiels/canaux/{id}/modifier', name: 'app_admin_canal_edit', methods: ['GET', 'POST'])]
    public function canalEdit(CanalNotification $canal, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CanalNotificationType::class, $canal);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', sprintf('Canal "%s" mis à jour.', $canal->getLibelle()));
            return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'canaux']);
        }

        return $this->render('admin/referentiels/canal_form.html.twig', [
            'form' => $form,
            'isEdit' => true,
            'canal' => $canal,
        ]);
    }

    #[Route('/referentiels/canaux/{id}/toggle', name: 'app_admin_canal_toggle', methods: ['POST'])]
    public function canalToggle(CanalNotification $canal, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle_canal_' . $canal->getId(), $request->request->get('_token'))) {
            $canal->setActif(!$canal->isActif());
            $em->flush();
            $this->addFlash('success', sprintf('Statut du canal "%s" modifié.', $canal->getLibelle()));
        }
        return $this->redirectToRoute('app_admin_referentiels', ['tab' => 'canaux']);
    }

    // ==========================================
    // JOURNAL D'AUDIT
    // ==========================================

    #[Route('/journal-audit', name: 'app_admin_audit', methods: ['GET'])]
    public function audit(HistoriqueRepository $histRepo): Response
    {
        $logs = $histRepo->findBy([], ['createdAt' => 'DESC'], 100);

        return $this->render('admin/audit.html.twig', [
            'logs' => $logs,
        ]);
    }
}
