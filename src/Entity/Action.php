<?php

namespace App\Entity;

use App\Repository\ActionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActionRepository::class)]
#[ORM\Table(name: 'actions')]
class Action
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instruction::class, inversedBy: 'actions')]
    #[ORM\JoinColumn(name: 'instruction_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Instruction $instruction = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 500)]
    private ?string $libelle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Statut::class)]
    #[ORM\JoinColumn(name: 'statut_id', referencedColumnName: 'id', nullable: false)]
    private ?Statut $statut = null;

    #[ORM\ManyToOne(targetEntity: Priorite::class)]
    #[ORM\JoinColumn(name: 'priorite_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Priorite $priorite = null;

    #[ORM\ManyToOne(targetEntity: Entite::class)]
    #[ORM\JoinColumn(name: 'entite_responsable_id', referencedColumnName: 'id', nullable: false)]
    private ?Entite $entiteResponsable = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'responsable_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $responsable = null;

    #[ORM\Column(name: 'date_debut', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateDebut = null;

    #[ORM\Column(name: 'date_echeance', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateEcheance = null;

    #[ORM\Column(name: 'date_realisation', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateRealisation = null;

    #[ORM\Column(name: 'taux_avancement', type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '0.00'])]
    private string $tauxAvancement = '0.00';

    #[ORM\Column(name: 'resultat_attendu', type: Types::TEXT, nullable: true)]
    private ?string $resultatAttendu = null;

    #[ORM\Column(name: 'resultat_obtenu', type: Types::TEXT, nullable: true)]
    private ?string $resultatObtenu = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'action', targetEntity: Justificatif::class, cascade: ['remove'])]
    #[ORM\OrderBy(['dateDepot' => 'DESC'])]
    private Collection $justificatifs;

    #[ORM\OneToMany(mappedBy: 'action', targetEntity: Prorogation::class, cascade: ['remove'])]
    #[ORM\OrderBy(['dateDemande' => 'DESC'])]
    private Collection $prorogations;

    #[ORM\OneToMany(mappedBy: 'action', targetEntity: Relance::class, cascade: ['remove'])]
    #[ORM\OrderBy(['datePlanifiee' => 'DESC'])]
    private Collection $relances;

    #[ORM\OneToMany(mappedBy: 'action', targetEntity: Commentaire::class, cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $commentaires;

    #[ORM\OneToMany(mappedBy: 'action', targetEntity: Historique::class, cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $historiques;

    public function __construct()
    {
        $this->justificatifs = new ArrayCollection();
        $this->prorogations = new ArrayCollection();
        $this->relances = new ArrayCollection();
        $this->commentaires = new ArrayCollection();
        $this->historiques = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
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

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;
        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
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

    public function getEntiteResponsable(): ?Entite
    {
        return $this->entiteResponsable;
    }

    public function setEntiteResponsable(?Entite $entiteResponsable): static
    {
        $this->entiteResponsable = $entiteResponsable;
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

    public function getDateDebut(): ?\DateTimeInterface
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeInterface $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
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

    public function getDateRealisation(): ?\DateTimeInterface
    {
        return $this->dateRealisation;
    }

    public function setDateRealisation(?\DateTimeInterface $dateRealisation): static
    {
        $this->dateRealisation = $dateRealisation;
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

    public function getResultatAttendu(): ?string
    {
        return $this->resultatAttendu;
    }

    public function setResultatAttendu(?string $resultatAttendu): static
    {
        $this->resultatAttendu = $resultatAttendu;
        return $this;
    }

    public function getResultatObtenu(): ?string
    {
        return $this->resultatObtenu;
    }

    public function setResultatObtenu(?string $resultatObtenu): static
    {
        $this->resultatObtenu = $resultatObtenu;
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

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
