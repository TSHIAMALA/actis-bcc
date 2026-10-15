<?php

namespace App\Service;

use App\Entity\Action;
use App\Entity\ConseillerGouverneur;
use App\Entity\Entite;
use App\Entity\Instruction;
use App\Entity\Statut;
use App\Entity\SuiviConseiller;
use App\Repository\ConseillerGouverneurRepository;
use App\Repository\InstructionRepository;
use App\Service\XlsxWriter as X;

/**
 * Rapport d'analyse Excel (3 feuilles) :
 *  1. Synthèse : indicateurs clés, répartitions par statut / type / priorité, actions les plus en retard ;
 *  2. Entités & Conseillers : performance d'exécution par entité, charge et avis par conseiller ;
 *  3. Instructions : détail complet, filtrable.
 * Les instructions et actions supprimées (corbeille) sont exclues.
 */
class RapportService
{
    private const DONE = [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE];
    private const AVIS_RENDUS = [SuiviConseiller::STATUT_APPROUVE, SuiviConseiller::STATUT_AVEC_RESERVES, SuiviConseiller::STATUT_REJETE];

    public function __construct(
        private InstructionRepository $instructionRepo,
        private ConseillerGouverneurRepository $conseillerRepo
    ) {
    }

    public function genererClasseur(): string
    {
        $instructions = array_values(array_filter(
            $this->instructionRepo->findBy([], ['dateInstruction' => 'DESC']),
            fn (Instruction $i) => $i->getDeletedAt() === null
        ));
        $actions = [];
        foreach ($instructions as $inst) {
            foreach ($inst->getActions() as $act) {
                if ($act->getDeletedAt() === null) {
                    $actions[] = $act;
                }
            }
        }

        $xlsx = new XlsxWriter();
        $genereLe = 'Généré le ' . (new \DateTime())->format('d/m/Y à H:i') . ' — ACTIS, Banque Centrale du Congo';

        $this->feuilleSynthese($xlsx->addSheet('Synthèse', [34, 14, 14, 14, 14, 3, 30, 14]), $instructions, $actions, $genereLe);
        $this->feuilleEntitesConseillers($xlsx->addSheet('Entités & Conseillers', [14, 52, 13, 12, 12, 12, 12, 13, 13]), $actions, $genereLe);
        $this->feuilleInstructions($xlsx->addSheet('Instructions', [17, 18, 50, 14, 11, 12, 12, 12, 9, 10, 10, 12, 22, 36]), $instructions, $genereLe);

        return $xlsx->toString();
    }

    // ─────────────────────────────── Feuille 1 : Synthèse ───────────────────────────────

    /** @param Instruction[] $instructions @param Action[] $actions */
    private function feuilleSynthese(XlsxSheet $f, array $instructions, array $actions, string $genereLe): void
    {
        $f->row([['Rapport de suivi des instructions du Gouverneur', X::TITRE]], 26);
        $f->merge('A1:H1');
        $f->row([[$genereLe, X::SOUS_TITRE]]);
        $f->merge('A2:H2');
        $f->row();

        // Indicateurs clés
        $nbInst = count($instructions);
        $instEnRetard = count(array_filter($instructions, fn (Instruction $i) => $i->isEnRetard()));
        $instCloturees = count(array_filter($instructions, fn (Instruction $i) => in_array($i->getStatut()?->getCode(), self::DONE, true)));
        $actionsUtiles = array_filter($actions, fn (Action $a) => $a->getStatut()?->getCode() !== Statut::CODE_ANNULEE);
        $nbActions = count($actionsUtiles);
        $actionsFaites = count(array_filter($actionsUtiles, fn (Action $a) => in_array($a->getStatut()?->getCode(), self::DONE, true)));
        $actionsRetard = count(array_filter($actionsUtiles, fn (Action $a) => $a->isEnRetard()));
        $taux = $nbActions ? $actionsFaites / $nbActions : 0;

        $f->row([['Indicateurs clés', X::SECTION]]);
        $f->row([
            [$nbInst, X::KPI_VALEUR], [$instEnRetard, X::KPI_VALEUR], [$nbActions, X::KPI_VALEUR],
            [$actionsRetard, X::KPI_VALEUR], [$taux, X::KPI_POURCENT],
        ], 34);
        $f->row([
            ['Instructions suivies', X::KPI_LIBELLE], ['Instructions en retard', X::KPI_LIBELLE], ['Actions', X::KPI_LIBELLE],
            ['Actions en retard', X::KPI_LIBELLE], ['Taux d\'exécution des actions', X::KPI_LIBELLE],
        ], 28);
        $f->row([
            [sprintf('Instructions exécutées ou clôturées : %d / %d', $instCloturees, $nbInst), X::SOUS_TITRE],
        ]);
        $f->merge('A' . ($f->nextRow() - 1) . ':E' . ($f->nextRow() - 1));
        $f->row();

        // Répartitions : statut, type, priorité
        $this->tableRepartition($f, 'Instructions par statut', $this->grouper($instructions, fn (Instruction $i) => $i->getStatut()?->getLibelle() ?? 'Sans statut'), $nbInst);
        $f->row();
        $this->tableRepartition($f, 'Instructions par type', $this->grouper($instructions, fn (Instruction $i) => $i->getTypeInstruction()?->getLibelle() ?? 'Non défini'), $nbInst);
        $f->row();
        $this->tableRepartition($f, 'Instructions par priorité', $this->grouper($instructions, fn (Instruction $i) => $i->getPriorite()?->getLibelle() ?? 'Non définie'), $nbInst);
        $f->row();

        // Actions les plus en retard
        $retards = array_filter($actionsUtiles, fn (Action $a) => $a->isEnRetard());
        $aujourdhui = new \DateTime('today');
        usort($retards, fn (Action $a, Action $b) => $a->getDateEcheance() <=> $b->getDateEcheance());

        $f->row([['Actions les plus en retard', X::SECTION]]);
        $f->row([
            ['Action', X::ENTETE], ['Instruction', X::ENTETE], ['Entité', X::ENTETE], ['Échéance', X::ENTETE], ['Jours de retard', X::ENTETE],
        ], 22);
        foreach (array_slice($retards, 0, 15) as $a) {
            $f->row([
                [$a->getLibelle(), X::TEXTE_LONG],
                [$a->getInstruction()?->getReference(), X::TEXTE],
                [$a->getEntiteResponsable()?->getCode(), X::TEXTE],
                [$a->getDateEcheance()?->format('d/m/Y'), X::TEXTE],
                [(int) $a->getDateEcheance()->diff($aujourdhui)->days, X::NOMBRE_ALERTE],
            ]);
        }
        if (!$retards) {
            $f->row([['Aucune action en retard.', X::SUCCES]]);
        } elseif (count($retards) > 15) {
            $f->row([[sprintf('… et %d autre(s) action(s) en retard (voir feuille Instructions).', count($retards) - 15), X::SOUS_TITRE]]);
        }
    }

    /** @param array<string, int> $groupes */
    private function tableRepartition(XlsxSheet $f, string $titre, array $groupes, int $total): void
    {
        arsort($groupes);
        $f->row([[$titre, X::SECTION]]);
        $f->row([['Catégorie', X::ENTETE], ['Nombre', X::ENTETE], ['Part', X::ENTETE]], 20);
        foreach ($groupes as $libelle => $nombre) {
            $f->row([[$libelle, X::TEXTE], [$nombre, X::NOMBRE], [$total ? $nombre / $total : 0, X::POURCENT]]);
        }
        $f->row([['Total', X::TEXTE_GRAS], [$total, X::NOMBRE_GRAS], [$total ? 1 : 0, X::POURCENT_GRAS]]);
    }

    // ──────────────────────── Feuille 2 : Entités & Conseillers ─────────────────────────

    /** @param Action[] $actions */
    private function feuilleEntitesConseillers(XlsxSheet $f, array $actions, string $genereLe): void
    {
        $f->row([['Performance par entité et suivi par les conseillers', X::TITRE]], 26);
        $f->merge('A1:I1');
        $f->row([[$genereLe, X::SOUS_TITRE]]);
        $f->merge('A2:I2');
        $f->row();

        // Par entité : uniquement les entités ayant au moins une action
        $parEntite = [];
        foreach ($actions as $a) {
            if ($a->getStatut()?->getCode() === Statut::CODE_ANNULEE || !$a->getEntiteResponsable()) {
                continue;
            }
            $e = $a->getEntiteResponsable();
            $ligne = $parEntite[$e->getId()] ??= ['entite' => $e, 'total' => 0, 'faites' => 0, 'retard' => 0, 'avancement' => 0.0];
            $ligne['total']++;
            $ligne['faites'] += in_array($a->getStatut()?->getCode(), self::DONE, true) ? 1 : 0;
            $ligne['retard'] += $a->isEnRetard() ? 1 : 0;
            $ligne['avancement'] += (float) $a->getTauxAvancement();
            $parEntite[$e->getId()] = $ligne;
        }
        // Les entités avec le plus de retards d'abord, puis le plus faible taux d'exécution
        usort($parEntite, fn ($x, $y) => [$y['retard'], $x['faites'] / $x['total']] <=> [$x['retard'], $y['faites'] / $y['total']]);

        $f->row([['Exécution des actions par entité', X::SECTION]]);
        $f->row([['Les entités qui cumulent le plus de retards apparaissent en premier.', X::SOUS_TITRE]]);
        $f->row([
            ['Code', X::ENTETE], ['Entité', X::ENTETE], ['Direction générale', X::ENTETE], ['Actions', X::ENTETE], ['Exécutées', X::ENTETE],
            ['En cours', X::ENTETE], ['En retard', X::ENTETE], ['Taux d\'exécution', X::ENTETE], ['Avancement moyen', X::ENTETE],
        ], 30);
        $totaux = ['total' => 0, 'faites' => 0, 'retard' => 0, 'avancement' => 0.0];
        foreach ($parEntite as $l) {
            /** @var Entite $e */
            $e = $l['entite'];
            $f->row([
                [$e->getCode(), X::TEXTE_GRAS],
                [$e->getNom(), X::TEXTE],
                [($e->getParent() ?? $e)->getCode(), X::TEXTE],
                [$l['total'], X::NOMBRE],
                [$l['faites'], X::NOMBRE],
                [$l['total'] - $l['faites'], X::NOMBRE],
                [$l['retard'], $l['retard'] > 0 ? X::NOMBRE_ALERTE : X::NOMBRE],
                [$l['faites'] / $l['total'], X::POURCENT],
                [$l['avancement'] / $l['total'] / 100, X::POURCENT],
            ]);
            foreach ($totaux as $k => $v) {
                $totaux[$k] = $v + $l[$k];
            }
        }
        if ($parEntite) {
            $f->row([
                ['Total', X::TEXTE_GRAS], [count($parEntite) . ' entité(s)', X::TEXTE_GRAS], ['', X::TEXTE_GRAS],
                [$totaux['total'], X::NOMBRE_GRAS], [$totaux['faites'], X::NOMBRE_GRAS], [$totaux['total'] - $totaux['faites'], X::NOMBRE_GRAS],
                [$totaux['retard'], X::NOMBRE_GRAS], [$totaux['faites'] / $totaux['total'], X::POURCENT_GRAS],
                [$totaux['avancement'] / $totaux['total'] / 100, X::POURCENT_GRAS],
            ]);
        } else {
            $f->row([['Aucune action enregistrée.', X::SOUS_TITRE]]);
        }
        $f->row();

        // Par conseiller
        $f->row([['Suivi par les conseillers du Gouverneur', X::SECTION]]);
        $f->row([['Instructions dont le conseiller est responsable, et avis demandés sur des actions.', X::SOUS_TITRE]]);
        $f->row([
            ['Code', X::ENTETE], ['Conseiller / Domaine', X::ENTETE], ['Instructions', X::ENTETE], ['dont en retard', X::ENTETE],
            ['Avancement moyen', X::ENTETE], ['Avis demandés', X::ENTETE], ['Avis rendus', X::ENTETE], ['Avis en attente', X::ENTETE],
        ], 30);

        $instructionsParConseiller = [];
        foreach ($this->instructionRepo->findBy(['deletedAt' => null]) as $inst) {
            foreach ($inst->getResponsables() as $c) {
                $instructionsParConseiller[$c->getId()][] = $inst;
            }
        }

        foreach ($this->conseillerRepo->findActifs() as $c) {
            /** @var ConseillerGouverneur $c */
            $insts = $instructionsParConseiller[$c->getId()] ?? [];
            $suivis = array_filter($c->getSuivis()->toArray(), fn (SuiviConseiller $s) => $s->getAction()?->getDeletedAt() === null);
            $rendus = count(array_filter($suivis, fn (SuiviConseiller $s) => in_array($s->getStatutSuivi(), self::AVIS_RENDUS, true)));
            $enRetard = count(array_filter($insts, fn (Instruction $i) => $i->isEnRetard()));
            $avancement = $insts ? array_sum(array_map(fn (Instruction $i) => (float) $i->getTauxAvancement(), $insts)) / count($insts) / 100 : null;
            $attente = count($suivis) - $rendus;

            $f->row([
                [$c->getCodeDomaine(), X::TEXTE_GRAS],
                [$c->getNom() . "\n" . $c->getDomaineCompetence(), X::TEXTE_LONG],
                [count($insts), X::NOMBRE],
                [$enRetard, $enRetard > 0 ? X::NOMBRE_ALERTE : X::NOMBRE],
                [$avancement, X::POURCENT],
                [count($suivis), X::NOMBRE],
                [$rendus, X::NOMBRE],
                [$attente, $attente > 0 ? X::NOMBRE_ALERTE : X::NOMBRE],
            ]);
        }
    }

    // ─────────────────────────────── Feuille 3 : Instructions ───────────────────────────────

    /** @param Instruction[] $instructions */
    private function feuilleInstructions(XlsxSheet $f, array $instructions, string $genereLe): void
    {
        $f->row([['Détail des instructions', X::TITRE]], 26);
        $f->merge('A1:N1');
        $f->row([[$genereLe . ' — utilisez les filtres de la ligne d\'en-tête pour trier ou sélectionner.', X::SOUS_TITRE]]);
        $f->merge('A2:N2');
        $f->row();

        $entete = $f->row(array_map(fn ($t) => [$t, X::ENTETE], [
            'Référence', 'Type', 'Objet', 'Statut', 'Priorité', 'Date', 'Échéance', 'Avancement',
            'Actions', 'Exécutées', 'En retard', 'Instruction en retard', 'Entités concernées', 'Conseillers responsables',
        ]), 32);

        foreach ($instructions as $i) {
            $acts = array_filter($i->getActions()->toArray(), fn (Action $a) => $a->getDeletedAt() === null && $a->getStatut()?->getCode() !== Statut::CODE_ANNULEE);
            $faites = count(array_filter($acts, fn (Action $a) => in_array($a->getStatut()?->getCode(), self::DONE, true)));
            $retard = count(array_filter($acts, fn (Action $a) => $a->isEnRetard()));

            $f->row([
                [$i->getReference(), X::TEXTE_GRAS],
                [$i->getTypeInstruction()?->getLibelle(), X::TEXTE],
                [$i->getObjet(), X::TEXTE_LONG],
                [$i->getStatut()?->getLibelle(), X::TEXTE],
                [$i->getPriorite()?->getLibelle(), X::TEXTE],
                [$i->getDateInstruction()?->format('d/m/Y'), X::TEXTE],
                [$i->getDateEcheance()?->format('d/m/Y'), $i->isEnRetard() ? X::ALERTE : X::TEXTE],
                [(float) $i->getTauxAvancement() / 100, X::POURCENT],
                [count($acts), X::NOMBRE],
                [$faites, X::NOMBRE],
                [$retard, $retard > 0 ? X::NOMBRE_ALERTE : X::NOMBRE],
                [$i->isEnRetard() ? 'OUI' : 'Non', $i->isEnRetard() ? X::ALERTE : X::TEXTE],
                [implode(', ', array_map(fn (Entite $e) => $e->getCode(), $i->getEntitesConcernees())), X::TEXTE_LONG],
                [implode(', ', $i->getResponsables()->map(fn (ConseillerGouverneur $c) => $c->getNom())->toArray()), X::TEXTE_LONG],
            ]);
        }

        $derniere = max($entete, $f->nextRow() - 1);
        $f->autoFilter(sprintf('A%d:N%d', $entete, $derniere));
        $f->freezeAbove($entete + 1);
    }

    /** @return array<string, int> */
    private function grouper(array $elements, callable $cle): array
    {
        $groupes = [];
        foreach ($elements as $e) {
            $k = $cle($e);
            $groupes[$k] = ($groupes[$k] ?? 0) + 1;
        }
        return $groupes;
    }
}
