<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\Commentaire;
use App\Entity\Instruction;
use App\Entity\Statut;
use App\Entity\TypeEvenement;
use App\Form\ActionAvancementType;
use App\Form\ActionType;
use App\Form\CommentaireType;
use App\Repository\ActionRepository;
use App\Repository\EntiteRepository;
use App\Repository\InstructionRepository;
use App\Repository\PrioriteRepository;
use App\Repository\StatutRepository;
use App\Repository\UtilisateurRepository;
use App\Service\InstructionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/actions')]
class ActionController extends AbstractController
{
    #[Route('', name: 'app_action_index', methods: ['GET'])]
    public function index(
        ActionRepository $actionRepo,
        StatutRepository $statutRepo,
        EntiteRepository $entiteRepo,
        UtilisateurRepository $userRepo,
        Request $request
    ): Response {
        $filters = [
            'q' => $request->query->get('q'),
            'statut' => $request->query->get('statut'),
            'entite' => $request->query->get('entite'),
            'responsable' => $request->query->get('responsable'),
            'instruction' => $request->query->get('instruction'),
            'retard' => $request->query->get('retard'),
            'my_actions' => $request->query->get('my_actions'),
        ];

        $actions = $actionRepo->searchAndFilter($filters, $this->getUser());

        return $this->render('action/index.html.twig', [
            'actions' => $actions,
            'statuts' => $statutRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']),
            'entites' => $entiteRepo->findBy(['actif' => true], ['nom' => 'ASC']),
            'utilisateurs' => $userRepo->findBy(['actif' => true], ['nom' => 'ASC']),
            'filters' => $filters,
        ]);
    }

    #[Route('/nouvelle/{instructionId}', name: 'app_action_new', methods: ['GET', 'POST'])]
    public function new(
        int $instructionId,
        Request $request,
        EntityManagerInterface $em,
        InstructionRepository $instructionRepo,
        InstructionService $instructionService,
        StatutRepository $statutRepo
    ): Response {
        $instruction = $instructionRepo->find($instructionId);
        if (!$instruction) {
            throw $this->createNotFoundException('Instruction non trouvée.');
        }

        $action = new Action();
        $action->setInstruction($instruction);
        $action->setEntiteResponsable($instruction->getEntitePilote());
        $action->setPriorite($instruction->getPriorite());
        $action->setDateDebut(new \DateTime());
        $action->setDateEcheance($instruction->getDateEcheance());

        $form = $this->createForm(ActionType::class, $action);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $action->setCreatedBy($currentUser);

            if (empty($action->getReference())) {
                $action->setReference($instructionService->generateActionReference($instruction));
            }

            $statutInitial = $action->getResponsable()
                ? $statutRepo->findOneBy(['code' => Statut::CODE_AFFECTEE])
                : $statutRepo->findOneBy(['code' => Statut::CODE_BROUILLON]);
            
            $action->setStatut($statutInitial ?? $statutRepo->findOneBy([]));

            $em->persist($action);
            $em->flush();

            $instructionService->logHistorique(
                $action,
                TypeEvenement::CREATION,
                $currentUser,
                null,
                $action->getStatut(),
                sprintf('Création de l\'action opérationnelle "%s" pour l\'instruction %s.', $action->getLibelle(), $instruction->getReference())
            );

            // Recalculate instruction progress
            $instructionService->updateInstructionProgress($instruction, $currentUser);

            $this->addFlash('success', 'L\'action a été créée avec succès.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        return $this->render('action/new.html.twig', [
            'instruction' => $instruction,
            'action' => $action,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_action_show', methods: ['GET', 'POST'])]
    public function show(
        Action $action,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        $currentUser = $this->getUser();

        // Comment form
        $commentaire = new Commentaire();
        $commentForm = $this->createForm(CommentaireType::class, $commentaire);
        $commentForm->handleRequest($request);

        if ($commentForm->isSubmitted() && $commentForm->isValid()) {
            $commentaire->setAction($action);
            $commentaire->setInstruction($action->getInstruction());
            $commentaire->setUtilisateur($currentUser);
            $em->persist($commentaire);

            $instructionService->logHistorique(
                $action,
                TypeEvenement::COMMENTAIRE_AJOUTE,
                $currentUser,
                null,
                null,
                sprintf('Nouveau commentaire sur l\'action "%s".', $action->getLibelle())
            );

            $em->flush();
            $this->addFlash('success', 'Commentaire ajouté avec succès.');
            return $this->redirectToRoute('app_action_show', ['id' => $action->getId()]);
        }

        return $this->render('action/show.html.twig', [
            'action' => $action,
            'commentForm' => $commentForm,
        ]);
    }

    #[Route('/{id}/avancement', name: 'app_action_avancement', methods: ['GET', 'POST'])]
    public function avancement(
        Action $action,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService,
        StatutRepository $statutRepo
    ): Response {
        $oldTaux = $action->getTauxAvancement();
        $oldStatut = $action->getStatut();

        $form = $this->createForm(ActionAvancementType::class, $action);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $action->setUpdatedAt(new \DateTime());

            if ((float)$action->getTauxAvancement() === 100.0 && !$action->getDateRealisation()) {
                $action->setDateRealisation(new \DateTime());
            }

            $instructionService->logHistorique(
                $action,
                TypeEvenement::AVANCEMENT,
                $currentUser,
                $oldStatut,
                $action->getStatut(),
                sprintf('Mise à jour de l\'avancement : %s%% -> %s%%. Statut : %s.', $oldTaux, $action->getTauxAvancement(), $action->getStatut()?->getLibelle())
            );

            $em->flush();

            // Recalculate instruction global progress
            $instructionService->updateInstructionProgress($action->getInstruction(), $currentUser);

            $this->addFlash('success', 'Avancement mis à jour avec succès.');
            return $this->redirectToRoute('app_action_show', ['id' => $action->getId()]);
        }

        return $this->render('action/avancement.html.twig', [
            'action' => $action,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/valider', name: 'app_action_valider', methods: ['POST'])]
    public function valider(
        Action $action,
        Request $request,
        EntityManagerInterface $em,
        StatutRepository $statutRepo,
        InstructionService $instructionService
    ): Response {
        $decision = $request->request->get('decision'); // 'VALIDE' or 'COMPLEMENT'
        $motif = $request->request->get('motif');
        $currentUser = $this->getUser();

        if ($decision === 'VALIDE') {
            $statutExec = $statutRepo->findOneBy(['code' => Statut::CODE_EXECUTEE]);
            $oldStatut = $action->getStatut();
            $action->setStatut($statutExec);
            $action->setTauxAvancement(100.0);
            if (!$action->getDateRealisation()) {
                $action->setDateRealisation(new \DateTime());
            }

            $instructionService->logHistorique(
                $action,
                TypeEvenement::CHANGEMENT_STATUT,
                $currentUser,
                $oldStatut,
                $statutExec,
                'Action validée et déclarée officiellement exécutée après vérification des livrables.'
            );

            $this->addFlash('success', 'L\'action a été validée avec succès.');
        } elseif ($decision === 'COMPLEMENT') {
            $statutAttente = $statutRepo->findOneBy(['code' => Statut::CODE_EN_ATTENTE]) ?? $statutRepo->findOneBy(['code' => Statut::CODE_EN_COURS]);
            $oldStatut = $action->getStatut();
            $action->setStatut($statutAttente);

            $instructionService->logHistorique(
                $action,
                TypeEvenement::CHANGEMENT_STATUT,
                $currentUser,
                $oldStatut,
                $statutAttente,
                sprintf('Demande de complément / révision : %s', $motif)
            );

            $this->addFlash('warning', 'Une demande de complément a été émise.');
        }

        $action->setUpdatedAt(new \DateTime());
        $em->flush();

        $instructionService->updateInstructionProgress($action->getInstruction(), $currentUser);

        return $this->redirectToRoute('app_action_show', ['id' => $action->getId()]);
    }

    #[Route('/{id}/modifier', name: 'app_action_edit', methods: ['GET', 'POST'])]
    public function edit(
        Action $action,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        $form = $this->createForm(ActionType::class, $action);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $action->setUpdatedAt(new \DateTime());

            $instructionService->logHistorique(
                $action,
                TypeEvenement::MODIFICATION,
                $currentUser,
                null,
                null,
                'Modification des paramètres de l\'action.'
            );

            $em->flush();
            $this->addFlash('success', 'L\'action a été modifiée avec succès.');
            return $this->redirectToRoute('app_action_show', ['id' => $action->getId()]);
        }

        return $this->render('action/edit.html.twig', [
            'action' => $action,
            'form' => $form,
        ]);
    }
}
