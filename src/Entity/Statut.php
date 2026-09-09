<?php

namespace App\Entity;

use App\Repository\StatutRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatutRepository::class)]
#[ORM\Table(name: 'statuts')]
class Statut
{
    public const CODE_BROUILLON = 'BROUILLON';
    public const CODE_AFFECTEE = 'AFFECTEE';
    public const CODE_EN_COURS = 'EN_COURS';
    public const CODE_EN_ATTENTE = 'EN_ATTENTE';
    public const CODE_A_VERIFIER = 'A_VERIFIER';
    public const CODE_EXECUTEE = 'EXECUTEE';
    public const CODE_CLOTUREE = 'CLOTUREE';
    public const CODE_ANNULEE = 'ANNULEE';
    public const CODE_NON_EXECUTEE = 'NON_EXECUTEE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 100)]
    private ?string $libelle = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $couleur = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $ordreAffichage = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
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

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;
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

    public function getOrdreAffichage(): int
    {
        return $this->ordreAffichage;
    }

    public function setOrdreAffichage(int $ordreAffichage): static
    {
        $this->ordreAffichage = $ordreAffichage;
        return $this;
    }

    public function getBadgeClass(): string
    {
        return match ($this->code) {
            self::CODE_BROUILLON => 'badge-secondary',
            self::CODE_AFFECTEE => 'badge-info',
            self::CODE_EN_COURS => 'badge-primary',
            self::CODE_EN_ATTENTE => 'badge-warning',
            self::CODE_A_VERIFIER => 'badge-purple',
            self::CODE_EXECUTEE => 'badge-success',
            self::CODE_CLOTUREE => 'badge-dark',
            self::CODE_ANNULEE, self::CODE_NON_EXECUTEE => 'badge-danger',
            default => 'badge-light',
        };
    }

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
