<?php

namespace App\Service;

use App\Entity\Action;
use App\Entity\Instruction;
use App\Entity\Statut;
use App\Repository\ActionRepository;
use App\Repository\EntiteRepository;
use App\Repository\InstructionRepository;
use App\Repository\ProrogationRepository;
use App\Repository\RelanceRepository;

class StatsService
{
    public function __construct(
        private InstructionRepository $instructionRepo,
        private ActionRepository $actionRepo,
        private EntiteRepository $entiteRepo,
        private ProrogationRepository $prorogationRepo,
        private RelanceRepository $relanceRepo
    ) {
    }

    public function getDashboardKPIs(): array
    {
        $allInstructions = $this->instructionRepo->findAll();
        $totalInstructions = count($allInstructions);

        $nouvelles = 0;
        $enCours = 0;
        $executees = 0;
        $cloturees = 0;
        $enRetard = 0;
        $totalAvancement = 0.0;

        $today = new \DateTime('today');

        foreach ($allInstructions as $inst) {
            $code = $inst->getStatut()?->getCode();
            $totalAvancement += (float) $inst->getTauxAvancement();

            if (in_array($code, [Statut::CODE_BROUILLON, Statut::CODE_AFFECTEE])) {
                $nouvelles++;
            } elseif (in_array($code, [Statut::CODE_EN_COURS, Statut::CODE_EN_ATTENTE, Statut::CODE_A_VERIFIER])) {
                $enCours++;
            } elseif ($code === Statut::CODE_EXECUTEE) {
                $executees++;
            } elseif ($code === Statut::CODE_CLOTUREE) {
                $cloturees++;
            }

            if ($inst->isEnRetard()) {
                $enRetard++;
            }
        }

        $allActions = $this->actionRepo->findAll();
        $totalActions = count($allActions);
        $actionsExecutees = 0;
        $actionsEnRetard = 0;
        $actionsEnCours = 0;

        foreach ($allActions as $act) {
            $code = $act->getStatut()?->getCode();
            if (in_array($code, [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE])) {
                $actionsExecutees++;
            } elseif ($code === Statut::CODE_EN_COURS) {
                $actionsEnCours++;
            }

            if ($act->isEnRetard()) {
                $actionsEnRetard++;
            }
        }

        $avgGlobalAvancement = $totalInstructions > 0 ? round($totalAvancement / $totalInstructions, 1) : 0;
        $tauxExecutionActions = $totalActions > 0 ? round(($actionsExecutees / $totalActions) * 100, 1) : 0;

        return [
            'total_instructions' => $totalInstructions,
            'nouvelles' => $nouvelles,
            'en_cours' => $enCours,
            'executees' => $executees,
            'cloturees' => $cloturees,
            'finalisees' => $executees + $cloturees,
            'en_retard' => $enRetard,
            'taux_avancement_moyen' => $avgGlobalAvancement,
            'total_actions' => $totalActions,
            'actions_executees' => $actionsExecutees,
            'actions_en_cours' => $actionsEnCours,
            'actions_en_retard' => $actionsEnRetard,
            'taux_execution_actions' => $tauxExecutionActions,
            'prorogations_en_attente' => count($this->prorogationRepo->findPending()),
            'total_relances' => $this->relanceRepo->count([]),
        ];
    }

    public function getChartsData(): array
    {
        $byStatut = $this->instructionRepo->getCountsByStatut();
        $byPriorite = $this->instructionRepo->getCountsByPriorite();
        $byType = $this->instructionRepo->getCountsByType();

        return [
            'statuts' => [
                'labels' => array_column($byStatut, 'libelle'),
                'data' => array_map('intval', array_column($byStatut, 'total')),
                'colors' => ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#6B7280', '#0EA5E9'],
            ],
            'priorites' => [
                'labels' => array_column($byPriorite, 'libelle'),
                'data' => array_map('intval', array_column($byPriorite, 'total')),
                'colors' => ['#EF4444', '#F97316', '#3B82F6', '#9CA3AF'],
            ],
            'types' => [
                'labels' => array_column($byType, 'libelle'),
                'data' => array_map('intval', array_column($byType, 'total')),
                'colors' => ['#1E3E62', '#D4AF37', '#0284C7', '#059669', '#7C3AED'],
            ]
        ];
    }
}
