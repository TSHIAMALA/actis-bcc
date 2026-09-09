<?php

namespace App\Service;

use App\Entity\Action;
use App\Entity\Historique;
use App\Entity\Instruction;
use App\Entity\Statut;
use App\Entity\TypeEvenement;
use App\Entity\TypeInstruction;
use App\Entity\Utilisateur;
use App\Repository\InstructionRepository;
use App\Repository\StatutRepository;
use App\Repository\TypeEvenementRepository;
use Doctrine\ORM\EntityManagerInterface;

class InstructionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private StatutRepository $statutRepo,
        private TypeEvenementRepository $typeEvenementRepo,
        private InstructionRepository $instructionRepo
    ) {
    }

    public function generateReference(TypeInstruction $type): string
    {
        $year = (new \DateTime())->format('Y');
        $prefix = match ($type->getCode()) {
            'ORDRE_SERVICE' => 'OS/DG',
            'INSTRUCTION' => 'INST/GOUV',
            'NOTE' => 'NOTE/DG',
            'DECISION' => 'DEC/GOUV',
            'RECOMMANDATION' => 'REC/DG',
            default => 'INST/BCC',
        };

        $count = $this->instructionRepo->count([]) + 1;
        $num = str_pad((string) $count, 3, '0', STR_PAD_LEFT);

        return sprintf('%s/%s/%s', $prefix, $year, $num);
    }

    public function generateActionReference(Instruction $instruction): string
    {
        $count = $instruction->getActions()->count() + 1;
        return sprintf('%s-ACT%02d', $instruction->getReference(), $count);
    }

    public function logHistorique(
        Instruction|Action $target,
        string $eventCode,
        ?Utilisateur $user = null,
        ?Statut $oldStatus = null,
        ?Statut $newStatus = null,
        ?string $description = null,
        ?array $before = null,
        ?array $after = null
    ): Historique {
        $event = $this->typeEvenementRepo->findOneBy(['code' => $eventCode]);
        if (!$event) {
            $event = $this->typeEvenementRepo->findOneBy([]) ?? new TypeEvenement();
        }

        $hist = new Historique();
        $hist->setTypeEvenement($event);
        $hist->setUtilisateur($user);
        $hist->setEntite($user?->getEntite());
        $hist->setAncienStatut($oldStatus);
        $hist->setNouveauStatut($newStatus);
        $hist->setDescription($description);
        $hist->setDonneesAvant($before);
        $hist->setDonneesApres($after);
        $hist->setCreatedAt(new \DateTime());

        if ($target instanceof Instruction) {
            $hist->setInstruction($target);
            $target->getHistoriques()->add($hist);
        } elseif ($target instanceof Action) {
            $hist->setAction($target);
            $hist->setInstruction($target->getInstruction());
            $target->getHistoriques()->add($hist);
        }

        $this->em->persist($hist);
        return $hist;
    }

    public function updateInstructionProgress(Instruction $instruction, ?Utilisateur $currentUser = null): void
    {
        $actions = $instruction->getActions();
        if ($actions->count() === 0) {
            return;
        }

        $totalProgress = 0.0;
        $allExecuted = true;
        $hasEnCours = false;

        foreach ($actions as $action) {
            $taux = (float) $action->getTauxAvancement();
            $totalProgress += $taux;

            if ($taux < 100 || $action->getStatut()?->getCode() !== Statut::CODE_EXECUTEE) {
                $allExecuted = false;
            }
            if ($taux > 0 || $action->getStatut()?->getCode() === Statut::CODE_EN_COURS) {
                $hasEnCours = true;
            }
        }

        $avgProgress = round($totalProgress / $actions->count(), 2);
        $instruction->setTauxAvancement($avgProgress);

        // Transition status if necessary
        $currentStatusCode = $instruction->getStatut()?->getCode();

        if ($allExecuted && $currentStatusCode !== Statut::CODE_CLOTUREE) {
            $execStatut = $this->statutRepo->findOneBy(['code' => Statut::CODE_EXECUTEE]);
            if ($execStatut && $currentStatusCode !== Statut::CODE_EXECUTEE) {
                $oldStatut = $instruction->getStatut();
                $instruction->setStatut($execStatut);
                $this->logHistorique(
                    $instruction,
                    TypeEvenement::CHANGEMENT_STATUT,
                    $currentUser,
                    $oldStatut,
                    $execStatut,
                    'Toutes les actions sont exécutées. L\'instruction passe automatiquement au statut "Exécutée".'
                );
            }
        } elseif ($hasEnCours && in_array($currentStatusCode, [Statut::CODE_BROUILLON, Statut::CODE_AFFECTEE])) {
            $enCoursStatut = $this->statutRepo->findOneBy(['code' => Statut::CODE_EN_COURS]);
            if ($enCoursStatut) {
                $oldStatut = $instruction->getStatut();
                $instruction->setStatut($enCoursStatut);
                $this->logHistorique(
                    $instruction,
                    TypeEvenement::CHANGEMENT_STATUT,
                    $currentUser,
                    $oldStatut,
                    $enCoursStatut,
                    'Démarrage des actions opérationnelles. Statut mis à jour vers "En cours".'
                );
            }
        }

        $instruction->setUpdatedAt(new \DateTime());
        $this->em->flush();
    }
}
