<?php

namespace App\Controller;

use App\Entity\Commentaire;
use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\InstructionEntite;
use App\Entity\Statut;
use App\Entity\TypeEvenement;
use App\Form\CommentaireType;
use App\Form\InstructionType;
use App\Form\JustificatifType;
use App\Form\RelanceType;
use App\Repository\EntiteRepository;
use App\Repository\InstructionRepository;
use App\Repository\PrioriteRepository;
use App\Repository\StatutRepository;
use App\Repository\TypeInstructionRepository;
use App\Repository\UtilisateurRepository;
use App\Service\FileUploader;
use App\Service\InstructionService;
use App\Service\RelanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/instructions')]
class InstructionController extends AbstractController
{
    #[Route('', name: 'app_instruction_index', methods: ['GET'])]
    public function index(
        InstructionRepository $instructionRepo,
        StatutRepository $statutRepo,
        PrioriteRepository $prioriteRepo,
        TypeInstructionRepository $typeRepo,
        EntiteRepository $entiteRepo,
        UtilisateurRepository $userRepo,
        Request $request
    ): Response {
        $filters = [
            'q' => $request->query->get('q'),
            'statut' => $request->query->get('statut'),
            'priorite' => $request->query->get('priorite'),
            'type' => $request->query->get('type'),
            'entite' => $request->query->get('entite'),
            'responsable' => $request->query->get('responsable'),
            'retard' => $request->query->get('retard'),
            'date_debut' => $request->query->get('date_debut'),
            'date_fin' => $request->query->get('date_fin'),
        ];

        $instructions = $instructionRepo->searchAndFilter($filters);

        return $this->render('instruction/index.html.twig', [
            'instructions' => $instructions,
            'statuts' => $statutRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']),
            'priorites' => $prioriteRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']),
            'types' => $typeRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']),
            'entites' => $entiteRepo->findBy(['actif' => true], ['nom' => 'ASC']),
            'utilisateurs' => $userRepo->findBy(['actif' => true], ['nom' => 'ASC']),
            'filters' => $filters,
        ]);
    }

    #[Route('/nouvelle', name: 'app_instruction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService,
        StatutRepository $statutRepo
    ): Response {
        $instruction = new Instruction();
        $instruction->setDateInstruction(new \DateTime());
        $instruction->setEmetteur('Gouverneur de la Banque Centrale');

        $form = $this->createForm(InstructionType::class, $instruction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $instruction->setCreatedBy($currentUser);

            if (empty($instruction->getReference())) {
                $instruction->setReference($instructionService->generateReference($instruction->getTypeInstruction()));
            }

            // Statut initial
            $statutInitial = $instruction->getResponsable() 
                ? $statutRepo->findOneBy(['code' => Statut::CODE_AFFECTEE]) 
                : $statutRepo->findOneBy(['code' => Statut::CODE_BROUILLON]);
            
            $instruction->setStatut($statutInitial ?? $statutRepo->findOneBy([]));

            $em->persist($instruction);
            $em->flush();

            $instructionService->logHistorique(
                $instruction,
                TypeEvenement::CREATION,
                $currentUser,
                null,
                $instruction->getStatut(),
                sprintf('Enregistrement de l\'instruction/OS "%s".', $instruction->getReference())
            );
            $em->flush();

            $this->addFlash('success', 'L\'instruction a été créée et enregistrée avec succès.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        return $this->render('instruction/new.html.twig', [
            'instruction' => $instruction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_instruction_show', methods: ['GET', 'POST'])]
    public function show(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService,
        StatutRepository $statutRepo,
        EntiteRepository $entiteRepo
    ): Response {
        $currentUser = $this->getUser();

        // Comment form
        $commentaire = new Commentaire();
        $commentForm = $this->createForm(CommentaireType::class, $commentaire);
        $commentForm->handleRequest($request);

        if ($commentForm->isSubmitted() && $commentForm->isValid()) {
            $commentaire->setInstruction($instruction);
            $commentaire->setUtilisateur($currentUser);
            $em->persist($commentaire);

            $instructionService->logHistorique(
                $instruction,
                TypeEvenement::COMMENTAIRE_AJOUTE,
                $currentUser,
                null,
                null,
                'Nouveau commentaire ajouté à l\'instruction.'
            );

            $em->flush();
            $this->addFlash('success', 'Commentaire ajouté avec succès.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        $allEntites = $entiteRepo->findBy(['actif' => true], ['nom' => 'ASC']);
        $availableStatuts = $statutRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']);

        return $this->render('instruction/show.html.twig', [
            'instruction' => $instruction,
            'commentForm' => $commentForm,
            'allEntites' => $allEntites,
            'availableStatuts' => $availableStatuts,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_instruction_edit', methods: ['GET', 'POST'])]
    public function edit(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        $form = $this->createForm(InstructionType::class, $instruction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $instruction->setUpdatedAt(new \DateTime());
            
            $instructionService->logHistorique(
                $instruction,
                TypeEvenement::MODIFICATION,
                $currentUser,
                null,
                null,
                'Mise à jour des informations de l\'instruction.'
            );

            $em->flush();

            $this->addFlash('success', 'L\'instruction a été mise à jour avec succès.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        return $this->render('instruction/edit.html.twig', [
            'instruction' => $instruction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/changer-statut', name: 'app_instruction_change_statut', methods: ['POST'])]
    public function changeStatut(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        StatutRepository $statutRepo,
        InstructionService $instructionService
    ): Response {
        $statutId = $request->request->get('statut_id');
        $motif = $request->request->get('motif_cloture');
        $statut = $statutRepo->find($statutId);

        if ($statut) {
            $oldStatut = $instruction->getStatut();
            $instruction->setStatut($statut);
            $instruction->setUpdatedAt(new \DateTime());

            if ($statut->getCode() === Statut::CODE_CLOTUREE) {
                $instruction->setDateCloture(new \DateTime());
                $instruction->setMotifCloture($motif);
            }

            $instructionService->logHistorique(
                $instruction,
                TypeEvenement::CHANGEMENT_STATUT,
                $this->getUser(),
                $oldStatut,
                $statut,
                $motif ? sprintf('Statut modifié vers "%s". Motif : %s', $statut->getLibelle(), $motif) : sprintf('Statut modifié vers "%s".', $statut->getLibelle())
            );

            $em->flush();
            $this->addFlash('success', sprintf('Le statut de l\'instruction a été changé pour "%s".', $statut->getLibelle()));
        }

        return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
    }

    #[Route('/{id}/ajouter-contributeur', name: 'app_instruction_add_contributor', methods: ['POST'])]
    public function addContributor(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        EntiteRepository $entiteRepo,
        InstructionService $instructionService
    ): Response {
        $entiteId = $request->request->get('entite_id');
        $role = $request->request->get('role_entite', 'CONTRIBUTEUR');
        $commentaire = $request->request->get('commentaire');

        $entite = $entiteRepo->find($entiteId);
        if ($entite) {
            // Check if already exists
            foreach ($instruction->getInstructionEntites() as $ie) {
                if ($ie->getEntite()->getId() === $entite->getId()) {
                    $this->addFlash('warning', 'Cette entité est déjà enregistrée comme contributrice.');
                    return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
                }
            }

            $ie = new InstructionEntite();
            $ie->setInstruction($instruction);
            $ie->setEntite($entite);
            $ie->setRoleEntite($role);
            $ie->setCommentaire($commentaire);

            $em->persist($ie);
            $instruction->getInstructionEntites()->add($ie);

            $instructionService->logHistorique(
                $instruction,
                TypeEvenement::AFFECTATION,
                $this->getUser(),
                null,
                null,
                sprintf('Ajout de l\'entité contributrice "%s".', $entite->getNomComplet())
            );

            $em->flush();
            $this->addFlash('success', 'Entité contributrice ajoutée avec succès.');
        }

        return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
    }

    #[Route('/{id}/supprimer-contributeur/{entiteId}', name: 'app_instruction_remove_contributor', methods: ['POST'])]
    public function removeContributor(
        Instruction $instruction,
        int $entiteId,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        foreach ($instruction->getInstructionEntites() as $ie) {
            if ($ie->getEntite()->getId() === $entiteId) {
                $em->remove($ie);
                $instructionService->logHistorique(
                    $instruction,
                    TypeEvenement::AFFECTATION,
                    $this->getUser(),
                    null,
                    null,
                    sprintf('Retrait de l\'entité contributrice "%s".', $ie->getEntite()->getNomComplet())
                );
                $em->flush();
                $this->addFlash('success', 'Entité contributrice retirée.');
                break;
            }
        }

        return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
    }

    #[Route('/{id}/supprimer', name: 'app_instruction_delete', methods: ['POST'])]
    public function delete(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        if (!$this->isCsrfTokenValid('delete_instruction_' . $instruction->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité CSRF invalide.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        $motif = trim($request->request->get('motif_suppression', ''));
        if (empty($motif)) {
            $this->addFlash('danger', 'Le motif de suppression est obligatoire.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        $currentUser = $this->getUser();
        $instruction->setDeletedAt(new \DateTime());
        $instruction->setMotifSuppression($motif);
        $instruction->setUpdatedAt(new \DateTime());

        // Suppression logique en cascade sur les actions associées
        foreach ($instruction->getActions() as $action) {
            if (!$action->isDeleted()) {
                $action->setDeletedAt(new \DateTime());
                $action->setMotifSuppression('Suppression de l\'instruction parente (' . $instruction->getReference() . ')');
            }
        }

        $instructionService->logHistorique(
            $instruction,
            TypeEvenement::SUPPRESSION_LOGIQUE,
            $currentUser,
            null,
            null,
            sprintf('Suppression logique de l\'instruction. Motif : %s', $motif)
        );

        $em->flush();

        $this->addFlash('success', sprintf('L\'instruction %s a été supprimée logiquement avec succès.', $instruction->getReference()));
        return $this->redirectToRoute('app_instruction_index');
    }

    #[Route('/{id}/restaurer', name: 'app_instruction_restore', methods: ['POST'])]
    public function restore(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        if (!$this->isCsrfTokenValid('restore_instruction_' . $instruction->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité CSRF invalide.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        $currentUser = $this->getUser();
        $instruction->setDeletedAt(null);
        $instruction->setMotifSuppression(null);
        $instruction->setUpdatedAt(new \DateTime());

        // Restaurer les actions associées
        foreach ($instruction->getActions() as $action) {
            $action->setDeletedAt(null);
            $action->setMotifSuppression(null);
        }

        $instructionService->logHistorique(
            $instruction,
            TypeEvenement::RESTAURATION,
            $currentUser,
            null,
            null,
            'Restauration de l\'instruction depuis les archives/corbeille.'
        );

        $em->flush();

        $this->addFlash('success', sprintf('L\'instruction %s a été restaurée avec succès.', $instruction->getReference()));
        return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
    }

    #[Route('/{id}/commentaire/{commentId}/supprimer', name: 'app_instruction_comment_delete', methods: ['POST'])]
    public function deleteComment(
        Instruction $instruction,
        int $commentId,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid('delete_comment_' . $commentId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        foreach ($instruction->getCommentaires() as $comment) {
            if ($comment->getId() === $commentId) {
                // Auteur ou Admin uniquement
                if ($comment->getAuteur() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
                    $this->addFlash('danger', 'Vous n\'avez pas les droits pour supprimer ce commentaire.');
                    return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
                }

                $em->remove($comment);
                $em->flush();
                $this->addFlash('success', 'Commentaire supprimé.');
                break;
            }
        }

        return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
    }
}
