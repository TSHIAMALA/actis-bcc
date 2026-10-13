<?php

namespace App\Service;

use App\Entity\Instruction;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Stockage du PDF officiel d'une instruction / OS.
 * Les fichiers sont conservés hors de public/ : ils ne sont accessibles
 * qu'à travers le contrôleur, donc uniquement aux utilisateurs connectés.
 */
class InstructionDocumentStorage
{
    public function __construct(
        private string $targetDirectory,
        private SluggerInterface $slugger
    ) {
    }

    /**
     * Enregistre le PDF et l'associe à l'instruction (remplace l'éventuel document précédent).
     */
    public function store(Instruction $instruction, UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();
        $safeName = $this->slugger->slug(pathinfo($originalName, PATHINFO_FILENAME))->lower();
        $fileName = sprintf('%s-%s.pdf', $safeName, bin2hex(random_bytes(6)));

        if (!is_dir($this->targetDirectory)) {
            mkdir($this->targetDirectory, 0775, true);
        }

        try {
            $file->move($this->targetDirectory, $fileName);
        } catch (FileException $e) {
            throw new \RuntimeException('Erreur lors du téléversement du document : ' . $e->getMessage());
        }

        $this->remove($instruction);
        $instruction->setDocumentFichier($fileName);
        $instruction->setDocumentNomOriginal($originalName);
    }

    /**
     * Supprime le PDF du disque et le détache de l'instruction.
     */
    public function remove(Instruction $instruction): void
    {
        $path = $this->getPath($instruction);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        $instruction->setDocumentFichier(null);
        $instruction->setDocumentNomOriginal(null);
    }

    /**
     * Chemin absolu du PDF, ou null si l'instruction n'a pas de document.
     */
    public function getPath(Instruction $instruction): ?string
    {
        $fileName = $instruction->getDocumentFichier();
        if ($fileName === null) {
            return null;
        }

        // basename() : protection contre toute tentative de remontée de dossier
        return $this->targetDirectory . DIRECTORY_SEPARATOR . basename($fileName);
    }
}
