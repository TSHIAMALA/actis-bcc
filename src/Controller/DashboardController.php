<?php

namespace App\Controller;

use App\Repository\ActionRepository;
use App\Repository\EntiteRepository;
use App\Repository\InstructionRepository;
use App\Service\RelanceService;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        $recentInstructions = $instructionRepo->getRecent(5);
        $upcomingEcheances = $actionRepo->getUpcomingEcheances(7);

        // Actions user specific
        $user = $this->getUser();
        $myPendingActions = [];
        if ($user) {
            $myPendingActions = $actionRepo->searchAndFilter(['my_actions' => '1'], $user);
        }

        return $this->render('dashboard/index.html.twig', [
            'kpis' => $kpis,
            'charts' => $chartsData,
            'performanceDirections' => $performanceDirections,
            'recentInstructions' => $recentInstructions,
            'upcomingEcheances' => $upcomingEcheances,
            'myPendingActions' => array_slice($myPendingActions, 0, 5),
        ]);
    }
}
