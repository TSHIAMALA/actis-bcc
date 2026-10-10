<?php

namespace App\Controller;

use App\Repository\ActionRepository;
use App\Repository\EntiteRepository;
use App\Repository\InstructionRepository;
use App\Service\RelanceService;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        StatsService $statsService,
        InstructionRepository $instructionRepo,
        ActionRepository $actionRepo,
        EntiteRepository $entiteRepo,
        RelanceService $relanceService,
        Request $request
    ): Response {
        $kpis = $statsService->getDashboardKPIs();
        $chartsData = $statsService->getChartsData();
        $performanceDirections = $entiteRepo->getPerformanceDirections();
        $allEntites = $entiteRepo->findBy(['actif' => true], ['nom' => 'ASC']);
        $recentInstructions = $instructionRepo->getRecent(5);
        $upcomingEcheances = $actionRepo->getUpcomingEcheances(14);

        // Actions prioritaires pour le tableau principal
        $priorityActions = $actionRepo->createQueryBuilder('a')
            ->leftJoin('a.instruction', 'i')->addSelect('i')
            ->leftJoin('a.statut', 's')->addSelect('s')
            ->leftJoin('a.priorite', 'p')->addSelect('p')
            ->leftJoin('a.entiteResponsable', 'e')->addSelect('e')
            ->leftJoin('a.responsable', 'r')->addSelect('r')
            ->where('a.deletedAt IS NULL')
            ->orderBy('a.dateEcheance', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Actions user specific
        $user = $this->getUser();
        $myPendingActions = [];
        if ($user) {
            $myPendingActions = $actionRepo->searchAndFilter(['my_actions' => '1'], $user);
        }

        return $this->render('dashboard/index.html.twig', [
            'kpis' => $kpis,
            'charts' => $chartsData,
            'allEntites' => $allEntites,
            'performanceDirections' => $performanceDirections,
            'recentInstructions' => $recentInstructions,
            'upcomingEcheances' => $upcomingEcheances,
            'priorityActions' => $priorityActions,
            'myPendingActions' => array_slice($myPendingActions, 0, 5),
        ]);
    }

    #[Route('/api/dashboard', name: 'app_dashboard_api', methods: ['GET'])]
    public function dashboardApi(
        Request $request,
        StatsService $statsService
    ): JsonResponse {
        $periode = $request->query->get('periode', 'all');
        $entiteId = $request->query->getInt('entite_id') ?: null;

        // KPIs filtrés
        $kpis = $statsService->getFilteredKPIs($periode, $entiteId);

        // Charts filtrés
        $charts = $statsService->getFilteredChartsData($periode, $entiteId);

        // Actions prioritaires filtrées
        $priorityActions = $statsService->getFilteredPriorityActions($periode, $entiteId, 5);
        $actionsData = [];
        foreach ($priorityActions as $act) {
            $isEnRetard = $act->isEnRetard();
            $statutCode = $act->getStatut()?->getCode();
            $prioriteCode = $act->getPriorite()?->getCode() ?? null;

            // Determine status display
            if ($isEnRetard) {
                $statutDisplay = ['label' => 'En retard', 'class' => 'badge-soft-red'];
            } elseif ($statutCode === 'EXECUTEE') {
                $statutDisplay = ['label' => 'Réalisée', 'class' => 'badge-soft-green'];
            } elseif ($statutCode === 'AFFECTEE') {
                $statutDisplay = ['label' => 'À démarrer', 'class' => 'badge-soft-gray'];
            } else {
                $statutDisplay = ['label' => 'En cours', 'class' => 'badge-soft-blue'];
            }

            // Priority display
            if (in_array($prioriteCode, ['TRES_HAUTE', 'HAUTE'])) {
                $prioriteDisplay = ['label' => 'Haute', 'class' => 'badge-soft-pink'];
            } elseif ($prioriteCode === 'MOYENNE') {
                $prioriteDisplay = ['label' => 'Moyenne', 'class' => 'badge-soft-yellow'];
            } else {
                $prioriteDisplay = ['label' => 'Normale', 'class' => 'badge-soft-gray'];
            }

            $actionsData[] = [
                'id' => $act->getId(),
                'libelle' => $act->getLibelle(),
                'reference' => $act->getReference(),
                'instruction_id' => $act->getInstruction()?->getId(),
                'instruction_reference' => $act->getInstruction()?->getReference(),
                'instruction_objet' => mb_substr($act->getInstruction()?->getObjet() ?? '', 0, 32) . (mb_strlen($act->getInstruction()?->getObjet() ?? '') > 32 ? '...' : ''),
                'entite_code' => $act->getEntiteResponsable()?->getCode() ?? 'BCC',
                'entite_nom' => mb_substr($act->getEntiteResponsable()?->getNom() ?? 'Direction Générale', 0, 24),
                'date_echeance' => $act->getDateEcheance()?->format('d/m/Y') ?? '-',
                'is_en_retard' => $isEnRetard,
                'statut' => $statutDisplay,
                'priorite' => $prioriteDisplay,
            ];
        }

        // Upcoming échéances filtrées
        $upcomingEcheances = $statsService->getFilteredUpcomingEcheances($periode, $entiteId);
        $echeancesData = [];
        foreach ($upcomingEcheances as $ech) {
            $today = new \DateTime('today');
            $daysUntil = $ech->getDateEcheance() ? (int) $today->diff($ech->getDateEcheance())->format('%r%a') : 999;
            $isRetard = $daysUntil < 0;
            $isProche = !$isRetard && $daysUntil <= 3;

            $echeancesData[] = [
                'id' => $ech->getId(),
                'libelle' => mb_substr($ech->getLibelle(), 0, 35),
                'entite_code' => $ech->getEntiteResponsable()?->getCode() ?? '',
                'date_echeance' => $ech->getDateEcheance()?->format('d/m/Y') ?? '',
                'date_day' => $ech->getDateEcheance()?->format('d') ?? '',
                'date_month' => $this->getShortMonthFr($ech->getDateEcheance()),
                'is_retard' => $isRetard,
                'is_proche' => $isProche,
                'date_class' => $isRetard ? 'date-red' : ($isProche ? 'date-amber' : 'date-blue'),
                'status_label' => $isRetard ? 'En retard' : ($isProche ? 'Échéance proche' : 'À venir'),
                'status_class' => $isRetard ? 'badge-soft-red' : ($isProche ? 'badge-soft-yellow' : 'badge-soft-blue'),
            ];
        }

        // Filtre label for UI feedback
        $filterLabel = 'Tous';
        if ($periode === 'month') $filterLabel = 'Ce mois';
        elseif ($periode === 'quarter') $filterLabel = 'Ce trimestre';
        elseif ($periode === 'year') $filterLabel = 'Cette année';

        return new JsonResponse([
            'success' => true,
            'filter_label' => $filterLabel,
            'kpis' => $kpis,
            'charts' => $charts,
            'actions' => $actionsData,
            'echeances' => $echeancesData,
        ]);
    }

    private function getShortMonthFr(?\DateTimeInterface $date): string
    {
        if (!$date) return '';
        $months = [1 => 'Janv.', 'Fév.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
        return $months[(int)$date->format('n')] ?? '';
    }
}
