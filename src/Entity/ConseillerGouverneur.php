<?php

namespace App\Entity;

use App\Repository\ConseillerGouverneurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConseillerGouverneurRepository::class)]
#[ORM\Table(name: 'conseillers_gouverneur')]
class ConseillerGouverneur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'utilisateur_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(name: 'domaine_competence', length: 255)]
    private ?string $domaineCompetence = null;

    #[ORM\Column(name: 'code_domaine', length: 50, unique: true)]
    private ?string $codeDomaine = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'ordre_affichage', type: 'integer', options: ['default' => 0])]
    private int $ordreAffichage = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'conseiller', targetEntity: SuiviConseiller::class, cascade: ['remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $suivis;

    public function __construct()
    {
        $this->suivis = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDomaineCompetence(): ?string
    {
        return $this->domaineCompetence;
    }

    public function setDomaineCompetence(string $domaineCompetence): static
    {
        $this->domaineCompetence = $domaineCompetence;
        return $this;
    }

    public function getCodeDomaine(): ?string
    {
        return $this->codeDomaine;
    }

    public function setCodeDomaine(string $codeDomaine): static
    {
        $this->codeDomaine = $codeDomaine;
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

    public function getOrdreAffichage(): int
    {
        return $this->ordreAffichage;
    }

    public function setOrdreAffichage(int $ordreAffichage): static
    {
        $this->ordreAffichage = $ordreAffichage;
        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
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
     * @return Collection<int, SuiviConseiller>
     */
    public function getSuivis(): Collection
    {
        return $this->suivis;
    }

    /**
     * Nombre de suivis en attente d'examen
     */
    public function countSuivisEnAttente(): int
    {
        return $this->suivis->filter(
            fn(SuiviConseiller $s) => $s->getStatutSuivi() === SuiviConseiller::STATUT_EN_ATTENTE
        )->count();
    }

    /**
     * Nombre de suivis approuvés
     */
    public function countSuivisApprouves(): int
    {
        return $this->suivis->filter(
            fn(SuiviConseiller $s) => $s->getStatutSuivi() === SuiviConseiller::STATUT_APPROUVE
        )->count();
    }

    public function getNomComplet(): string
    {
        return $this->codeDomaine . ' — ' . $this->domaineCompetence;
    }

    public function __toString(): string
    {
        $nom = $this->utilisateur ? $this->utilisateur->getNomComplet() : 'N/A';
        return $nom . ' (' . $this->domaineCompetence . ')';
    }
}
