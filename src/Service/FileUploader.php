<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FileUploader
{
    public function __construct(
        private string $targetDirectory,
        private SluggerInterface $slugger
    ) {
    }

    public function upload(UploadedFile $file): array
    {
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $this->slugger->slug($originalFilename);
        $fileName = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
        $size = $file->getSize();

        if (!is_dir($this->targetDirectory)) {
            mkdir($this->targetDirectory, 0777, true);
        }

        try {
            $file->move($this->getTargetDirectory(), $fileName);
        } catch (FileException $e) {
            throw new \RuntimeException('Erreur lors du téléversement du fichier : ' . $e->getMessage());
        }

        return [
            'fileName' => $fileName,
            'originalName' => $file->getClientOriginalName(),
            'path' => 'uploads/justificatifs/' . $fileName,
            'mimeType' => $mimeType,
            'size' => $size,
        ];
    }

    public function getTargetDirectory(): string
    {
        return $this->targetDirectory;
    }
}
