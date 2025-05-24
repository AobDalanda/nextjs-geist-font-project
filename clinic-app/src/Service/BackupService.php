<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Security;
use Psr\Log\LoggerInterface;
use ZipArchive;

class BackupService
{
    private const BACKUP_TYPES = ['full', 'database', 'files', 'config'];
    private const MAX_BACKUPS = 10;
    private const BACKUP_RETENTION_DAYS = 30;

    private $entityManager;
    private $params;
    private $filesystem;
    private $security;
    private $logger;
    private $settingsService;

    private $backupDir;
    private $projectDir;
    private $databaseUrl;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $params,
        Security $security,
        LoggerInterface $logger,
        SettingsService $settingsService
    ) {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->security = $security;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->filesystem = new Filesystem();

        $this->initializeBackupConfiguration();
    }

    public function createBackup(string $type = 'full', array $options = []): string
    {
        if (!in_array($type, self::BACKUP_TYPES)) {
            throw new \InvalidArgumentException(sprintf('Invalid backup type: %s', $type));
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = sprintf('%s/%s_%s.zip', $this->backupDir, $type, $timestamp);

        try {
            $this->logger->info('Starting backup process', [
                'type' => $type,
                'path' => $backupPath,
            ]);

            $zip = new ZipArchive();
            if ($zip->open($backupPath, ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Failed to create backup archive');
            }

            switch ($type) {
                case 'full':
                    $this->backupDatabase($zip);
                    $this->backupFiles($zip);
                    $this->backupConfig($zip);
                    break;
                case 'database':
                    $this->backupDatabase($zip);
                    break;
                case 'files':
                    $this->backupFiles($zip);
                    break;
                case 'config':
                    $this->backupConfig($zip);
                    break;
            }

            $zip->close();

            // Add metadata file
            $this->addBackupMetadata($backupPath, $type, $options);

            // Cleanup old backups
            $this->cleanupOldBackups();

            $this->logger->info('Backup completed successfully', [
                'path' => $backupPath,
                'size' => filesize($backupPath),
            ]);

            return $backupPath;
        } catch (\Exception $e) {
            $this->logger->error('Backup failed', [
                'error' => $e->getMessage(),
                'type' => $type,
            ]);
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }
            throw $e;
        }
    }

    public function restoreBackup(string $backupPath, array $options = []): void
    {
        if (!file_exists($backupPath)) {
            throw new \InvalidArgumentException('Backup file not found');
        }

        try {
            $this->logger->info('Starting restore process', [
                'path' => $backupPath,
            ]);

            $zip = new ZipArchive();
            if ($zip->open($backupPath) !== true) {
                throw new \RuntimeException('Failed to open backup archive');
            }

            // Read metadata
            $metadata = $this->getBackupMetadata($backupPath);
            
            // Verify backup integrity
            $this->verifyBackupIntegrity($zip, $metadata);

            // Create restore point before proceeding
            $this->createRestorePoint();

            // Perform restore based on backup type
            switch ($metadata['type']) {
                case 'full':
                    $this->restoreDatabase($zip);
                    $this->restoreFiles($zip);
                    $this->restoreConfig($zip);
                    break;
                case 'database':
                    $this->restoreDatabase($zip);
                    break;
                case 'files':
                    $this->restoreFiles($zip);
                    break;
                case 'config':
                    $this->restoreConfig($zip);
                    break;
            }

            $zip->close();

            $this->logger->info('Restore completed successfully', [
                'path' => $backupPath,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Restore failed', [
                'error' => $e->getMessage(),
                'path' => $backupPath,
            ]);
            throw $e;
        }
    }

    public function listBackups(): array
    {
        $backups = [];
        foreach (glob($this->backupDir . '/*.zip') as $file) {
            $metadata = $this->getBackupMetadata($file);
            $backups[] = [
                'path' => $file,
                'filename' => basename($file),
                'size' => filesize($file),
                'created_at' => filemtime($file),
                'type' => $metadata['type'] ?? 'unknown',
                'metadata' => $metadata,
            ];
        }

        usort($backups, function ($a, $b) {
            return $b['created_at'] - $a['created_at'];
        });

        return $backups;
    }

    public function deleteBackup(string $backupPath): void
    {
        if (!file_exists($backupPath)) {
            throw new \InvalidArgumentException('Backup file not found');
        }

        try {
            unlink($backupPath);
            $this->logger->info('Backup deleted', ['path' => $backupPath]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete backup', [
                'error' => $e->getMessage(),
                'path' => $backupPath,
            ]);
            throw $e;
        }
    }

    private function initializeBackupConfiguration(): void
    {
        $this->projectDir = $this->params->get('kernel.project_dir');
        $this->backupDir = $this->projectDir . '/var/backups';
        $this->databaseUrl = $this->params->get('DATABASE_URL');

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0777, true);
        }
    }

    private function backupDatabase(ZipArchive $zip): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'db_backup_');
        
        // Execute mysqldump
        $process = new Process([
            'mysqldump',
            '--host=' . parse_url($this->databaseUrl, PHP_URL_HOST),
            '--user=' . parse_url($this->databaseUrl, PHP_URL_USER),
            '--password=' . parse_url($this->databaseUrl, PHP_URL_PASS),
            parse_url($this->databaseUrl, PHP_URL_PATH),
        ]);
        
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Database backup failed: ' . $process->getErrorOutput());
        }

        file_put_contents($tmpFile, $process->getOutput());
        $zip->addFile($tmpFile, 'database/dump.sql');
        unlink($tmpFile);
    }

    private function backupFiles(ZipArchive $zip): void
    {
        $directories = [
            'public/uploads',
            'config',
            'templates',
            'src',
        ];

        foreach ($directories as $dir) {
            $path = $this->projectDir . '/' . $dir;
            if (is_dir($path)) {
                $this->addDirectoryToZip($zip, $path, $dir);
            }
        }
    }

    private function backupConfig(ZipArchive $zip): void
    {
        $configFiles = [
            '.env',
            '.env.local',
            'composer.json',
            'composer.lock',
            'symfony.lock',
        ];

        foreach ($configFiles as $file) {
            $path = $this->projectDir . '/' . $file;
            if (file_exists($path)) {
                $zip->addFile($path, 'config/' . $file);
            }
        }
    }

    private function addDirectoryToZip(ZipArchive $zip, string $path, string $relativePath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $zip->addFile($filePath, $relativePath . '/' . $iterator->getSubPathName());
            }
        }
    }

    private function addBackupMetadata(string $backupPath, string $type, array $options): void
    {
        $metadata = [
            'type' => $type,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $this->security->getUser()?->getUserIdentifier(),
            'version' => $this->params->get('app.version'),
            'options' => $options,
            'checksum' => md5_file($backupPath),
        ];

        file_put_contents(
            $backupPath . '.meta',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );
    }

    private function getBackupMetadata(string $backupPath): array
    {
        $metaFile = $backupPath . '.meta';
        if (!file_exists($metaFile)) {
            return [];
        }

        return json_decode(file_get_contents($metaFile), true) ?? [];
    }

    private function verifyBackupIntegrity(ZipArchive $zip, array $metadata): void
    {
        if (empty($metadata['checksum'])) {
            throw new \RuntimeException('Backup metadata is missing checksum');
        }

        if ($metadata['checksum'] !== md5_file($zip->filename)) {
            throw new \RuntimeException('Backup file is corrupted');
        }
    }

    private function createRestorePoint(): void
    {
        $this->createBackup('full', ['is_restore_point' => true]);
    }

    private function cleanupOldBackups(): void
    {
        $backups = $this->listBackups();
        
        // Remove backups exceeding MAX_BACKUPS
        if (count($backups) > self::MAX_BACKUPS) {
            $toDelete = array_slice($backups, self::MAX_BACKUPS);
            foreach ($toDelete as $backup) {
                $this->deleteBackup($backup['path']);
            }
        }

        // Remove backups older than BACKUP_RETENTION_DAYS
        $cutoff = strtotime('-' . self::BACKUP_RETENTION_DAYS . ' days');
        foreach ($backups as $backup) {
            if ($backup['created_at'] < $cutoff) {
                $this->deleteBackup($backup['path']);
            }
        }
    }
}
