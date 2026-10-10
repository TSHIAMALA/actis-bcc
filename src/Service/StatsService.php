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
        return $this->getFilteredChartsData();
    }

    /**
     * KPIs filtrés par période et/ou entité
     */
    public function getFilteredKPIs(?string $periode = null, ?int $entiteId = null): array
    {
        $qbInstructions = $this->instructionRepo->createQueryBuilder('i')
            ->leftJoin('i.statut', 's')
            ->where('i.deletedAt IS NULL');

        $qbActions = $this->actionRepo->createQueryBuilder('a')
            ->leftJoin('a.statut', 's')
            ->leftJoin('a.instruction', 'i')
            ->where('a.deletedAt IS NULL');

        // Filtre période
        $dateFrom = $this->getDateFrom($periode);
        if ($dateFrom) {
            $qbInstructions->andWhere('i.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
            $qbActions->andWhere('a.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        // Filtre entité
        if ($entiteId) {
            $qbInstructions->leftJoin('i.entitePilote', 'ep')
                ->andWhere('ep.id = :entiteId')
                ->setParameter('entiteId', $entiteId);
            $qbActions->andWhere('a.entiteResponsable = :entiteId')
                ->setParameter('entiteId', $entiteId);
        }

        $allInstructions = $qbInstructions->getQuery()->getResult();
        $totalInstructions = count($allInstructions);

        $nouvelles = 0;
        $enCours = 0;
        $executees = 0;
        $cloturees = 0;
        $enRetard = 0;
        $totalAvancement = 0.0;

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

        $allActions = $qbActions->getQuery()->getResult();
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

    /**
     * Charts data filtrés par période et/ou entité
     */
    public function getFilteredChartsData(?string $periode = null, ?int $entiteId = null): array
    {
        $dateFrom = $this->getDateFrom($periode);

        // Requête instructions filtrée
        $qb = $this->instructionRepo->createQueryBuilder('i')
            ->select('s.libelle, s.code, s.couleur, COUNT(i.id) as total')
            ->leftJoin('i.statut', 's')
            ->where('i.deletedAt IS NULL')
            ->groupBy('s.id');

        if ($dateFrom) {
            $qb->andWhere('i.createdAt >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($entiteId) {
            $qb->leftJoin('i.entitePilote', 'ep')
                ->andWhere('ep.id = :entiteId')
                ->setParameter('entiteId', $entiteId);
        }

        $byStatut = $qb->getQuery()->getArrayResult();

        $totalStatut = array_sum(array_column($byStatut, 'total'));
        $colorsMap = [
            'EN_COURS' => '#0A2540',
            'EXECUTEE' => '#E5A93C',
            'CLOTUREE' => '#10B981',
            'EN_RETARD' => '#E74C3C',
            'SUSPENDUE' => '#38BDF8',
            'ANNULEE' => '#94A3B8',
            'BROUILLON' => '#64748B',
            'AFFECTEE' => '#6366F1',
            'EN_ATTENTE' => '#8B5CF6',
            'A_VERIFIER' => '#06B6D4',
        ];

        if ($totalStatut === 0) {
            $statutsList = [
                ['libelle' => 'En cours', 'total' => 0, 'percent' => 0, 'color' => '#0A2540'],
            ];
            $totalInstructionsCount = 0;
        } else {
            $statutsList = [];
            foreach ($byStatut as $s) {
                $pct = $totalStatut > 0 ? round(($s['total'] / $totalStatut) * 100) : 0;
                $color = $colorsMap[$s['code'] ?? ''] ?? ($s['couleur'] ?? '#3B82F6');
                $statutsList[] = [
                    'libelle' => $s['libelle'],
                    'total' => (int)$s['total'],
                    'percent' => $pct,
                    'color' => $color,
                ];
            }
            $totalInstructionsCount = $totalStatut;
        }

        // Monthly rates (static pour l'instant, mais on filtre le current_rate)
        $kpis = $this->getFilteredKPIs($periode, $entiteId);
        $currentRate = $kpis['taux_execution_actions'];

        return [
            'total_instructions_count' => $totalInstructionsCount,
            'statuts_detailed' => $statutsList,
            'statuts' => [
                'labels' => array_column($statutsList, 'libelle'),
                'data' => array_column($statutsList, 'total'),
                'colors' => array_column($statutsList, 'color'),
            ],
            'monthly_rates' => [
                'labels' => ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept'],
                'data' => [22, 28, 35, 45, 52, 58, 62, 67, (int)$currentRate],
                'current_rate' => $currentRate,
            ],
        ];
    }

    /**
     * Actions filtrées pour le tableau du dashboard
     */
    public function getFilteredPriorityActions(?string $periode = null, ?int $entiteId = null, int $limit = 10): array
    {
        $qb = $this->actionRepo->createQueryBuilder('a')
            ->leftJoin('a.instruction', 'i')->addSelect('i')
            ->leftJoin('a.statut', 's')->addSelect('s')
            ->leftJoin('a.priorite', 'p')->addSelect('p')
            ->leftJoin('a.entiteResponsable', 'e')->addSelect('e')
            ->leftJoin('a.responsable', 'r')->addSelect('r')
            ->where('a.deletedAt IS NULL')
            ->orderBy('a.dateEcheance', 'ASC')
            ->setMaxResults($limit);

        $dateFrom = $this->getDateFrom($periode);
        if ($dateFrom) {
            $qb->andWhere('a.createdAt >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }

        if ($entiteId) {
            $qb->andWhere('a.entiteResponsable = :entiteId')->setParameter('entiteId', $entiteId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Upcoming échéances filtrées
     */
    public function getFilteredUpcomingEcheances(?string $periode = null, ?int $entiteId = null, int $days = 14): array
    {
        $qb = $this->actionRepo->createQueryBuilder('a')
            ->leftJoin('a.instruction', 'i')->addSelect('i')
            ->leftJoin('a.statut', 's')->addSelect('s')
            ->leftJoin('a.entiteResponsable', 'e')->addSelect('e')
            ->where('a.deletedAt IS NULL')
            ->andWhere('a.dateEcheance IS NOT NULL')
            ->andWhere('a.dateEcheance <= :limitDate')
            ->andWhere('s.code NOT IN (:doneStatuts)')
            ->setParameter('limitDate', (new \DateTime())->modify("+{$days} days"))
            ->setParameter('doneStatuts', [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE])
            ->orderBy('a.dateEcheance', 'ASC')
            ->setMaxResults(5);

        if ($entiteId) {
            $qb->andWhere('a.entiteResponsable = :entiteId')->setParameter('entiteId', $entiteId);
        }

        return $qb->getQuery()->getResult();
    }

    private function getDateFrom(?string $periode): ?\DateTime
    {
        if (!$periode || $periode === 'all') {
            return null;
        }

        $now = new \DateTime();
        return match ($periode) {
            'month' => (clone $now)->modify('first day of this month')->setTime(0, 0),
            'quarter' => (clone $now)->modify('-3 months')->setTime(0, 0),
            'year' => (clone $now)->modify('first day of january this year')->setTime(0, 0),
            default => null,
        };
    }
}

