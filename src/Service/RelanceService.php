<?php

namespace App\Service;

use App\Entity\Action;
use App\Entity\Instruction;
use App\Entity\Relance;
use App\Entity\Statut;
use App\Entity\StatutRelance;
use App\Entity\TypeEvenement;
use App\Entity\TypeRelance;
use App\Entity\Utilisateur;
use App\Repository\ActionRepository;
use App\Repository\CanalNotificationRepository;
use App\Repository\InstructionRepository;
use App\Repository\StatutRelanceRepository;
use App\Repository\TypeRelanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RelanceService
{
    public function __construct(
        private EntityManagerInterface $em,
        private InstructionRepository $instructionRepo,
        private ActionRepository $actionRepo,
        private TypeRelanceRepository $typeRelanceRepo,
        private StatutRelanceRepository $statutRelanceRepo,
        private CanalNotificationRepository $canalRepo,
        private InstructionService $instructionService,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function generateManualRelance(
        Instruction|Action $target,
        Utilisateur $destinataire,
        string $objet,
        string $message,
        ?Utilisateur $sender = null
    ): Relance {
        $typeRelance = $this->typeRelanceRepo->findOneBy(['code' => 'MANUELLE']) 
            ?? $this->typeRelanceRepo->findOneBy([]) 
            ?? new TypeRelance();
        $statutRelance = $this->statutRelanceRepo->findOneBy(['code' => 'ENVOYEE'])
            ?? $this->statutRelanceRepo->findOneBy([])
            ?? new StatutRelance();
        $canal = $this->canalRepo->findOneBy(['code' => 'EMAIL'])
            ?? $this->canalRepo->findOneBy([])
            ?? new \App\Entity\CanalNotification();

        $relance = new Relance();
        $relance->setTypeRelance($typeRelance);
        $relance->setStatutRelance($statutRelance);
        $relance->setCanalNotification($canal);
        $relance->setDestinataireUtilisateur($destinataire);
        $relance->setDestinataireEmail($destinataire->getEmail());
        $relance->setObjet($objet);
        $relance->setMessage($message);
        $relance->setDatePlanifiee(new \DateTime());
        $relance->setDateEnvoi(new \DateTime());
        $relance->setNombreTentatives(1);

        if ($target instanceof Instruction) {
            $relance->setInstruction($target);
            $this->instructionService->logHistorique(
                $target,
                TypeEvenement::RELANCE_ENVOYEE,
                $sender,
                null,
                null,
                sprintf('Relance manuelle transmise à %s (%s).', $destinataire->getNomComplet(), $destinataire->getEmail())
            );
        } else {
            $relance->setAction($target);
            $relance->setInstruction($target->getInstruction());
            $this->instructionService->logHistorique(
                $target,
                TypeEvenement::RELANCE_ENVOYEE,
                $sender,
                null,
                null,
                sprintf('Relance manuelle envoyée pour l\'action "%s" à %s.', $target->getLibelle(), $destinataire->getNomComplet())
            );
        }

        $this->em->persist($relance);
        $this->em->flush();

        return $relance;
    }

    public function checkAndGenerateAutomatedAlerts(): array
    {
        $created = [];
        $actions = $this->actionRepo->findAll();
        $today = new \DateTime('today');

        $statutEnvoyee = $this->statutRelanceRepo->findOneBy(['code' => 'ENVOYEE']);
        $canal = $this->canalRepo->findOneBy(['code' => 'EMAIL']);

        foreach ($actions as $action) {
            if (in_array($action->getStatut()?->getCode(), [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE])) {
                continue;
            }

            $echeance = $action->getDateEcheance();
            if (!$echeance) {
                continue;
            }

            $diffDays = (int) $today->diff($echeance)->format('%r%a');
            $typeCode = null;
            $alertMsg = null;

            if ($diffDays === 7) {
                $typeCode = 'J_MOINS_7';
                $alertMsg = sprintf('Rappel J-7 : L\'action "%s" arrive à échéance le %s.', $action->getLibelle(), $echeance->format('d/m/Y'));
            } elseif ($diffDays === 3) {
                $typeCode = 'J_MOINS_3';
                $alertMsg = sprintf('Rappel renforcé J-3 : L\'action "%s" arrive à échéance le %s.', $action->getLibelle(), $echeance->format('d/m/Y'));
            } elseif ($diffDays === 1) {
                $typeCode = 'J_MOINS_1';
                $alertMsg = sprintf('Alerte J-1 : Échéance imminente demain pour l\'action "%s".', $action->getLibelle());
            } elseif ($diffDays === -1) {
                $typeCode = 'J_PLUS_1';
                $alertMsg = sprintf('Alerte Retard J+1 : L\'action "%s" a dépassé son échéance du %s.', $action->getLibelle(), $echeance->format('d/m/Y'));
            } elseif ($diffDays <= -7) {
                $typeCode = 'J_PLUS_7';
                $alertMsg = sprintf('Escalade Retard Persistant : L\'action "%s" est en retard depuis plus de 7 jours.', $action->getLibelle());
            }

            if ($typeCode && $action->getResponsable()) {
                $typeRelance = $this->typeRelanceRepo->findOneBy(['code' => $typeCode]) ?? $this->typeRelanceRepo->findOneBy([]);
                
                $relance = new Relance();
                $relance->setAction($action);
                $relance->setInstruction($action->getInstruction());
                $relance->setTypeRelance($typeRelance);
                $relance->setStatutRelance($statutEnvoyee);
                $relance->setCanalNotification($canal);
                $relance->setDestinataireUtilisateur($action->getResponsable());
                $relance->setDestinataireEmail($action->getResponsable()->getEmail());
                $relance->setObjet(sprintf('[ACTIS-BCC] %s - %s', $typeRelance?->getLibelle() ?? 'Notification', $action->getLibelle()));
                $relance->setMessage($alertMsg);
                $relance->setDatePlanifiee(new \DateTime());
                $relance->setDateEnvoi(new \DateTime());
                $relance->setNombreTentatives(1);

                $this->em->persist($relance);
                $created[] = $relance;
            }
        }

        if (count($created) > 0) {
            $this->em->flush();
        }

        return $created;
    }
}
