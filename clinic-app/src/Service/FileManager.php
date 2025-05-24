<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Mime\MimeTypes;
use Psr\Log\LoggerInterface;

class FileManager
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
    ];

    private const MAX_FILE_SIZE = 10485760; // 10MB
    private const UPLOAD_DIRECTORIES = [
        'medical_records' => 'uploads/medical_records',
        'prescriptions' => 'uploads/prescriptions',
        'lab_results' => 'uploads/lab_results',
        'profile_pictures' => 'uploads/profiles',
        'temp' => 'uploads/temp',
    ];

    private $entityManager;
    private $security;
    private $slugger;
    private $logger;
    private $projectDir;
    private $mimeTypes;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        SluggerInterface $slugger,
        LoggerInterface $logger,
        string $projectDir
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->slugger = $slugger;
        $this->logger = $logger;
        $this->projectDir = $projectDir;
        $this->mimeTypes = new MimeTypes();

        $this->initializeDirectories();
    }

    public function uploadFile(UploadedFile $file, string $directory, array $options = []): Document
    {
        $this->validateUpload($file, $directory);

        try {
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

            $document = new Document();
            $document->setOriginalName($file->getClientOriginalName())
                    ->setFilename($newFilename)
                    ->setMimeType($file->getMimeType())
                    ->setSize($file->getSize())
                    ->setPath($this->getRelativePath($directory, $newFilename))
                    ->setUploadedBy($this->security->getUser())
                    ->setMetadata($options['metadata'] ?? []);

            $file->move(
                $this->getUploadPath($directory),
                $newFilename
            );

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->logger->info('File uploaded successfully', [
                'document_id' => $document->getId(),
                'filename' => $newFilename,
                'directory' => $directory,
            ]);

            return $document;
        } catch (\Exception $e) {
            $this->logger->error('File upload failed', [
                'error' => $e->getMessage(),
                'filename' => $file->getClientOriginalName(),
            ]);
            throw $e;
        }
    }

    public function deleteFile(Document $document): void
    {
        try {
            $filePath = $this->getAbsolutePath($document->getPath());
            
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $this->entityManager->remove($document);
            $this->entityManager->flush();

            $this->logger->info('File deleted successfully', [
                'document_id' => $document->getId(),
                'path' => $document->getPath(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('File deletion failed', [
                'error' => $e->getMessage(),
                'document_id' => $document->getId(),
            ]);
            throw $e;
        }
    }

    public function moveFile(Document $document, string $newDirectory): void
    {
        try {
            $currentPath = $this->getAbsolutePath($document->getPath());
            $newRelativePath = $this->getRelativePath($newDirectory, $document->getFilename());
            $newPath = $this->getAbsolutePath($newRelativePath);

            if (!file_exists($currentPath)) {
                throw new FileException('Source file does not exist');
            }

            if (!rename($currentPath, $newPath)) {
                throw new FileException('Failed to move file');
            }

            $document->setPath($newRelativePath);
            $this->entityManager->flush();

            $this->logger->info('File moved successfully', [
                'document_id' => $document->getId(),
                'from' => $document->getPath(),
                'to' => $newRelativePath,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('File move failed', [
                'error' => $e->getMessage(),
                'document_id' => $document->getId(),
            ]);
            throw $e;
        }
    }

    public function getFileContent(Document $document): string
    {
        $path = $this->getAbsolutePath($document->getPath());
        
        if (!file_exists($path)) {
            throw new FileException('File not found');
        }

        return file_get_contents($path);
    }

    public function createThumbnail(Document $document, int $width = 150, int $height = 150): ?Document
    {
        if (!str_starts_with($document->getMimeType(), 'image/')) {
            return null;
        }

        try {
            $originalPath = $this->getAbsolutePath($document->getPath());
            $thumbnailFilename = 'thumb_' . $document->getFilename();
            $thumbnailPath = $this->getUploadPath('temp') . '/' . $thumbnailFilename;

            $image = imagecreatefromstring(file_get_contents($originalPath));
            $thumbnail = imagecreatetruecolor($width, $height);

            imagecopyresampled(
                $thumbnail,
                $image,
                0, 0, 0, 0,
                $width, $height,
                imagesx($image), imagesy($image)
            );

            imagejpeg($thumbnail, $thumbnailPath);

            $thumbnailDocument = new Document();
            $thumbnailDocument->setOriginalName('thumbnail_' . $document->getOriginalName())
                            ->setFilename($thumbnailFilename)
                            ->setMimeType('image/jpeg')
                            ->setSize(filesize($thumbnailPath))
                            ->setPath($this->getRelativePath('temp', $thumbnailFilename))
                            ->setUploadedBy($this->security->getUser())
                            ->setMetadata(['parent_document_id' => $document->getId()]);

            $this->entityManager->persist($thumbnailDocument);
            $this->entityManager->flush();

            return $thumbnailDocument;
        } catch (\Exception $e) {
            $this->logger->error('Thumbnail creation failed', [
                'error' => $e->getMessage(),
                'document_id' => $document->getId(),
            ]);
            return null;
        }
    }

    public function cleanupTempFiles(int $maxAge = 3600): void
    {
        try {
            $tempDir = $this->getUploadPath('temp');
            $now = time();

            foreach (new \DirectoryIterator($tempDir) as $file) {
                if ($file->isFile() && ($now - $file->getCTime() >= $maxAge)) {
                    unlink($file->getRealPath());
                }
            }

            $this->logger->info('Temporary files cleaned up');
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup temporary files', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function validateUpload(UploadedFile $file, string $directory): void
    {
        if (!isset(self::UPLOAD_DIRECTORIES[$directory])) {
            throw new FileException('Invalid upload directory');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new FileException('Invalid file type');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new FileException('File size exceeds maximum allowed size');
        }
    }

    private function initializeDirectories(): void
    {
        foreach (self::UPLOAD_DIRECTORIES as $dir) {
            $path = $this->projectDir . '/public/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
    }

    private function getUploadPath(string $directory): string
    {
        return $this->projectDir . '/public/' . self::UPLOAD_DIRECTORIES[$directory];
    }

    private function getRelativePath(string $directory, string $filename): string
    {
        return self::UPLOAD_DIRECTORIES[$directory] . '/' . $filename;
    }

    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/public/' . $relativePath;
    }

    public function getUploadDirectories(): array
    {
        return array_keys(self::UPLOAD_DIRECTORIES);
    }

    public function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }
}
