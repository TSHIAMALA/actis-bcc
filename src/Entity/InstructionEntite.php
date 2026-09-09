<?php

namespace App\Entity;

use App\Repository\InstructionEntiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstructionEntiteRepository::class)]
#[ORM\Table(name: 'instruction_entites')]
class InstructionEntite
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Instruction::class, inversedBy: 'instructionEntites')]
    #[ORM\JoinColumn(name: 'instruction_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Instruction $instruction = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entite::class)]
    #[ORM\JoinColumn(name: 'entite_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    #[ORM\Column(name: 'role_entite', length: 50, options: ['default' => 'CONTRIBUTEUR'])]
    private string $roleEntite = 'CONTRIBUTEUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;
        return $this;
    }

    public function getRoleEntite(): string
    {
        return $this->roleEntite;
    }

    public function setRoleEntite(string $roleEntite): static
    {
        $this->roleEntite = $roleEntite;
        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;
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
