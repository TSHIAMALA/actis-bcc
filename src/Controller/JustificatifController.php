<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\Instruction;
use App\Entity\Justificatif;
use App\Entity\TypeEvenement;
use App\Form\JustificatifType;
use App\Repository\ActionRepository;
use App\Repository\InstructionRepository;
use App\Repository\JustificatifRepository;
use App\Service\FileUploader;
use App\Service\InstructionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/justificatifs')]
class JustificatifController extends AbstractController
{
    #[Route('/depot', name: 'app_justificatif_depot', methods: ['GET', 'POST'])]
    public function depot(
        Request $request,
        EntityManagerInterface $em,
        InstructionRepository $instructionRepo,
        ActionRepository $actionRepo,
        FileUploader $uploader,
        InstructionService $instructionService
    ): Response {
        $instructionId = $request->query->get('instruction_id');
        $actionId = $request->query->get('action_id');

        $instruction = $instructionId ? $instructionRepo->find($instructionId) : null;
        $action = $actionId ? $actionRepo->find($actionId) : null;

        if (!$instruction && !$action) {
            throw $this->createNotFoundException('Cible du justificatif non spécifiée.');
        }

        $form = $this->createForm(JustificatifType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('fichier')->getData();
            $description = $form->get('description')->getData();

            if ($file) {
                $uploadResult = $uploader->upload($file);
                $currentUser = $this->getUser();

                $justificatif = new Justificatif();
                $justificatif->setNomFichier($uploadResult['originalName']);
                $justificatif->setCheminFichier($uploadResult['path']);
                $justificatif->setTypeMime($uploadResult['mimeType']);
                $justificatif->setTailleOctets($uploadResult['size']);
                $justificatif->setDescription($description);
                $justificatif->setDeposePar($currentUser);
                $justificatif->setDateDepot(new \DateTime());

                if ($action) {
                    $justificatif->setAction($action);
                    $justificatif->setInstruction($action->getInstruction());
                    $instructionService->logHistorique(
                        $action,
                        TypeEvenement::JUSTIFICATIF_AJOUTE,
                        $currentUser,
                        null,
                        null,
                        sprintf('Justificatif déposé sur l\'action : %s', $uploadResult['originalName'])
                    );
                } elseif ($instruction) {
                    $justificatif->setInstruction($instruction);
                    $instructionService->logHistorique(
                        $instruction,
                        TypeEvenement::JUSTIFICATIF_AJOUTE,
                        $currentUser,
                        null,
                        null,
                        sprintf('Pièce jointe / justificatif déposé : %s', $uploadResult['originalName'])
                    );
                }

                $em->persist($justificatif);
                $em->flush();

                $this->addFlash('success', 'Le justificatif a été téléversé et enregistré avec succès.');

                if ($action) {
                    return $this->redirectToRoute('app_action_show', ['id' => $action->getId()]);
                }
                return $this->redirectToRoute('app_instruction_show', ['id' => $instruction->getId()]);
            }
        }

        return $this->render('justificatif/depot.html.twig', [
            'form' => $form,
            'instruction' => $instruction,
            'action' => $action,
        ]);
    }

    #[Route('/{id}/telecharger', name: 'app_justificatif_download', methods: ['GET'])]
    public function download(Justificatif $justificatif): Response
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $filePath = $projectDir . '/public/' . $justificatif->getCheminFichier();

        if (!file_exists($filePath)) {
            $this->addFlash('danger', 'Le fichier demandé est introuvable sur le serveur.');
            return $this->redirectToRoute('app_dashboard');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $justificatif->getNomFichier()
        );

        return $response;
    }

    #[Route('/{id}/supprimer', name: 'app_justificatif_delete', methods: ['POST'])]
    public function delete(
        Justificatif $justificatif,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $instructionId = $justificatif->getInstruction()?->getId();
        $actionId = $justificatif->getAction()?->getId();

        $projectDir = $this->getParameter('kernel.project_dir');
        $filePath = $projectDir . '/public/' . $justificatif->getCheminFichier();
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $em->remove($justificatif);
        $em->flush();

        $this->addFlash('success', 'Le justificatif a été supprimé.');

        if ($actionId) {
            return $this->redirectToRoute('app_action_show', ['id' => $actionId]);
        }
        if ($instructionId) {
            return $this->redirectToRoute('app_instruction_show', ['id' => $instructionId]);
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
