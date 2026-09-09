<?php

namespace App\Entity;

use App\Repository\RelanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RelanceRepository::class)]
#[ORM\Table(name: 'relances')]
class Relance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instruction::class, inversedBy: 'relances')]
    #[ORM\JoinColumn(name: 'instruction_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Instruction $instruction = null;

    #[ORM\ManyToOne(targetEntity: Action::class, inversedBy: 'relances')]
    #[ORM\JoinColumn(name: 'action_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Action $action = null;

    #[ORM\ManyToOne(targetEntity: TypeRelance::class)]
    #[ORM\JoinColumn(name: 'type_relance_id', referencedColumnName: 'id', nullable: false)]
    private ?TypeRelance $typeRelance = null;

    #[ORM\ManyToOne(targetEntity: CanalNotification::class)]
    #[ORM\JoinColumn(name: 'canal_notification_id', referencedColumnName: 'id', nullable: false)]
    private ?CanalNotification $canalNotification = null;

    #[ORM\ManyToOne(targetEntity: StatutRelance::class)]
    #[ORM\JoinColumn(name: 'statut_relance_id', referencedColumnName: 'id', nullable: false)]
    private ?StatutRelance $statutRelance = null;

    #[ORM\Column(name: 'date_planifiee', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $datePlanifiee = null;

    #[ORM\Column(name: 'date_envoi', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateEnvoi = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'destinataire_utilisateur_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $destinataireUtilisateur = null;

    #[ORM\Column(name: 'destinataire_email', length: 255, nullable: true)]
    private ?string $destinataireEmail = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $objet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(name: 'nombre_tentatives', type: 'integer', options: ['default' => 0])]
    private int $nombreTentatives = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $erreur = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->datePlanifiee = new \DateTime();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstruction(): ?Instruction
    {
        return $this->instruction;
    }

    public function setInstruction(?Instruction $instruction): static
    {
        $this->instruction = $instruction;
        return $this;
    }

    public function getAction(): ?Action
    {
        return $this->action;
    }

    public function setAction(?Action $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getTypeRelance(): ?TypeRelance
    {
        return $this->typeRelance;
    }

    public function setTypeRelance(?TypeRelance $typeRelance): static
    {
        $this->typeRelance = $typeRelance;
        return $this;
    }

    public function getCanalNotification(): ?CanalNotification
    {
        return $this->canalNotification;
    }

    public function setCanalNotification(?CanalNotification $canalNotification): static
    {
        $this->canalNotification = $canalNotification;
        return $this;
    }

    public function getStatutRelance(): ?StatutRelance
    {
        return $this->statutRelance;
    }

    public function setStatutRelance(?StatutRelance $statutRelance): static
    {
        $this->statutRelance = $statutRelance;
        return $this;
    }

    public function getDatePlanifiee(): ?\DateTimeInterface
    {
        return $this->datePlanifiee;
    }

    public function setDatePlanifiee(\DateTimeInterface $datePlanifiee): static
    {
        $this->datePlanifiee = $datePlanifiee;
        return $this;
    }

    public function getDateEnvoi(): ?\DateTimeInterface
    {
        return $this->dateEnvoi;
    }

    public function setDateEnvoi(?\DateTimeInterface $dateEnvoi): static
    {
        $this->dateEnvoi = $dateEnvoi;
        return $this;
    }

    public function getDestinataireUtilisateur(): ?Utilisateur
    {
        return $this->destinataireUtilisateur;
    }

    public function setDestinataireUtilisateur(?Utilisateur $destinataireUtilisateur): static
    {
        $this->destinataireUtilisateur = $destinataireUtilisateur;
        return $this;
    }

    public function getDestinataireEmail(): ?string
    {
        return $this->destinataireEmail;
    }

    public function setDestinataireEmail(?string $destinataireEmail): static
    {
        $this->destinataireEmail = $destinataireEmail;
        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = $objet;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getNombreTentatives(): int
    {
        return $this->nombreTentatives;
    }

    public function setNombreTentatives(int $nombreTentatives): static
    {
        $this->nombreTentatives = $nombreTentatives;
        return $this;
    }

    public function getErreur(): ?string
    {
        return $this->erreur;
    }

    public function setErreur(?string $erreur): static
    {
        $this->erreur = $erreur;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
