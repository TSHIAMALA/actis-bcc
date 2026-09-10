<?php

namespace App\Entity;

use App\Repository\TypeEvenementRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TypeEvenementRepository::class)]
#[ORM\Table(name: 'types_evenement')]
class TypeEvenement
{
    public const CREATION = 'CREATION';
    public const MODIFICATION = 'MODIFICATION';
    public const AFFECTATION = 'AFFECTATION';
    public const CHANGEMENT_STATUT = 'CHANGEMENT_STATUT';
    public const AVANCEMENT = 'AVANCEMENT';
    public const JUSTIFICATIF_AJOUTE = 'JUSTIFICATIF_AJOUTE';
    public const DEMANDE_PROROGATION = 'DEMANDE_PROROGATION';
    public const VALIDATION_PROROGATION = 'VALIDATION_PROROGATION';
    public const RELANCE_ENVOYEE = 'RELANCE_ENVOYEE';
    public const COMMENTAIRE_AJOUTE = 'COMMENTAIRE_AJOUTE';
    public const CLOTURE = 'CLOTURE';
    public const SUPPRESSION_LOGIQUE = 'SUPPRESSION_LOGIQUE';
    public const RESTAURATION = 'RESTAURATION';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 150)]
    private ?string $libelle = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

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

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
