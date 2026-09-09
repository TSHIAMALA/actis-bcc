<?php

namespace App\Entity;

use App\Repository\ProrogationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProrogationRepository::class)]
#[ORM\Table(name: 'prorogations')]
class Prorogation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instruction::class, inversedBy: 'prorogations')]
    #[ORM\JoinColumn(name: 'instruction_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Instruction $instruction = null;

    #[ORM\ManyToOne(targetEntity: Action::class, inversedBy: 'prorogations')]
    #[ORM\JoinColumn(name: 'action_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Action $action = null;

    #[ORM\Column(name: 'ancienne_echeance', type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $ancienneEcheance = null;

    #[ORM\Column(name: 'nouvelle_echeance', type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $nouvelleEcheance = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $motif = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'demande_par', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $demandePar = null;

    #[ORM\Column(name: 'date_demande', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $dateDemande = null;

    #[ORM\ManyToOne(targetEntity: StatutProrogation::class)]
    #[ORM\JoinColumn(name: 'statut_prorogation_id', referencedColumnName: 'id', nullable: false)]
    private ?StatutProrogation $statutProrogation = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'valide_par', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $validePar = null;

    #[ORM\Column(name: 'date_validation', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateValidation = null;

    #[ORM\Column(name: 'commentaire_validation', type: Types::TEXT, nullable: true)]
    private ?string $commentaireValidation = null;

    public function __construct()
    {
        $this->dateDemande = new \DateTime();
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

    public function getAncienneEcheance(): ?\DateTimeInterface
    {
        return $this->ancienneEcheance;
    }

    public function setAncienneEcheance(\DateTimeInterface $ancienneEcheance): static
    {
        $this->ancienneEcheance = $ancienneEcheance;
        return $this;
    }

    public function getNouvelleEcheance(): ?\DateTimeInterface
    {
        return $this->nouvelleEcheance;
    }

    public function setNouvelleEcheance(\DateTimeInterface $nouvelleEcheance): static
    {
        $this->nouvelleEcheance = $nouvelleEcheance;
        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = $motif;
        return $this;
    }

    public function getDemandePar(): ?Utilisateur
    {
        return $this->demandePar;
    }

    public function setDemandePar(?Utilisateur $demandePar): static
    {
        $this->demandePar = $demandePar;
        return $this;
    }

    public function getDateDemande(): ?\DateTimeInterface
    {
        return $this->dateDemande;
    }

    public function setDateDemande(\DateTimeInterface $dateDemande): static
    {
        $this->dateDemande = $dateDemande;
        return $this;
    }

    public function getStatutProrogation(): ?StatutProrogation
    {
        return $this->statutProrogation;
    }

    public function setStatutProrogation(?StatutProrogation $statutProrogation): static
    {
        $this->statutProrogation = $statutProrogation;
        return $this;
    }

    public function getValidePar(): ?Utilisateur
    {
        return $this->validePar;
    }

    public function setValidePar(?Utilisateur $validePar): static
    {
        $this->validePar = $validePar;
        return $this;
    }

    public function getDateValidation(): ?\DateTimeInterface
    {
        return $this->dateValidation;
    }

    public function setDateValidation(?\DateTimeInterface $dateValidation): static
    {
        $this->dateValidation = $dateValidation;
        return $this;
    }

    public function getCommentaireValidation(): ?string
    {
        return $this->commentaireValidation;
    }

    public function setCommentaireValidation(?string $commentaireValidation): static
    {
        $this->commentaireValidation = $commentaireValidation;
        return $this;
    }
}
