<?php

namespace App\Entity;

use App\Repository\InstructionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstructionRepository::class)]
#[ORM\Table(name: 'instructions')]
class Instruction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 500)]
    private ?string $objet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: TypeInstruction::class)]
    #[ORM\JoinColumn(name: 'type_instruction_id', referencedColumnName: 'id', nullable: false)]
    private ?TypeInstruction $typeInstruction = null;

    #[ORM\ManyToOne(targetEntity: Statut::class)]
    #[ORM\JoinColumn(name: 'statut_id', referencedColumnName: 'id', nullable: false)]
    private ?Statut $statut = null;

    #[ORM\ManyToOne(targetEntity: Priorite::class)]
    #[ORM\JoinColumn(name: 'priorite_id', referencedColumnName: 'id', nullable: false)]
    private ?Priorite $priorite = null;

    #[ORM\Column(name: 'date_instruction', type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $dateInstruction = null;

    #[ORM\Column(name: 'date_reception', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateReception = null;

    #[ORM\Column(name: 'date_echeance', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateEcheance = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emetteur = null;

    #[ORM\ManyToOne(targetEntity: Entite::class)]
    #[ORM\JoinColumn(name: 'entite_pilote_id', referencedColumnName: 'id', nullable: false)]
    private ?Entite $entitePilote = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'responsable_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $responsable = null;

    #[ORM\Column(name: 'taux_avancement', type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '0.00'])]
    private string $tauxAvancement = '0.00';

    #[ORM\Column(name: 'date_cloture', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateCloture = null;

    #[ORM\Column(name: 'motif_cloture', type: Types::TEXT, nullable: true)]
    private ?string $motifCloture = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deletedAt = null;

    #[ORM\Column(name: 'motif_suppression', type: Types::TEXT, nullable: true)]
    private ?string $motifSuppression = null;

    #[ORM\OneToMany(mappedBy: 'instruction', targetEntity: Action::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $actions;

    #[ORM\OneToMany(mappedBy: 'instruction', targetEntity: InstructionEntite::class, cascade: ['persist', 'remove'])]
    private Collection $instructionEntites;

    #[ORM\OneToMany(mappedBy: 'instruction', targetEntity: Justificatif::class, cascade: ['remove'])]
    #[ORM\OrderBy(['dateDepot' => 'DESC'])]
    private Collection $justificatifs;

    #[ORM\OneToMany(mappedBy: 'instruction', targetEntity: Prorogation::class, cascade: ['remove'])]
    #[ORM\OrderBy(['dateDemande' => 'DESC'])]
    private Collection $prorogations;

    #[ORM\OneToMany(mappedBy: 'instruction', targetEntity: Relance::class, cascade: ['remove'])]
    #[ORM\OrderBy(['datePlanifiee' => 'DESC'])]
    private Collection $relances;

    #[ORM\OneToMany(mappedBy: 'instruction', targetEntity: Commentaire::class, cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $commentaires;

    #[ORM\OneToMany(mappedBy: 'instruction', targetEntity: Historique::class, cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $historiques;

    public function __construct()
    {
        $this->actions = new ArrayCollection();
        $this->instructionEntites = new ArrayCollection();
        $this->justificatifs = new ArrayCollection();
        $this->prorogations = new ArrayCollection();
        $this->relances = new ArrayCollection();
        $this->commentaires = new ArrayCollection();
        $this->historiques = new ArrayCollection();
        $this->dateInstruction = new \DateTime();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;
        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(string $objet): static
    {
        $this->objet = $objet;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getTypeInstruction(): ?TypeInstruction
    {
        return $this->typeInstruction;
    }

    public function setTypeInstruction(?TypeInstruction $typeInstruction): static
    {
        $this->typeInstruction = $typeInstruction;
        return $this;
    }

    public function getStatut(): ?Statut
    {
        return $this->statut;
    }

    public function setStatut(?Statut $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getPriorite(): ?Priorite
    {
        return $this->priorite;
    }

    public function setPriorite(?Priorite $priorite): static
    {
        $this->priorite = $priorite;
        return $this;
    }

    public function getDateInstruction(): ?\DateTimeInterface
    {
        return $this->dateInstruction;
    }

    public function setDateInstruction(\DateTimeInterface $dateInstruction): static
    {
        $this->dateInstruction = $dateInstruction;
        return $this;
    }

    public function getDateReception(): ?\DateTimeInterface
    {
        return $this->dateReception;
    }

    public function setDateReception(?\DateTimeInterface $dateReception): static
    {
        $this->dateReception = $dateReception;
        return $this;
    }

    public function getDateEcheance(): ?\DateTimeInterface
    {
        return $this->dateEcheance;
    }

    public function setDateEcheance(?\DateTimeInterface $dateEcheance): static
    {
        $this->dateEcheance = $dateEcheance;
        return $this;
    }

    public function getEmetteur(): ?string
    {
        return $this->emetteur;
    }

    public function setEmetteur(?string $emetteur): static
    {
        $this->emetteur = $emetteur;
        return $this;
    }

    public function getEntitePilote(): ?Entite
    {
        return $this->entitePilote;
    }

    public function setEntitePilote(?Entite $entitePilote): static
    {
        $this->entitePilote = $entitePilote;
        return $this;
    }

    public function getResponsable(): ?Utilisateur
    {
        return $this->responsable;
    }

    public function setResponsable(?Utilisateur $responsable): static
    {
        $this->responsable = $responsable;
        return $this;
    }

    public function getTauxAvancement(): string
    {
        return $this->tauxAvancement;
    }

    public function setTauxAvancement(string|float $tauxAvancement): static
    {
        $this->tauxAvancement = number_format((float) $tauxAvancement, 2, '.', '');
        return $this;
    }

    public function getDateCloture(): ?\DateTimeInterface
    {
        return $this->dateCloture;
    }

    public function setDateCloture(?\DateTimeInterface $dateCloture): static
    {
        $this->dateCloture = $dateCloture;
        return $this;
    }

    public function getMotifCloture(): ?string
    {
        return $this->motifCloture;
    }

    public function setMotifCloture(?string $motifCloture): static
    {
        $this->motifCloture = $motifCloture;
        return $this;
    }

    public function getCreatedBy(): ?Utilisateur
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Utilisateur $createdBy): static
    {
        $this->createdBy = $createdBy;
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

    /**
     * @return Collection<int, Action>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    /**
     * @return Collection<int, InstructionEntite>
     */
    public function getInstructionEntites(): Collection
    {
        return $this->instructionEntites;
    }

    /**
     * @return Collection<int, Justificatif>
     */
    public function getJustificatifs(): Collection
    {
        return $this->justificatifs;
    }

    /**
     * @return Collection<int, Prorogation>
     */
    public function getProrogations(): Collection
    {
        return $this->prorogations;
    }

    /**
     * @return Collection<int, Relance>
     */
    public function getRelances(): Collection
    {
        return $this->relances;
    }

    /**
     * @return Collection<int, Commentaire>
     */
    public function getCommentaires(): Collection
    {
        return $this->commentaires;
    }

    /**
     * @return Collection<int, Historique>
     */
    public function getHistoriques(): Collection
    {
        return $this->historiques;
    }

    public function isEnRetard(): bool
    {
        if (in_array($this->statut?->getCode(), [Statut::CODE_EXECUTEE, Statut::CODE_CLOTUREE, Statut::CODE_ANNULEE])) {
            return false;
        }

        if ($this->dateEcheance !== null && $this->dateEcheance < new \DateTime('today')) {
            return true;
        }

        return false;
    }

    public function recalculerTauxAvancement(): void
    {
        if ($this->actions->count() === 0) {
            return;
        }

        $total = 0.0;
        foreach ($this->actions as $act) {
            $total += (float) $act->getTauxAvancement();
        }

        $this->setTauxAvancement($total / $this->actions->count());
    }

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeInterface $deletedAt): static
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getMotifSuppression(): ?string
    {
        return $this->motifSuppression;
    }

    public function setMotifSuppression(?string $motifSuppression): static
    {
        $this->motifSuppression = $motifSuppression;
        return $this;
    }

    public function __toString(): string
    {
        return $this->reference . ' - ' . $this->objet;
    }
}
