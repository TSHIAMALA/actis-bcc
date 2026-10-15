<?php

namespace App\Controller;

use App\Entity\Action;
use App\Entity\Historique;
use App\Entity\Statut;
use App\Repository\EntiteRepository;
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
        EntiteRepository $entiteRepo
    ): Response {
        return $this->render('dashboard/index.html.twig', [
            'allEntites' => $entiteRepo->findActivesGroupees(),
            // Même source de données que l'API de filtrage : le rendu initial et le filtre « Tous » sont identiques
            'dashboard' => $this->buildDashboardPayload($statsService, 'all', null),
        ]);
    }

    #[Route('/api/dashboard', name: 'app_dashboard_api', methods: ['GET'])]
    public function dashboardApi(
        Request $request,
        StatsService $statsService
    ): JsonResponse {
        $periode = $request->query->get('periode', 'all');
        $entiteId = (int) $request->query->get('entite_id') ?: null;

        if ($periode === 'custom') {
            $periode = StatsService::buildCustomPeriod($request->query->get('du'), $request->query->get('au'));
            if ($periode === null) {
                return new JsonResponse(['success' => false, 'message' => 'Période personnalisée invalide : la date de début doit précéder la date de fin.'], 400);
            }
        }

        return new JsonResponse($this->buildDashboardPayload($statsService, $periode, $entiteId));
    }

    private function buildDashboardPayload(StatsService $statsService, string $periode, ?int $entiteId): array
    {
        $actionsData = array_map(
            fn (Action $act) => $this->serializePriorityAction($act),
            $statsService->getFilteredPriorityActions($periode, $entiteId, 5)
        );

        $today = new \DateTime('today');
        $echeancesData = [];
        foreach ($statsService->getFilteredUpcomingEcheances($periode, $entiteId) as $ech) {
            $daysUntil = (int) $today->diff($ech->getDateEcheance())->format('%r%a');
            $isRetard = $daysUntil < 0;
            $isProche = !$isRetard && $daysUntil <= 3;

            $echeancesData[] = [
                'id' => $ech->getId(),
                'url' => $this->generateUrl('app_action_show', ['id' => $ech->getId()]),
                'libelle' => $ech->getLibelle(),
                'entite_code' => $ech->getEntiteResponsable()?->getCode() ?? '',
                'date_echeance' => $ech->getDateEcheance()->format('d/m/Y'),
                'date_day' => $ech->getDateEcheance()->format('d'),
                'date_month' => $this->getShortMonthFr($ech->getDateEcheance()),
                'date_class' => $isRetard ? 'date-red' : ($isProche ? 'date-amber' : 'date-blue'),
                'status_label' => $isRetard ? 'En retard' : ($isProche ? 'Échéance proche' : 'À venir'),
                'status_class' => $isRetard ? 'badge-soft-red' : ($isProche ? 'badge-soft-yellow' : 'badge-soft-blue'),
            ];
        }

        $activitiesData = array_map(
            fn (Historique $h) => $this->serializeActivity($h),
            $statsService->getRecentActivities($periode, $entiteId, 5)
        );

        $kpis = $statsService->getFilteredKPIs($periode, $entiteId);

        return [
            'success' => true,
            'filter_label' => match ($periode) {
                'month' => 'Ce mois',
                'quarter' => 'Ce trimestre',
                'year' => 'Cette année',
                'all' => 'Tous',
                default => 'Personnalisée',
            },
            'kpis' => $kpis,
            'period_info' => $kpis['period_from']
                ? sprintf('Créées du %s au %s', $kpis['period_from'], $kpis['period_to'])
                : 'Toutes les données (date de création)',
            'charts' => $statsService->getFilteredChartsData($periode, $entiteId),
            'actions' => $actionsData,
            'echeances' => $echeancesData,
            'activities' => $activitiesData,
        ];
    }

    private function serializePriorityAction(Action $act): array
    {
        $statutCode = $act->getStatut()?->getCode();

        if ($act->isEnRetard()) {
            $statutDisplay = ['label' => 'En retard', 'class' => 'badge-soft-red'];
        } elseif (in_array($statutCode, [Statut::CODE_BROUILLON, Statut::CODE_AFFECTEE])) {
            $statutDisplay = ['label' => 'À démarrer', 'class' => 'badge-soft-gray'];
        } elseif ($statutCode === Statut::CODE_A_VERIFIER) {
            $statutDisplay = ['label' => 'À vérifier', 'class' => 'badge-soft-yellow'];
        } elseif ($statutCode === Statut::CODE_EN_ATTENTE) {
            $statutDisplay = ['label' => 'En attente', 'class' => 'badge-soft-gray'];
        } else {
            $statutDisplay = ['label' => 'En cours', 'class' => 'badge-soft-blue'];
        }

        $prioriteDisplay = match ($act->getPriorite()?->getCode()) {
            'URGENTE' => ['label' => 'Urgente', 'class' => 'badge-soft-red'],
            'HAUTE' => ['label' => 'Haute', 'class' => 'badge-soft-pink'],
            'FAIBLE' => ['label' => 'Faible', 'class' => 'badge-soft-gray'],
            default => ['label' => 'Normale', 'class' => 'badge-soft-gray'],
        };

        $instruction = $act->getInstruction();
        $objet = $instruction?->getObjet() ?? '';

        return [
            'id' => $act->getId(),
            'url' => $this->generateUrl('app_action_show', ['id' => $act->getId()]),
            'instruction_url' => $instruction ? $this->generateUrl('app_instruction_show', ['id' => $instruction->getId()]) : null,
            'libelle' => $act->getLibelle(),
            'reference' => $act->getReference(),
            'instruction_id' => $instruction?->getId(),
            'instruction_reference' => $instruction?->getReference(),
            'instruction_objet' => mb_substr($objet, 0, 32) . (mb_strlen($objet) > 32 ? '...' : ''),
            'entite_code' => $act->getEntiteResponsable()?->getCode() ?? 'BCC',
            'entite_nom' => mb_substr($act->getEntiteResponsable()?->getNom() ?? 'Direction Générale', 0, 24),
            'date_echeance' => $act->getDateEcheance()?->format('d/m/Y') ?? '-',
            'is_en_retard' => $act->isEnRetard(),
            'statut' => $statutDisplay,
            'priorite' => $prioriteDisplay,
        ];
    }

    private function serializeActivity(Historique $h): array
    {
        $style = StatsService::getActivityStyle($h->getTypeEvenement()?->getCode());
        $user = $h->getUtilisateur();

        if ($h->getAction()) {
            $subject = 'Action ' . $h->getAction()->getReference();
            $url = $this->generateUrl('app_action_show', ['id' => $h->getAction()->getId()]);
        } elseif ($h->getInstruction()) {
            $subject = 'Instruction ' . $h->getInstruction()->getReference();
            $url = $this->generateUrl('app_instruction_show', ['id' => $h->getInstruction()->getId()]);
        } else {
            $subject = '';
            $url = null;
        }

        return [
            'subject' => $subject,
            'url' => $url,
            'text' => $h->getDescription() ?: ($h->getTypeEvenement()?->getLibelle() ?? ''),
            'actor' => $user ? $user->getNomComplet() . ($user->getEntite() ? ' (' . $user->getEntite()->getCode() . ')' : '') : null,
            'time' => $this->formatRelativeTime($h->getCreatedAt()),
            'icon' => $style['icon'],
            'icon_class' => $style['class'],
        ];
    }

    private function formatRelativeTime(?\DateTimeInterface $date): string
    {
        if (!$date) return '';

        $seconds = (new \DateTime())->getTimestamp() - $date->getTimestamp();
        if ($seconds < 60) return "À l'instant";
        if ($seconds < 3600) return sprintf('Il y a %d min', intdiv($seconds, 60));
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            return sprintf('Il y a %d heure%s', $h, $h > 1 ? 's' : '');
        }
        $d = intdiv($seconds, 86400);
        if ($d < 30) return sprintf('Il y a %d jour%s', $d, $d > 1 ? 's' : '');

        return 'Le ' . $date->format('d/m/Y');
    }

    private function getShortMonthFr(?\DateTimeInterface $date): string
    {
        if (!$date) return '';
        $months = [1 => 'Janv.', 'Fév.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
        return $months[(int)$date->format('n')] ?? '';
    }
}
