<?php

namespace App\Entity;

use App\Repository\HistoriqueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HistoriqueRepository::class)]
#[ORM\Table(name: 'historique')]
class Historique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instruction::class, inversedBy: 'historiques')]
    #[ORM\JoinColumn(name: 'instruction_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Instruction $instruction = null;

    #[ORM\ManyToOne(targetEntity: Action::class, inversedBy: 'historiques')]
    #[ORM\JoinColumn(name: 'action_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Action $action = null;

    #[ORM\ManyToOne(targetEntity: TypeEvenement::class)]
    #[ORM\JoinColumn(name: 'type_evenement_id', referencedColumnName: 'id', nullable: false)]
    private ?TypeEvenement $typeEvenement = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'utilisateur_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\ManyToOne(targetEntity: Entite::class)]
    #[ORM\JoinColumn(name: 'entite_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Entite $entite = null;

    #[ORM\ManyToOne(targetEntity: Statut::class)]
    #[ORM\JoinColumn(name: 'ancien_statut_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Statut $ancienStatut = null;

    #[ORM\ManyToOne(targetEntity: Statut::class)]
    #[ORM\JoinColumn(name: 'nouveau_statut_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Statut $nouveauStatut = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'donnees_avant', type: Types::JSON, nullable: true)]
    private ?array $donneesAvant = null;

    #[ORM\Column(name: 'donnees_apres', type: Types::JSON, nullable: true)]
    private ?array $donneesApres = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
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

    public function getTypeEvenement(): ?TypeEvenement
    {
        return $this->typeEvenement;
    }

    public function setTypeEvenement(?TypeEvenement $typeEvenement): static
    {
        $this->typeEvenement = $typeEvenement;
        return $this;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;
        return $this;
    }

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;
        return $this;
    }

    public function getAncienStatut(): ?Statut
    {
        return $this->ancienStatut;
    }

    public function setAncienStatut(?Statut $ancienStatut): static
    {
        $this->ancienStatut = $ancienStatut;
        return $this;
    }

    public function getNouveauStatut(): ?Statut
    {
        return $this->nouveauStatut;
    }

    public function setNouveauStatut(?Statut $nouveauStatut): static
    {
        $this->nouveauStatut = $nouveauStatut;
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

    public function getDonneesAvant(): ?array
    {
        return $this->donneesAvant;
    }

    public function setDonneesAvant(?array $donneesAvant): static
    {
        $this->donneesAvant = $donneesAvant;
        return $this;
    }

    public function getDonneesApres(): ?array
    {
        return $this->donneesApres;
    }

    public function setDonneesApres(?array $donneesApres): static
    {
        $this->donneesApres = $donneesApres;
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
