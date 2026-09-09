<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\Instruction;
use App\Entity\Prorogation;
use App\Entity\StatutProrogation;
use App\Entity\TypeEvenement;
use App\Form\ProrogationDecisionType;
use App\Form\ProrogationType;
use App\Repository\ActionRepository;
use App\Repository\InstructionRepository;
use App\Repository\ProrogationRepository;
use App\Repository\StatutProrogationRepository;
use App\Service\InstructionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/prorogations')]
class ProrogationController extends AbstractController
{
    #[Route('', name: 'app_prorogation_index', methods: ['GET'])]
    public function index(ProrogationRepository $prorogationRepo): Response
    {
        $pending = $prorogationRepo->findPending();
        $all = $prorogationRepo->findBy([], ['dateDemande' => 'DESC']);

        return $this->render('prorogation/index.html.twig', [
            'pending' => $pending,
            'all' => $all,
        ]);
    }

    #[Route('/demande', name: 'app_prorogation_demande', methods: ['GET', 'POST'])]
    public function demande(
        Request $request,
        EntityManagerInterface $em,
        InstructionRepository $instructionRepo,
        ActionRepository $actionRepo,
        StatutProrogationRepository $statutProrogationRepo,
        InstructionService $instructionService
    ): Response {
        $instructionId = $request->query->get('instruction_id');
        $actionId = $request->query->get('action_id');

        $instruction = $instructionId ? $instructionRepo->find($instructionId) : null;
        $action = $actionId ? $actionRepo->find($actionId) : null;

        if (!$instruction && !$action) {
            throw $this->createNotFoundException('Cible de la prorogation non trouvée.');
        }

        $prorogation = new Prorogation();
        $prorogation->setDateDemande(new \DateTime());
        $prorogation->setDemandePar($this->getUser());

        $statutAttente = $statutProrogationRepo->findOneBy(['code' => StatutProrogation::CODE_EN_ATTENTE]) 
            ?? $statutProrogationRepo->findOneBy([]) 
            ?? new StatutProrogation();
        $prorogation->setStatutProrogation($statutAttente);

        if ($action) {
            $prorogation->setAction($action);
            $prorogation->setInstruction($action->getInstruction());
            $prorogation->setAncienneEcheance($action->getDateEcheance() ?? new \DateTime());
        } elseif ($instruction) {
            $prorogation->setInstruction($instruction);
            $prorogation->setAncienneEcheance($instruction->getDateEcheance() ?? new \DateTime());
        }

        $form = $this->createForm(ProrogationType::class, $prorogation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $em->persist($prorogation);

            $desc = sprintf(
                'Demande de prorogation soumise : passage de l\'échéance du %s au %s. Motif : %s',
                $prorogation->getAncienneEcheance()?->format('d/m/Y'),
                $prorogation->getNouvelleEcheance()?->format('d/m/Y'),
                $prorogation->getMotif()
            );

            if ($action) {
                $instructionService->logHistorique(
                    $action,
                    TypeEvenement::DEMANDE_PROROGATION,
                    $currentUser,
                    null,
                    null,
                    $desc
                );
            } else {
                $instructionService->logHistorique(
                    $instruction,
                    TypeEvenement::DEMANDE_PROROGATION,
                    $currentUser,
                    null,
                    null,
                    $desc
                );
            }

            $em->flush();

            $this->addFlash('success', 'Votre demande de prorogation d\'échéance a été transmise.');

            if ($action) {
                return $this->redirectToRoute('app_action_show', ['id' => $action->getId()]);
            }
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        return $this->render('prorogation/demande.html.twig', [
            'form' => $form,
            'prorogation' => $prorogation,
            'instruction' => $instruction,
            'action' => $action,
        ]);
    }

    #[Route('/{id}/decision', name: 'app_prorogation_decision', methods: ['GET', 'POST'])]
    public function decision(
        Prorogation $prorogation,
        Request $request,
        EntityManagerInterface $em,
        InstructionService $instructionService
    ): Response {
        $form = $this->createForm(ProrogationDecisionType::class, $prorogation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->getUser();
            $prorogation->setValidePar($currentUser);
            $prorogation->setDateValidation(new \DateTime());

            $isAccepted = $prorogation->getStatutProrogation()?->getCode() === StatutProrogation::CODE_ACCEPTEE;

            if ($isAccepted) {
                if ($prorogation->getAction()) {
                    $prorogation->getAction()->setDateEcheance($prorogation->getNouvelleEcheance());
                    $prorogation->getAction()->setUpdatedAt(new \DateTime());
                } elseif ($prorogation->getInstruction()) {
                    $prorogation->getInstruction()->setDateEcheance($prorogation->getNouvelleEcheance());
                    $prorogation->getInstruction()->setUpdatedAt(new \DateTime());
                }
            }

            $target = $prorogation->getAction() ?? $prorogation->getInstruction();
            if ($target) {
                $instructionService->logHistorique(
                    $target,
                    TypeEvenement::VALIDATION_PROROGATION,
                    $currentUser,
                    null,
                    null,
                    sprintf(
                        'Décision de prorogation : %s. %s',
                        $prorogation->getStatutProrogation()?->getLibelle(),
                        $prorogation->getCommentaireValidation() ? 'Commentaire : ' . $prorogation->getCommentaireValidation() : ''
                    )
                );
            }

            $em->flush();

            $this->addFlash('success', 'La décision de prorogation a été enregistrée avec succès.');
            return $this->redirectToRoute('app_prorogation_index');
        }

        return $this->render('prorogation/decision.html.twig', [
            'form' => $form,
            'prorogation' => $prorogation,
        ]);
    }
}
