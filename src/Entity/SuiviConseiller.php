<?php

namespace App\Entity;

use App\Repository\SuiviConseillerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SuiviConseillerRepository::class)]
#[ORM\Table(name: 'suivis_conseillers')]
class SuiviConseiller
{
    public const STATUT_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_APPROUVE = 'APPROUVE';
    public const STATUT_AVEC_RESERVES = 'AVEC_RESERVES';
    public const STATUT_REJETE = 'REJETE';

    public const STATUTS = [
        self::STATUT_EN_ATTENTE => 'En attente d\'examen',
        self::STATUT_EN_COURS => 'En cours d\'examen',
        self::STATUT_APPROUVE => 'Approuvé / Conforme',
        self::STATUT_AVEC_RESERVES => 'Observations / Réserves',
        self::STATUT_REJETE => 'Défavorable / Non conforme',
    ];

    public const PRIORITE_BASSE = 'BASSE';
    public const PRIORITE_NORMALE = 'NORMALE';
    public const PRIORITE_URGENTE = 'URGENTE';
    public const PRIORITE_TRES_URGENTE = 'TRES_URGENTE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Action::class, inversedBy: 'suivisConseillers')]
    #[ORM\JoinColumn(name: 'action_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Action $action = null;

    #[ORM\ManyToOne(targetEntity: ConseillerGouverneur::class, inversedBy: 'suivis')]
    #[ORM\JoinColumn(name: 'conseiller_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ConseillerGouverneur $conseiller = null;

    #[ORM\Column(name: 'statut_suivi', length: 50, options: ['default' => self::STATUT_EN_ATTENTE])]
    private string $statutSuivi = self::STATUT_EN_ATTENTE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $avis = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommandations = null;

    #[ORM\Column(name: 'point_attention', type: Types::TEXT, nullable: true)]
    private ?string $pointAttention = null;

    #[ORM\Column(name: 'priorite_cabinet', length: 30, options: ['default' => self::PRIORITE_NORMALE])]
    private string $prioriteCabinet = self::PRIORITE_NORMALE;

    #[ORM\Column(name: 'date_affectation', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateAffectation = null;

    #[ORM\Column(name: 'date_avis', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateAvis = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'affecte_par', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $affectePar = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->dateAffectation = new \DateTime();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getConseiller(): ?ConseillerGouverneur
    {
        return $this->conseiller;
    }

    public function setConseiller(?ConseillerGouverneur $conseiller): static
    {
        $this->conseiller = $conseiller;
        return $this;
    }

    public function getStatutSuivi(): string
    {
        return $this->statutSuivi;
    }

    public function setStatutSuivi(string $statutSuivi): static
    {
        $this->statutSuivi = $statutSuivi;
        return $this;
    }

    public function getAvis(): ?string
    {
        return $this->avis;
    }

    public function setAvis(?string $avis): static
    {
        $this->avis = $avis;
        return $this;
    }

    public function getRecommandations(): ?string
    {
        return $this->recommandations;
    }

    public function setRecommandations(?string $recommandations): static
    {
        $this->recommandations = $recommandations;
        return $this;
    }

    public function getPointAttention(): ?string
    {
        return $this->pointAttention;
    }

    public function setPointAttention(?string $pointAttention): static
    {
        $this->pointAttention = $pointAttention;
        return $this;
    }

    public function getPrioriteCabinet(): string
    {
        return $this->prioriteCabinet;
    }

    public function setPrioriteCabinet(string $prioriteCabinet): static
    {
        $this->prioriteCabinet = $prioriteCabinet;
        return $this;
    }

    public function getDateAffectation(): ?\DateTimeInterface
    {
        return $this->dateAffectation;
    }

    public function setDateAffectation(\DateTimeInterface $dateAffectation): static
    {
        $this->dateAffectation = $dateAffectation;
        return $this;
    }

    public function getDateAvis(): ?\DateTimeInterface
    {
        return $this->dateAvis;
    }

    public function setDateAvis(?\DateTimeInterface $dateAvis): static
    {
        $this->dateAvis = $dateAvis;
        return $this;
    }

    public function getAffectePar(): ?Utilisateur
    {
        return $this->affectePar;
    }

    public function setAffectePar(?Utilisateur $affectePar): static
    {
        $this->affectePar = $affectePar;
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

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getStatutLibelle(): string
    {
        return self::STATUTS[$this->statutSuivi] ?? $this->statutSuivi;
    }

    public function getStatutBadgeClass(): string
    {
        return match ($this->statutSuivi) {
            self::STATUT_APPROUVE => 'bg-success text-white',
            self::STATUT_AVEC_RESERVES => 'bg-warning text-dark',
            self::STATUT_REJETE => 'bg-danger text-white',
            self::STATUT_EN_COURS => 'bg-info text-white',
            default => 'bg-secondary text-white',
        };
    }

    public function getStatutIcon(): string
    {
        return match ($this->statutSuivi) {
            self::STATUT_APPROUVE => 'bi-check-circle-fill',
            self::STATUT_AVEC_RESERVES => 'bi-exclamation-triangle-fill',
            self::STATUT_REJETE => 'bi-x-circle-fill',
            self::STATUT_EN_COURS => 'bi-hourglass-split',
            default => 'bi-clock',
        };
    }
}
