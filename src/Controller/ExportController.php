<?php

namespace App\Controller;

use App\Entity\Instruction;
use App\Repository\InstructionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/export')]
class ExportController extends AbstractController
{
    #[Route('/instruction/{id}/fiche', name: 'app_export_instruction_fiche', methods: ['GET'])]
    public function fiche(Instruction $instruction): Response
    {
        return $this->render('export/fiche.html.twig', [
            'instruction' => $instruction,
        ]);
    }

    #[Route('/instructions/csv', name: 'app_export_instructions_csv', methods: ['GET'])]
    public function exportCsv(InstructionRepository $instructionRepo): StreamedResponse
    {
        $instructions = $instructionRepo->findAll();

        $response = new StreamedResponse(function () use ($instructions) {
            $handle = fopen('php://output', 'w+');
            // Add UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'Référence',
                'Type',
                'Objet',
                'Statut',
                'Priorité',
                'Entité Pilote',
                'Responsable',
                'Date Instruction',
                'Date Échéance',
                'Avancement (%)',
                'Nombre Actions',
                'En Retard'
            ], ';');

            foreach ($instructions as $inst) {
                fputcsv($handle, [
                    $inst->getReference(),
                    $inst->getTypeInstruction()?->getLibelle() ?? '',
                    $inst->getObjet(),
                    $inst->getStatut()?->getLibelle() ?? '',
                    $inst->getPriorite()?->getLibelle() ?? '',
                    $inst->getEntitePilote()?->getNomComplet() ?? '',
                    $inst->getResponsable()?->getNomComplet() ?? 'Non désigné',
                    $inst->getDateInstruction()?->format('d/m/Y') ?? '',
                    $inst->getDateEcheance()?->format('d/m/Y') ?? '',
                    $inst->getTauxAvancement() . '%',
                    $inst->getActions()->count(),
                    $inst->isEnRetard() ? 'OUI' : 'NON'
                ], ';');
            }

            fclose($handle);
        });

        $filename = 'instructions_actis_bcc_' . (new \DateTime())->format('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
}
