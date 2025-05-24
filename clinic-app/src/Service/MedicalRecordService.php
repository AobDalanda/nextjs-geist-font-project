<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Doctor;
use App\Entity\MedicalRecord;
use App\Entity\MedicalNote;
use App\Entity\Prescription;
use App\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Psr\Log\LoggerInterface;

class MedicalRecordService
{
    private const ALLOWED_DOCUMENT_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const RECORD_TYPES = [
        'consultation',
        'prescription',
        'lab_result',
        'imaging',
        'vaccination',
        'allergy',
        'surgery',
        'chronic_condition',
    ];

    private $entityManager;
    private $security;
    private $logger;
    private $fileManager;
    private $auditService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        LoggerInterface $logger,
        FileManager $fileManager,
        AuditService $auditService
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->logger = $logger;
        $this->fileManager = $fileManager;
        $this->auditService = $auditService;
    }

    public function createMedicalRecord(User $patient, array $data): MedicalRecord
    {
        $this->validateAccess($patient);

        try {
            $record = new MedicalRecord();
            $record->setPatient($patient)
                  ->setDoctor($this->security->getUser())
                  ->setType($data['type'])
                  ->setTitle($data['title'])
                  ->setDescription($data['description'])
                  ->setDate(new \DateTime($data['date'] ?? 'now'))
                  ->setMetadata($data['metadata'] ?? []);

            $this->entityManager->persist($record);
            $this->entityManager->flush();

            $this->auditService->logMedicalEvent('medical_record_created', [
                'record_id' => $record->getId(),
                'patient_id' => $patient->getId(),
                'type' => $record->getType(),
            ]);

            return $record;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create medical record', [
                'error' => $e->getMessage(),
                'patient_id' => $patient->getId(),
            ]);
            throw $e;
        }
    }

    public function addMedicalNote(MedicalRecord $record, string $content, array $metadata = []): MedicalNote
    {
        $this->validateAccess($record->getPatient());

        try {
            $note = new MedicalNote();
            $note->setRecord($record)
                 ->setDoctor($this->security->getUser())
                 ->setContent($content)
                 ->setMetadata($metadata);

            $this->entityManager->persist($note);
            $this->entityManager->flush();

            $this->auditService->logMedicalEvent('medical_note_added', [
                'note_id' => $note->getId(),
                'record_id' => $record->getId(),
                'patient_id' => $record->getPatient()->getId(),
            ]);

            return $note;
        } catch (\Exception $e) {
            $this->logger->error('Failed to add medical note', [
                'error' => $e->getMessage(),
                'record_id' => $record->getId(),
            ]);
            throw $e;
        }
    }

    public function createPrescription(User $patient, array $data): Prescription
    {
        $this->validateAccess($patient);

        try {
            $prescription = new Prescription();
            $prescription->setPatient($patient)
                        ->setDoctor($this->security->getUser())
                        ->setMedications($data['medications'])
                        ->setInstructions($data['instructions'])
                        ->setStartDate(new \DateTime($data['start_date']))
                        ->setEndDate(new \DateTime($data['end_date']))
                        ->setMetadata($data['metadata'] ?? []);

            $this->entityManager->persist($prescription);
            $this->entityManager->flush();

            $this->auditService->logMedicalEvent('prescription_created', [
                'prescription_id' => $prescription->getId(),
                'patient_id' => $patient->getId(),
            ]);

            return $prescription;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create prescription', [
                'error' => $e->getMessage(),
                'patient_id' => $patient->getId(),
            ]);
            throw $e;
        }
    }

    public function attachDocument(MedicalRecord $record, UploadedFile $file, string $title, array $metadata = []): Document
    {
        $this->validateAccess($record->getPatient());
        $this->validateDocumentType($file);

        try {
            $document = new Document();
            $document->setRecord($record)
                    ->setTitle($title)
                    ->setType($file->getMimeType())
                    ->setSize($file->getSize())
                    ->setMetadata($metadata);

            // Upload file
            $path = $this->fileManager->uploadMedicalDocument($file, $record->getPatient());
            $document->setPath($path);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->auditService->logMedicalEvent('document_attached', [
                'document_id' => $document->getId(),
                'record_id' => $record->getId(),
                'patient_id' => $record->getPatient()->getId(),
            ]);

            return $document;
        } catch (\Exception $e) {
            $this->logger->error('Failed to attach document', [
                'error' => $e->getMessage(),
                'record_id' => $record->getId(),
            ]);
            throw $e;
        }
    }

    public function getMedicalHistory(User $patient): array
    {
        $this->validateAccess($patient);

        return [
            'records' => $this->entityManager->getRepository(MedicalRecord::class)
                ->findByPatient($patient),
            'prescriptions' => $this->entityManager->getRepository(Prescription::class)
                ->findByPatient($patient),
            'summary' => $this->generateMedicalSummary($patient),
        ];
    }

    public function searchMedicalRecords(User $patient, array $criteria = []): array
    {
        $this->validateAccess($patient);

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r')
           ->from(MedicalRecord::class, 'r')
           ->where('r.patient = :patient')
           ->setParameter('patient', $patient);

        if (isset($criteria['type'])) {
            $qb->andWhere('r.type = :type')
               ->setParameter('type', $criteria['type']);
        }

        if (isset($criteria['start_date'])) {
            $qb->andWhere('r.date >= :start_date')
               ->setParameter('start_date', new \DateTime($criteria['start_date']));
        }

        if (isset($criteria['end_date'])) {
            $qb->andWhere('r.date <= :end_date')
               ->setParameter('end_date', new \DateTime($criteria['end_date']));
        }

        if (isset($criteria['doctor'])) {
            $qb->andWhere('r.doctor = :doctor')
               ->setParameter('doctor', $criteria['doctor']);
        }

        return $qb->getQuery()->getResult();
    }

    public function generateMedicalSummary(User $patient): array
    {
        $this->validateAccess($patient);

        return [
            'allergies' => $this->getAllergies($patient),
            'chronic_conditions' => $this->getChronicConditions($patient),
            'current_medications' => $this->getCurrentMedications($patient),
            'recent_consultations' => $this->getRecentConsultations($patient),
            'vaccinations' => $this->getVaccinations($patient),
            'vital_signs' => $this->getVitalSigns($patient),
        ];
    }

    private function validateAccess(User $patient): void
    {
        $user = $this->security->getUser();

        if (!$user) {
            throw new BadRequestException('Authentication required.');
        }

        if (!$this->security->isGranted('ROLE_ADMIN') &&
            !$this->security->isGranted('ROLE_DOCTOR') &&
            $user !== $patient) {
            throw new BadRequestException('You do not have permission to access these medical records.');
        }
    }

    private function validateDocumentType(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), self::ALLOWED_DOCUMENT_TYPES)) {
            throw new BadRequestException(sprintf(
                'Invalid document type. Allowed types: %s',
                implode(', ', self::ALLOWED_DOCUMENT_TYPES)
            ));
        }
    }

    private function getAllergies(User $patient): array
    {
        return $this->entityManager->getRepository(MedicalRecord::class)
            ->findBy([
                'patient' => $patient,
                'type' => 'allergy',
            ]);
    }

    private function getChronicConditions(User $patient): array
    {
        return $this->entityManager->getRepository(MedicalRecord::class)
            ->findBy([
                'patient' => $patient,
                'type' => 'chronic_condition',
            ]);
    }

    private function getCurrentMedications(User $patient): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        return $qb->select('p')
            ->from(Prescription::class, 'p')
            ->where('p.patient = :patient')
            ->andWhere('p.endDate >= :today')
            ->setParameter('patient', $patient)
            ->setParameter('today', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    private function getRecentConsultations(User $patient, int $limit = 5): array
    {
        return $this->entityManager->getRepository(MedicalRecord::class)
            ->findBy(
                ['patient' => $patient, 'type' => 'consultation'],
                ['date' => 'DESC'],
                $limit
            );
    }

    private function getVaccinations(User $patient): array
    {
        return $this->entityManager->getRepository(MedicalRecord::class)
            ->findBy([
                'patient' => $patient,
                'type' => 'vaccination',
            ]);
    }

    private function getVitalSigns(User $patient): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        return $qb->select('r')
            ->from(MedicalRecord::class, 'r')
            ->where('r.patient = :patient')
            ->andWhere('r.type = :type')
            ->orderBy('r.date', 'DESC')
            ->setMaxResults(1)
            ->setParameter('patient', $patient)
            ->setParameter('type', 'vital_signs')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
