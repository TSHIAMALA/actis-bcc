<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\Instruction;
use App\Entity\Relance;
use App\Form\RelanceType;
use App\Repository\ActionRepository;
use App\Repository\InstructionRepository;
use App\Repository\RelanceRepository;
use App\Service\RelanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/relances')]
class RelanceController extends AbstractController
{
    #[Route('', name: 'app_relance_index', methods: ['GET'])]
    public function index(RelanceRepository $relanceRepo): Response
    {
        $relances = $relanceRepo->findBy([], ['datePlanifiee' => 'DESC']);

        return $this->render('relance/index.html.twig', [
            'relances' => $relances,
        ]);
    }

    #[Route('/nouvelle', name: 'app_relance_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        InstructionRepository $instructionRepo,
        ActionRepository $actionRepo,
        RelanceService $relanceService
    ): Response {
        $instructionId = $request->query->get('instruction_id');
        $actionId = $request->query->get('action_id');

        $instruction = $instructionId ? $instructionRepo->find($instructionId) : null;
        $action = $actionId ? $actionRepo->find($actionId) : null;

        if (!$instruction && !$action) {
            throw $this->createNotFoundException('Instruction ou Action introuvable pour la relance.');
        }

        $target = $action ?? $instruction;
        $defaultDest = $action ? $action->getResponsable() : $instruction->getResponsable();

        $relance = new Relance();
        if ($defaultDest) {
            $relance->setDestinataireUtilisateur($defaultDest);
            $relance->setDestinataireEmail($defaultDest->getEmail());
        }

        $defaultSubject = sprintf('[ACTIS-BCC] Relance : %s', $action ? $action->getLibelle() : $instruction->getObjet());
        $defaultMsg = sprintf(
            "Bonjour,\n\nNous vous prions de bien vouloir mettre à jour l'état d'avancement du dossier : %s (Échéance : %s).\n\nCordialement,\nCellule de suivi des instructions du Gouverneur",
            $action ? $action->getLibelle() : $instruction->getObjet(),
            ($action ? $action->getDateEcheance() : $instruction->getDateEcheance())?->format('d/m/Y') ?? 'Non définie'
        );

        $relance->setObjet($defaultSubject);
        $relance->setMessage($defaultMsg);

        $form = $this->createForm(RelanceType::class, $relance);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $destinataire = $relance->getDestinataireUtilisateur();
            $relanceService->generateManualRelance(
                $target,
                $destinataire,
                $relance->getObjet(),
                $relance->getMessage(),
                $this->getUser()
            );

            $this->addFlash('success', 'La relance a été transmise avec succès au destinataire.');

            if ($action) {
                return $this->redirectToRoute('app_action_show', ['id' => $action->getId()]);
            }
            return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
        }

        return $this->render('relance/new.html.twig', [
            'form' => $form,
            'instruction' => $instruction,
            'action' => $action,
        ]);
    }

    #[Route('/lancer-moteur', name: 'app_relance_trigger_engine', methods: ['POST'])]
    public function triggerEngine(RelanceService $relanceService): Response
    {
        $alerts = $relanceService->checkAndGenerateAutomatedAlerts();

        if (count($alerts) > 0) {
            $this->addFlash('success', sprintf('Le moteur d\'échéances a généré et envoyé %d alertes/relances automatiques.', count($alerts)));
        } else {
            $this->addFlash('info', 'Toutes les échéances sont à jour. Aucune nouvelle alerte générée.');
        }

        return $this->redirectToRoute('app_relance_index');
    }
}
