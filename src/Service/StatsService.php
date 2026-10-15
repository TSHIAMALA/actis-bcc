<?php

namespace App\Service;

use App\Entity\Historique;
use App\Entity\Statut;
use App\Entity\TypeEvenement;
use App\Repository\ActionRepository;
use App\Repository\EntiteRepository;
use App\Repository\HistoriqueRepository;
use App\Repository\InstructionRepository;
use App\Repository\ProrogationRepository;
use App\Repository\RelanceRepository;

class StatsService
{
    private const DONE_CODES = [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE];
    private const CLOSED_CODES = [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE];
    private const EN_COURS_CODES = [Statut::CODE_EN_COURS, Statut::CODE_EN_ATTENTE, Statut::CODE_A_VERIFIER];

    /** Une instruction concerne une entité si au moins une de ses actions lui est confiée (alias d'instruction : i) */
    private const DQL_INSTRUCTION_CONCERNE_ENTITE = 'EXISTS (SELECT ax.id FROM App\Entity\Action ax WHERE ax.instruction = i AND ax.entiteResponsable = :entiteId AND ax.deletedAt IS NULL)';

    public function __construct(
        private InstructionRepository $instructionRepo,
        private ActionRepository $actionRepo,
        private EntiteRepository $entiteRepo,
        private ProrogationRepository $prorogationRepo,
        private RelanceRepository $relanceRepo,
        private HistoriqueRepository $historiqueRepo
    ) {
    }

    public function getDashboardKPIs(): array
    {
        return $this->getFilteredKPIs(null, null);
    }

    public function getChartsData(): array
    {
        return $this->getFilteredChartsData();
    }

    /**
     * KPIs filtrés par période et/ou entité, avec tendances réelles.
     *
     * Périmètre : instructions rattachées par entité pilote, actions par entité responsable,
     * période appliquée sur la date de création.
     *
     * $periode : 'all' | 'month' | 'quarter' | 'year' | 'custom:AAAA-MM-JJ:AAAA-MM-JJ' (voir resolvePeriod)
     */
    public function getFilteredKPIs(?string $periode = null, ?int $entiteId = null): array
    {
        [$dateFrom, $dateTo] = $this->resolvePeriod($periode);
        $metrics = $this->computeMetrics($dateFrom, $dateTo, $entiteId);

        $echeancesThisWeek = $this->countEcheancesForWeek(0, $entiteId);
        $echeancesPrevWeek = $this->countEcheancesForWeek(-1, $entiteId);

        // Tendances : uniquement si une période de comparaison équivalente existe
        $prevBounds = $this->getPreviousPeriodBounds($periode);
        if ($prevBounds !== null) {
            $prev = $this->computeMetrics($prevBounds[0], $prevBounds[1], $entiteId);
            $trends = [
                'en_cours' => $this->computeTrendPercent($prev['en_cours'], $metrics['en_cours']),
                'total_actions' => $this->computeTrendPercent($prev['total_actions'], $metrics['total_actions']),
                'actions_en_retard' => $this->computeTrendPercent($prev['actions_en_retard'], $metrics['actions_en_retard'], true),
                // Un taux sur 0 action n'a pas de sens : pas de comparaison si l'une des périodes est vide
                'taux_execution' => ($prev['total_actions'] > 0 && $metrics['total_actions'] > 0)
                    ? $this->computeTrendPoints($prev['taux_execution_actions'], $metrics['taux_execution_actions'])
                    : null,
            ];
        } else {
            $trends = ['en_cours' => null, 'total_actions' => null, 'actions_en_retard' => null, 'taux_execution' => null];
        }
        $trends['echeances'] = $this->computeTrendPercent($echeancesPrevWeek, $echeancesThisWeek);

        return $metrics + [
            'echeances_this_week' => $echeancesThisWeek,
            'prorogations_en_attente' => count($this->prorogationRepo->findPending()),
            'total_relances' => $this->relanceRepo->count([]),
            'trends' => $trends,
            'trend_label' => match ($this->getPeriodType($periode)) {
                'month' => 'vs même date mois précédent',
                'quarter' => 'vs même date trimestre précédent',
                'year' => 'vs même date année précédente',
                'custom' => $prevBounds
                    ? sprintf('vs %s – %s', $prevBounds[0]->format('d/m/Y'), $prevBounds[1]->format('d/m/Y'))
                    : null,
                default => null,
            },
            'period_from' => $dateFrom?->format('d/m/Y'),
            'period_to' => ($dateTo ?? new \DateTime())->format('d/m/Y'),
        ];
    }

    /**
     * Calcule les indicateurs sur les instructions/actions créées dans l'intervalle [from, to].
     */
    private function computeMetrics(?\DateTimeInterface $from, ?\DateTimeInterface $to, ?int $entiteId): array
    {
        $qbInstructions = $this->instructionRepo->createQueryBuilder('i')
            ->leftJoin('i.statut', 's')->addSelect('s')
            ->where('i.deletedAt IS NULL');

        $qbActions = $this->actionRepo->createQueryBuilder('a')
            ->leftJoin('a.statut', 's')->addSelect('s')
            ->where('a.deletedAt IS NULL');

        if ($from) {
            $qbInstructions->andWhere('i.createdAt >= :dateFrom')->setParameter('dateFrom', $from);
            $qbActions->andWhere('a.createdAt >= :dateFrom')->setParameter('dateFrom', $from);
        }
        if ($to) {
            $qbInstructions->andWhere('i.createdAt <= :dateTo')->setParameter('dateTo', $to);
            $qbActions->andWhere('a.createdAt <= :dateTo')->setParameter('dateTo', $to);
        }
        if ($entiteId) {
            $qbInstructions->andWhere(self::DQL_INSTRUCTION_CONCERNE_ENTITE)->setParameter('entiteId', $entiteId);
            $qbActions->andWhere('a.entiteResponsable = :entiteId')->setParameter('entiteId', $entiteId);
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
            } elseif (in_array($code, self::EN_COURS_CODES)) {
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
        $totalActions = 0;
        $actionsExecutees = 0;
        $actionsEnRetard = 0;
        $actionsEnCours = 0;

        foreach ($allActions as $act) {
            $code = $act->getStatut()?->getCode();
            // Les actions annulées ne comptent pas dans la base du taux d'exécution
            if ($code === Statut::CODE_ANNULEE) {
                continue;
            }
            $totalActions++;

            if (in_array($code, self::DONE_CODES)) {
                $actionsExecutees++;
            } elseif ($code === Statut::CODE_EN_COURS) {
                $actionsEnCours++;
            }

            if ($act->isEnRetard()) {
                $actionsEnRetard++;
            }
        }

        return [
            'total_instructions' => $totalInstructions,
            'nouvelles' => $nouvelles,
            'en_cours' => $enCours,
            'executees' => $executees,
            'cloturees' => $cloturees,
            'finalisees' => $executees + $cloturees,
            'en_retard' => $enRetard,
            'taux_avancement_moyen' => $totalInstructions > 0 ? round($totalAvancement / $totalInstructions, 1) : 0,
            'total_actions' => $totalActions,
            'actions_executees' => $actionsExecutees,
            'actions_en_cours' => $actionsEnCours,
            'actions_en_retard' => $actionsEnRetard,
            'taux_execution_actions' => $totalActions > 0 ? round(($actionsExecutees / $totalActions) * 100, 1) : 0,
        ];
    }

    /**
     * Période précédente « à date équivalente » : même durée écoulée, décalée d'une période.
     * Ex. (filtre mois, le 13/10) : 01/09 → 13/09.
     * Période personnalisée : la plage de même durée qui la précède immédiatement
     * (ex. 01/07 → 30/09 donne 31/03 → 30/06).
     *
     * @return array{0: \DateTime, 1: \DateTime}|null null si aucune comparaison possible (filtre « Tous »)
     */
    private function getPreviousPeriodBounds(?string $periode): ?array
    {
        [$from, $to] = $this->resolvePeriod($periode);
        if (!$from) {
            return null;
        }

        if ($this->getPeriodType($periode) === 'custom') {
            $days = (int) $from->diff($to)->days;
            $prevTo = (clone $from)->modify('-1 day')->setTime(23, 59, 59);
            $prevFrom = (clone $prevTo)->modify("-{$days} days")->setTime(0, 0);

            return [$prevFrom, $prevTo];
        }

        $shift = match ($this->getPeriodType($periode)) {
            'month' => '-1 month',
            'quarter' => '-3 months',
            'year' => '-1 year',
        };

        $prevFrom = (clone $from)->modify($shift);
        $prevTo = (new \DateTime())->modify($shift);

        return [$prevFrom, $prevTo];
    }

    /**
     * Variation en % d'un compteur. $higherIsWorse inverse la couleur (ex. retards).
     */
    private function computeTrendPercent(float $previous, float $current, bool $higherIsWorse = false): array
    {
        if ($previous == 0 && $current == 0) {
            return $this->formatTrend(0, '0%', $higherIsWorse);
        }

        if ($previous == 0) {
            return $this->formatTrend(1, 'Nouveau', $higherIsWorse);
        }

        $change = (int) round((($current - $previous) / $previous) * 100);

        return $this->formatTrend($change, ($change > 0 ? '+' : '') . $change . '%', $higherIsWorse);
    }

    /**
     * Variation en points de pourcentage (pour un taux).
     */
    private function computeTrendPoints(float $previous, float $current): array
    {
        $diff = round($current - $previous, 1);

        return $this->formatTrend($diff, ($diff > 0 ? '+' : '') . $diff . ' pts');
    }

    private function formatTrend(float $value, string $label, bool $higherIsWorse = false): array
    {
        if ($value > 0) {
            return ['value' => $value, 'label' => $label, 'direction' => 'up', 'icon' => 'bi-arrow-up-right', 'css_class' => $higherIsWorse ? 'trend-danger' : 'trend-up'];
        }
        if ($value < 0) {
            return ['value' => $value, 'label' => $label, 'direction' => 'down', 'icon' => 'bi-arrow-down-right', 'css_class' => $higherIsWorse ? 'trend-green' : 'trend-danger'];
        }

        return ['value' => 0, 'label' => $label, 'direction' => 'neutral', 'icon' => 'bi-dash', 'css_class' => 'trend-neutral'];
    }

    /**
     * Compte les actions (hors annulées) dont l'échéance tombe dans la semaine (lundi → dimanche).
     * $weekOffset = 0 pour la semaine courante, -1 pour la précédente.
     */
    private function countEcheancesForWeek(int $weekOffset, ?int $entiteId = null): int
    {
        $weekStart = (new \DateTime('monday this week'))->modify(($weekOffset * 7) . ' days')->setTime(0, 0);
        $weekEnd = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);

        $qb = $this->actionRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->leftJoin('a.statut', 's')
            ->where('a.deletedAt IS NULL')
            ->andWhere('a.dateEcheance BETWEEN :weekStart AND :weekEnd')
            ->andWhere('s.code != :annulee')
            ->setParameter('weekStart', $weekStart)
            ->setParameter('weekEnd', $weekEnd)
            ->setParameter('annulee', Statut::CODE_ANNULEE);

        if ($entiteId) {
            $qb->andWhere('a.entiteResponsable = :entiteId')->setParameter('entiteId', $entiteId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Taux d'exécution cumulé à la fin de chaque mois :
     * (actions réalisées à cette date) / (actions existantes à cette date), hors annulées,
     * sur le même périmètre que le KPI (actions créées depuis le début de la période filtrée).
     * Le dernier point est donc toujours égal au KPI « Taux d'exécution ».
     *
     * Une action est « réalisée à cette date » si son statut est exécuté/clôturé
     * et que sa date de réalisation (à défaut sa dernière mise à jour) est antérieure à la fin du mois.
     * Les mois sans aucune action renvoient null (pas de point affiché plutôt qu'un faux 0 %).
     */
    private function calculateMonthlyRates(?\DateTimeInterface $dateFrom = null, ?\DateTimeInterface $dateTo = null, ?int $entiteId = null): array
    {
        $now = new \DateTime();
        $monthLabels = ['Janv', 'Fév', 'Mars', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept', 'Oct', 'Nov', 'Déc'];

        $qb = $this->actionRepo->createQueryBuilder('a')
            ->select('a.createdAt AS createdAt, a.dateRealisation AS dateRealisation, a.updatedAt AS updatedAt, s.code AS code')
            ->leftJoin('a.statut', 's')
            ->where('a.deletedAt IS NULL')
            ->andWhere('s.code != :annulee')
            ->setParameter('annulee', Statut::CODE_ANNULEE);

        if ($dateFrom) {
            $qb->andWhere('a.createdAt >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('a.createdAt <= :dateTo')->setParameter('dateTo', $dateTo);
        }
        if ($entiteId) {
            $qb->andWhere('a.entiteResponsable = :entiteId')->setParameter('entiteId', $entiteId);
        }

        $rows = $qb->getQuery()->getArrayResult();

        // Axe : du début de la période (ou de janvier pour « Tous ») jusqu'au mois courant.
        // Pour une période passée, la courbe montre comment les actions créées dans la période
        // ont été exécutées jusqu'à aujourd'hui ; le dernier point reste égal au KPI.
        $cursor = $dateFrom
            ? (new \DateTime($dateFrom->format('Y-m-01')))
            : (new \DateTime($now->format('Y') . '-01-01'));
        $lastMonth = new \DateTime($now->format('Y-m-01'));

        $labels = [];
        $data = [];
        for (; $cursor <= $lastMonth; $cursor->modify('+1 month')) {
            $labels[] = $monthLabels[(int) $cursor->format('n') - 1] . ($cursor->format('Y') !== $now->format('Y') ? ' ' . $cursor->format('y') : '');
            $monthEnd = (clone $cursor)->modify('last day of this month')->setTime(23, 59, 59);

            $total = 0;
            $done = 0;
            foreach ($rows as $row) {
                if ($row['createdAt'] === null || $row['createdAt'] > $monthEnd) {
                    continue;
                }
                $total++;

                $doneAt = $row['dateRealisation'] ?? $row['updatedAt'] ?? $row['createdAt'];
                if (in_array($row['code'], self::DONE_CODES) && $doneAt <= $monthEnd) {
                    $done++;
                }
            }

            $data[] = $total > 0 ? round(($done / $total) * 100, 1) : null;
        }

        $nonNull = array_values(array_filter($data, fn ($v) => $v !== null));

        return [
            'labels' => $labels,
            'data' => $data,
            'current_rate' => $nonNull ? end($nonNull) : 0,
        ];
    }

    /**
     * Charts data filtrés par période et/ou entité
     */
    public function getFilteredChartsData(?string $periode = null, ?int $entiteId = null): array
    {
        [$dateFrom, $dateTo] = $this->resolvePeriod($periode);

        $qb = $this->instructionRepo->createQueryBuilder('i')
            ->select('s.libelle, s.code, s.couleur, COUNT(i.id) as total')
            ->leftJoin('i.statut', 's')
            ->where('i.deletedAt IS NULL')
            ->groupBy('s.id');

        if ($dateFrom) {
            $qb->andWhere('i.createdAt >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('i.createdAt <= :dateTo')->setParameter('dateTo', $dateTo);
        }
        if ($entiteId) {
            $qb->andWhere(self::DQL_INSTRUCTION_CONCERNE_ENTITE)->setParameter('entiteId', $entiteId);
        }

        $byStatut = $qb->getQuery()->getArrayResult();

        $totalStatut = (int) array_sum(array_column($byStatut, 'total'));
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

        $statutsList = [];
        foreach ($byStatut as $s) {
            $statutsList[] = [
                'libelle' => $s['libelle'],
                'total' => (int) $s['total'],
                'percent' => $totalStatut > 0 ? round(($s['total'] / $totalStatut) * 100) : 0,
                'color' => $colorsMap[$s['code'] ?? ''] ?? ($s['couleur'] ?? '#3B82F6'),
            ];
        }

        return [
            'total_instructions_count' => $totalStatut,
            'statuts_detailed' => $statutsList,
            'statuts' => [
                'labels' => array_column($statutsList, 'libelle'),
                'data' => array_column($statutsList, 'total'),
                'colors' => array_column($statutsList, 'color'),
            ],
            'monthly_rates' => $this->calculateMonthlyRates($dateFrom, $dateTo, $entiteId),
        ];
    }

    /**
     * Actions prioritaires : actions non terminées, les plus urgentes d'abord
     * (échéance la plus proche / dépassée, puis priorité ; sans échéance en dernier).
     */
    public function getFilteredPriorityActions(?string $periode = null, ?int $entiteId = null, int $limit = 10): array
    {
        $qb = $this->actionRepo->createQueryBuilder('a')
            ->leftJoin('a.instruction', 'i')->addSelect('i')
            ->leftJoin('a.statut', 's')->addSelect('s')
            ->leftJoin('a.priorite', 'p')->addSelect('p')
            ->leftJoin('a.entiteResponsable', 'e')->addSelect('e')
            ->leftJoin('a.responsable', 'r')->addSelect('r')
            ->addSelect('CASE WHEN a.dateEcheance IS NULL THEN 1 ELSE 0 END AS HIDDEN sansEcheance')
            ->where('a.deletedAt IS NULL')
            ->andWhere('s.code NOT IN (:closedStatuts)')
            ->setParameter('closedStatuts', self::CLOSED_CODES)
            ->orderBy('sansEcheance', 'ASC')
            ->addOrderBy('a.dateEcheance', 'ASC')
            ->addOrderBy('p.niveau', 'DESC')
            ->setMaxResults($limit);

        [$dateFrom, $dateTo] = $this->resolvePeriod($periode);
        if ($dateFrom) {
            $qb->andWhere('a.createdAt >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('a.createdAt <= :dateTo')->setParameter('dateTo', $dateTo);
        }

        if ($entiteId) {
            $qb->andWhere('a.entiteResponsable = :entiteId')->setParameter('entiteId', $entiteId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Échéances à suivre : actions non terminées en retard ou arrivant à échéance dans $days jours.
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
            ->andWhere('s.code NOT IN (:closedStatuts)')
            ->setParameter('limitDate', (new \DateTime('today'))->modify("+{$days} days"))
            ->setParameter('closedStatuts', self::CLOSED_CODES)
            ->orderBy('a.dateEcheance', 'ASC')
            ->setMaxResults(5);

        [$dateFrom, $dateTo] = $this->resolvePeriod($periode);
        if ($dateFrom) {
            $qb->andWhere('a.createdAt >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('a.createdAt <= :dateTo')->setParameter('dateTo', $dateTo);
        }

        if ($entiteId) {
            $qb->andWhere('a.entiteResponsable = :entiteId')->setParameter('entiteId', $entiteId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Dernières activités (journal historique), filtrées par période et entité.
     *
     * @return Historique[]
     */
    public function getRecentActivities(?string $periode = null, ?int $entiteId = null, int $limit = 5): array
    {
        $qb = $this->historiqueRepo->createQueryBuilder('h')
            ->leftJoin('h.typeEvenement', 't')->addSelect('t')
            ->leftJoin('h.utilisateur', 'u')->addSelect('u')
            ->leftJoin('u.entite', 'ue')->addSelect('ue')
            ->leftJoin('h.instruction', 'hi')->addSelect('hi')
            ->leftJoin('h.action', 'ha')->addSelect('ha')
            ->orderBy('h.createdAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults($limit);

        [$dateFrom, $dateTo] = $this->resolvePeriod($periode);
        if ($dateFrom) {
            $qb->andWhere('h.createdAt >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo) {
            $qb->andWhere('h.createdAt <= :dateTo')->setParameter('dateTo', $dateTo);
        }

        if ($entiteId) {
            $qb->andWhere('h.entite = :entiteId OR ha.entiteResponsable = :entiteId OR ' . str_replace('ax.instruction = i', 'ax.instruction = hi', self::DQL_INSTRUCTION_CONCERNE_ENTITE))
                ->setParameter('entiteId', $entiteId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Icône et couleur d'affichage d'un type d'événement du journal.
     */
    public static function getActivityStyle(?string $typeCode): array
    {
        return match ($typeCode) {
            TypeEvenement::CLOTURE => ['icon' => 'bi-check2-all', 'class' => 'disc-green'],
            TypeEvenement::AVANCEMENT, TypeEvenement::CHANGEMENT_STATUT => ['icon' => 'bi-check2', 'class' => 'disc-green'],
            TypeEvenement::COMMENTAIRE_AJOUTE => ['icon' => 'bi-chat-left-text', 'class' => 'disc-gray'],
            TypeEvenement::RELANCE_ENVOYEE => ['icon' => 'bi-send', 'class' => 'disc-indigo'],
            TypeEvenement::JUSTIFICATIF_AJOUTE => ['icon' => 'bi-paperclip', 'class' => 'disc-gray'],
            TypeEvenement::DEMANDE_PROROGATION, TypeEvenement::VALIDATION_PROROGATION => ['icon' => 'bi-calendar-plus', 'class' => 'disc-indigo'],
            default => ['icon' => 'bi-file-earmark-text', 'class' => 'disc-blue'],
        };
    }

    /**
     * Construit la valeur de période personnalisée attendue par les méthodes de ce service.
     * Retourne null si les dates sont invalides ou inversées.
     */
    public static function buildCustomPeriod(?string $du, ?string $au): ?string
    {
        $from = $du ? \DateTime::createFromFormat('!Y-m-d', $du) : false;
        $to = $au ? \DateTime::createFromFormat('!Y-m-d', $au) : false;

        if (!$from || !$to || $from > $to) {
            return null;
        }

        return sprintf('custom:%s:%s', $from->format('Y-m-d'), $to->format('Y-m-d'));
    }

    private function getPeriodType(?string $periode): string
    {
        if ($periode !== null && str_starts_with($periode, 'custom:')) {
            return 'custom';
        }

        return in_array($periode, ['month', 'quarter', 'year'], true) ? $periode : 'all';
    }

    /**
     * Bornes [début, fin] de la période, appliquées sur la date de création.
     * Fin null = jusqu'à maintenant ; début null = depuis toujours.
     *
     * @return array{0: ?\DateTime, 1: ?\DateTime}
     */
    private function resolvePeriod(?string $periode): array
    {
        $now = new \DateTime();

        switch ($this->getPeriodType($periode)) {
            case 'month':
                return [(clone $now)->modify('first day of this month')->setTime(0, 0), null];
            case 'quarter':
                return [new \DateTime(sprintf('%d-%02d-01', (int) $now->format('Y'), intdiv((int) $now->format('n') - 1, 3) * 3 + 1)), null];
            case 'year':
                return [(clone $now)->modify('first day of january this year')->setTime(0, 0), null];
            case 'custom':
                [, $du, $au] = explode(':', $periode) + [null, null, null];
                $from = \DateTime::createFromFormat('!Y-m-d', (string) $du);
                $to = \DateTime::createFromFormat('!Y-m-d', (string) $au);
                if ($from && $to && $from <= $to) {
                    return [$from, $to->setTime(23, 59, 59)];
                }
                // Période mal formée : on retombe sur « Tous »
        }

        return [null, null];
    }
}
