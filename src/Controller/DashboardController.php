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
}
