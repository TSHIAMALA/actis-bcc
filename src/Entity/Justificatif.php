<?php

namespace App\Entity;

use App\Repository\JustificatifRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JustificatifRepository::class)]
#[ORM\Table(name: 'justificatifs')]
class Justificatif
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instruction::class, inversedBy: 'justificatifs')]
    #[ORM\JoinColumn(name: 'instruction_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Instruction $instruction = null;

    #[ORM\ManyToOne(targetEntity: Action::class, inversedBy: 'justificatifs')]
    #[ORM\JoinColumn(name: 'action_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Action $action = null;

    #[ORM\Column(name: 'nom_fichier', length: 255)]
    private ?string $nomFichier = null;

    #[ORM\Column(name: 'chemin_fichier', length: 1000)]
    private ?string $cheminFichier = null;

    #[ORM\Column(name: 'type_mime', length: 150, nullable: true)]
    private ?string $typeMime = null;

    #[ORM\Column(name: 'taille_octets', type: 'bigint', nullable: true, options: ['unsigned' => true])]
    private ?int $tailleOctets = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'depose_par', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $deposePar = null;

    #[ORM\Column(name: 'date_depot', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $dateDepot = null;

    public function __construct()
    {
        $this->dateDepot = new \DateTime();
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

    public function getNomFichier(): ?string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(string $nomFichier): static
    {
        $this->nomFichier = $nomFichier;
        return $this;
    }

    public function getCheminFichier(): ?string
    {
        return $this->cheminFichier;
    }

    public function setCheminFichier(string $cheminFichier): static
    {
        $this->cheminFichier = $cheminFichier;
        return $this;
    }

    public function getTypeMime(): ?string
    {
        return $this->typeMime;
    }

    public function setTypeMime(?string $typeMime): static
    {
        $this->typeMime = $typeMime;
        return $this;
    }

    public function getTailleOctets(): ?int
    {
        return $this->tailleOctets;
    }

    public function setTailleOctets(?int $tailleOctets): static
    {
        $this->tailleOctets = $tailleOctets;
        return $this;
    }

    public function getTailleLisible(): string
    {
        if (!$this->tailleOctets) {
            return 'N/A';
        }
        $units = ['B', 'Ko', 'Mo', 'Go'];
        $bytes = $this->tailleOctets;
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
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

    public function getDeposePar(): ?Utilisateur
    {
        return $this->deposePar;
    }

    public function setDeposePar(?Utilisateur $deposePar): static
    {
        $this->deposePar = $deposePar;
        return $this;
    }

    public function getDateDepot(): ?\DateTimeInterface
    {
        return $this->dateDepot;
    }

    public function setDateDepot(\DateTimeInterface $dateDepot): static
    {
        $this->dateDepot = $dateDepot;
        return $this;
    }

    public function isPdf(): bool
    {
        return $this->typeMime === 'application/pdf' || str_ends_with(strtolower($this->nomFichier ?? ''), '.pdf');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->typeMime ?? '', 'image/');
    }

    public function __toString(): string
    {
        return (string) $this->nomFichier;
    }
}
