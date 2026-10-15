<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\Commentaire;
use App\Entity\ConseillerGouverneur;
use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\Priorite;
use App\Entity\Statut;
use App\Entity\TypeEvenement;
use App\Form\CommentaireType;
use App\Form\InstructionType;
use App\Form\JustificatifType;
use App\Form\RelanceType;
use App\Repository\ConseillerGouverneurRepository;
use App\Repository\EntiteRepository;
use App\Repository\InstructionRepository;
use App\Repository\PrioriteRepository;
use App\Repository\StatutRepository;
use App\Repository\TypeInstructionRepository;
use App\Repository\UtilisateurRepository;
use App\Service\AiActionExtractorService;
use App\Service\FileUploader;
use App\Service\InstructionDocumentStorage;
use App\Service\InstructionService;
use App\Service\RelanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
        ConseillerGouverneurRepository $conseillerRepo,
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
            'entites' => $entiteRepo->findActivesGroupees(),
            'utilisateurs' => $userRepo->findBy(['actif' => true], ['nom' => 'ASC']),
            'conseillers' => $conseillerRepo->findActifs(),
            'filters' => $filters,
        ]);
    }

    #[Route('/nouvelle', name: 'app_instruction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService,
        StatutRepository $statutRepo,
        InstructionDocumentStorage $documentStorage
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

            // Statut initial : « Affectée » dès qu'au moins un conseiller responsable est désigné
            $statutInitial = !$instruction->getResponsables()->isEmpty()
                ? $statutRepo->findOneBy(['code' => Statut::CODE_AFFECTEE]) 
                : $statutRepo->findOneBy(['code' => Statut::CODE_BROUILLON]);
            
            $instruction->setStatut($statutInitial ?? $statutRepo->findOneBy([]));

            $documentPdf = $form->get('documentPdf')->getData();
            if ($documentPdf) {
                $documentStorage->store($instruction, $documentPdf);
            }

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
        EntiteRepository $entiteRepo,
        PrioriteRepository $prioriteRepo,
        UtilisateurRepository $userRepo,
        ConseillerGouverneurRepository $conseillerRepo
    ): Response {
        /** @var \App\Entity\Utilisateur|null $currentUser */
        $currentUser = $this->getUser();
        $monProfilConseiller = $currentUser ? $conseillerRepo->findByUtilisateur($currentUser) : null;

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

        $allEntites = $entiteRepo->findActivesGroupees();
        $availableStatuts = $statutRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']);
        $allPriorites = $prioriteRepo->findBy(['actif' => true], ['ordreAffichage' => 'ASC']);
        $allUtilisateurs = $userRepo->findBy(['actif' => true], ['nom' => 'ASC']);
        $allConseillers = $conseillerRepo->findActifs();

        return $this->render('instruction/show.html.twig', [
            'instruction' => $instruction,
            'commentForm' => $commentForm,
            'allEntites' => $allEntites,
            'availableStatuts' => $availableStatuts,
            'allPriorites' => $allPriorites,
            'allUtilisateurs' => $allUtilisateurs,
            'allConseillers' => $allConseillers,
            'monProfilConseiller' => $monProfilConseiller,
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_instruction_edit', methods: ['GET', 'POST'])]
    public function edit(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService,
        InstructionDocumentStorage $documentStorage
    ): Response {
        $responsablesAvant = $this->nomsResponsables($instruction);
        $form = $this->createForm(InstructionType::class, $instruction, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $instruction->setUpdatedAt(new \DateTime());

            $description = 'Mise à jour des informations de l\'instruction.';
            $responsablesApres = $this->nomsResponsables($instruction);
            if ($responsablesApres !== $responsablesAvant) {
                $description .= ' Responsables : ' . ($responsablesApres ?: 'aucun') . '.';
            }
            $documentPdf = $form->get('documentPdf')->getData();
            if ($documentPdf) {
                $documentStorage->store($instruction, $documentPdf);
                $description .= sprintf(' Document officiel joint : %s.', $instruction->getDocumentNomOriginal());
            } elseif ($form->get('supprimerDocument')->getData() && $instruction->hasDocument()) {
                $documentStorage->remove($instruction);
                $description .= ' Document officiel supprimé.';
            }

            $instructionService->logHistorique(
                $instruction,
                TypeEvenement::MODIFICATION,
                $currentUser,
                null,
                null,
                $description
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

    /**
     * Affiche le PDF officiel dans le navigateur (lecteur intégré), ou le télécharge avec ?telecharger=1.
     */
    #[Route('/{id}/document', name: 'app_instruction_document', methods: ['GET'])]
    public function document(
        Instruction $instruction,
        Request $request,
        InstructionDocumentStorage $documentStorage
    ): Response {
        $path = $documentStorage->getPath($instruction);
        if ($path === null || !is_file($path)) {
            throw $this->createNotFoundException('Aucun document officiel n\'est joint à cette instruction.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            $request->query->getBoolean('telecharger') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $instruction->getDocumentNomOriginal() ?: $instruction->getReference() . '.pdf',
            $instruction->getDocumentFichier()
        );

        return $response;
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

    #[Route('/{id}/ai-extract', name: 'app_instruction_ai_extract', methods: ['POST'])]
    public function aiExtract(
        Instruction $instruction,
        Request $request,
        AiActionExtractorService $aiExtractor
    ): JsonResponse {
        try {
            $uploadedFile = $request->files->get('document');
            $customPrompt = $request->request->get('custom_prompt');

            $resultat = $aiExtractor->extractActions($instruction, $uploadedFile, $customPrompt);

            return new JsonResponse([
                'success' => true,
                'actions' => $resultat['actions'],
                'conseillers' => $resultat['conseillers'],
                'count' => count($resultat['actions'])
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    #[Route('/{id}/actions/batch-create', name: 'app_instruction_actions_batch_create', methods: ['POST'])]
    public function batchCreateActions(
        Instruction $instruction,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService,
        EntiteRepository $entiteRepo,
        PrioriteRepository $prioriteRepo,
        StatutRepository $statutRepo,
        ConseillerGouverneurRepository $conseillerRepo
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            $rawActions = $data['actions'] ?? [];

            // Conseillers responsables retenus dans la fenêtre IA (proposés par l'IA ou cochés à la main)
            $responsablesAjoutes = [];
            foreach (array_unique(array_map('intval', $data['responsables'] ?? [])) as $conseillerId) {
                $conseiller = $conseillerRepo->find($conseillerId);
                if ($conseiller && $conseiller->isActif() && !$instruction->getResponsables()->contains($conseiller)) {
                    $instruction->addResponsable($conseiller);
                    $responsablesAjoutes[] = $conseiller->getNom();
                }
            }

            if (empty($rawActions)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Aucune action reçue pour l\'enregistrement.'
                ], 400);
            }

            $currentUser = $this->getUser();
            $defaultStatut = $statutRepo->findOneBy(['code' => Statut::CODE_AFFECTEE])
                ?? $statutRepo->findOneBy(['code' => Statut::CODE_BROUILLON])
                ?? $statutRepo->findOneBy([]);

            $createdCount = 0;
            $currentActionCount = $instruction->getActions()->count();

            foreach ($rawActions as $item) {
                $libelle = trim($item['libelle'] ?? '');
                if (empty($libelle)) {
                    continue;
                }

                $action = new Action();
                $action->setInstruction($instruction);
                $action->setLibelle($libelle);
                $action->setResultatAttendu(!empty($item['resultat_attendu']) ? trim($item['resultat_attendu']) : null);
                
                // Entité responsable
                $entiteId = !empty($item['entite_id']) ? (int)$item['entite_id'] : null;
                $entite = $entiteId ? $entiteRepo->find($entiteId) : null;
                if (!$entite) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => sprintf('Choisissez l\'entité responsable de l\'action « %s ».', $libelle),
                    ], 400);
                }
                $action->setEntiteResponsable($entite);

                // Priorité
                $prioriteId = !empty($item['priorite_id']) ? (int)$item['priorite_id'] : null;
                $priorite = $prioriteId ? $prioriteRepo->find($prioriteId) : null;
                $action->setPriorite($priorite ?? $instruction->getPriorite());

                // Date d'échéance
                if (!empty($item['date_echeance'])) {
                    try {
                        $action->setDateEcheance(new \DateTime($item['date_echeance']));
                    } catch (\Throwable) {
                        $action->setDateEcheance($instruction->getDateEcheance());
                    }
                } else {
                    $action->setDateEcheance($instruction->getDateEcheance());
                }

                $action->setDateDebut(new \DateTime());
                $action->setStatut($defaultStatut);
                $action->setCreatedBy($currentUser);
                $action->setTauxAvancement('0.00');

                // Référence unique
                $currentActionCount++;
                $action->setReference(sprintf('%s-ACT%02d', $instruction->getReference(), $currentActionCount));

                $em->persist($action);
                $createdCount++;
            }

            if ($createdCount > 0) {
                $instructionService->logHistorique(
                    $instruction,
                    TypeEvenement::CREATION,
                    $currentUser,
                    null,
                    null,
                    sprintf('Génération et création en bloc de %d action(s) opérationnelle(s) par IA.', $createdCount)
                );

                $em->flush();
                $this->addFlash('success', sprintf('%d action(s) opérationnelle(s) ont été créées avec succès.', $createdCount));
            }

            if ($responsablesAjoutes) {
                // Une instruction en brouillon devient « Affectée » dès qu'elle a un responsable
                if ($instruction->getStatut()?->getCode() === Statut::CODE_BROUILLON) {
                    $instruction->setStatut($statutRepo->findOneBy(['code' => Statut::CODE_AFFECTEE]) ?? $instruction->getStatut());
                }
                $instructionService->logHistorique(
                    $instruction,
                    TypeEvenement::AFFECTATION,
                    $currentUser,
                    null,
                    $instruction->getStatut(),
                    'Conseiller(s) responsable(s) désigné(s) : ' . implode(', ', $responsablesAjoutes) . '.'
                );
                $em->flush();
                $this->addFlash('success', 'Responsable(s) désigné(s) : ' . implode(', ', $responsablesAjoutes) . '.');
            }

            return new JsonResponse([
                'success' => true,
                'count' => $createdCount,
                'message' => sprintf('%d action(s) créée(s) avec succès.', $createdCount)
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la création : ' . $e->getMessage()
            ], 500);
        }
    }

    private function nomsResponsables(Instruction $instruction): string
    {
        return implode(', ', $instruction->getResponsables()->map(fn (ConseillerGouverneur $c) => $c->getNom())->toArray());
    }
}
