<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Doctor;
use App\Entity\Appointment;
use App\Entity\MedicalRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class SecurityService
{
    private const ACCESS_LEVELS = [
        'VIEW' => 1,
        'EDIT' => 2,
        'DELETE' => 3,
        'ADMIN' => 4,
    ];

    private const SENSITIVE_ACTIONS = [
        'view_medical_records',
        'edit_medical_records',
        'delete_medical_records',
        'prescribe_medication',
        'view_patient_history',
        'export_data',
        'manage_users',
        'manage_doctors',
        'system_settings',
    ];

    private $entityManager;
    private $security;
    private $tokenStorage;
    private $authChecker;
    private $requestStack;
    private $logger;
    private $auditService;
    private $settingsService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        TokenStorageInterface $tokenStorage,
        AuthorizationCheckerInterface $authChecker,
        RequestStack $requestStack,
        LoggerInterface $logger,
        AuditService $auditService,
        SettingsService $settingsService
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->tokenStorage = $tokenStorage;
        $this->authChecker = $authChecker;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->auditService = $auditService;
        $this->settingsService = $settingsService;
    }

    public function checkAccess(string $action, $subject = null): bool
    {
        $user = $this->security->getUser();
        if (!$user) {
            return false;
        }

        try {
            switch ($action) {
                case 'view_medical_records':
                    return $this->canViewMedicalRecords($user, $subject);
                
                case 'edit_medical_records':
                    return $this->canEditMedicalRecords($user, $subject);
                
                case 'delete_medical_records':
                    return $this->canDeleteMedicalRecords($user, $subject);
                
                case 'prescribe_medication':
                    return $this->canPrescribeMedication($user, $subject);
                
                case 'view_patient_history':
                    return $this->canViewPatientHistory($user, $subject);
                
                case 'export_data':
                    return $this->canExportData($user, $subject);
                
                case 'manage_users':
                    return $this->security->isGranted('ROLE_ADMIN');
                
                case 'manage_doctors':
                    return $this->security->isGranted('ROLE_ADMIN');
                
                case 'system_settings':
                    return $this->security->isGranted('ROLE_ADMIN');
                
                default:
                    return false;
            }
        } catch (\Exception $e) {
            $this->logger->error('Access check failed', [
                'action' => $action,
                'user' => $user->getId(),
                'subject' => $subject ? get_class($subject) : null,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function enforceAccess(string $action, $subject = null): void
    {
        if (!$this->checkAccess($action, $subject)) {
            $this->logUnauthorizedAccess($action, $subject);
            throw new AccessDeniedException('Access Denied');
        }
    }

    public function isActionSensitive(string $action): bool
    {
        return in_array($action, self::SENSITIVE_ACTIONS);
    }

    public function validateUserAccess(User $user, User $subject): bool
    {
        // Users can always access their own data
        if ($user === $subject) {
            return true;
        }

        // Doctors can access their patients' data
        if ($user instanceof Doctor && $this->isPatientOfDoctor($subject, $user)) {
            return true;
        }

        // Admins can access all user data
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return false;
    }

    public function validateAppointmentAccess(User $user, Appointment $appointment): bool
    {
        // Patient can access their own appointments
        if ($user === $appointment->getPatient()) {
            return true;
        }

        // Doctor can access appointments where they are the assigned doctor
        if ($user === $appointment->getDoctor()) {
            return true;
        }

        // Admins can access all appointments
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return false;
    }

    public function validateMedicalRecordAccess(User $user, MedicalRecord $record, int $accessLevel): bool
    {
        // Patient can view their own records
        if ($user === $record->getPatient() && $accessLevel === self::ACCESS_LEVELS['VIEW']) {
            return true;
        }

        // Doctor can access records of their patients
        if ($user instanceof Doctor && $this->isPatientOfDoctor($record->getPatient(), $user)) {
            return true;
        }

        // Admins have full access
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return false;
    }

    public function getAccessibleUsers(User $user): array
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $this->entityManager->getRepository(User::class)->findAll();
        }

        if ($user instanceof Doctor) {
            return $this->entityManager->getRepository(User::class)
                ->findPatientsByDoctor($user);
        }

        return [$user];
    }

    public function getAccessibleAppointments(User $user): array
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $this->entityManager->getRepository(Appointment::class)->findAll();
        }

        if ($user instanceof Doctor) {
            return $this->entityManager->getRepository(Appointment::class)
                ->findByDoctor($user);
        }

        return $this->entityManager->getRepository(Appointment::class)
            ->findByPatient($user);
    }

    private function canViewMedicalRecords(User $user, ?MedicalRecord $record = null): bool
    {
        if ($record) {
            return $this->validateMedicalRecordAccess($user, $record, self::ACCESS_LEVELS['VIEW']);
        }

        return $user instanceof Doctor || $this->security->isGranted('ROLE_ADMIN');
    }

    private function canEditMedicalRecords(User $user, ?MedicalRecord $record = null): bool
    {
        if (!$user instanceof Doctor && !$this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }

        if ($record) {
            return $this->validateMedicalRecordAccess($user, $record, self::ACCESS_LEVELS['EDIT']);
        }

        return true;
    }

    private function canDeleteMedicalRecords(User $user, ?MedicalRecord $record = null): bool
    {
        // Only admins can delete medical records
        return $this->security->isGranted('ROLE_ADMIN');
    }

    private function canPrescribeMedication(User $user, ?User $patient = null): bool
    {
        if (!$user instanceof Doctor) {
            return false;
        }

        if ($patient) {
            return $this->isPatientOfDoctor($patient, $user);
        }

        return true;
    }

    private function canViewPatientHistory(User $user, ?User $patient = null): bool
    {
        if ($patient) {
            return $this->validateUserAccess($user, $patient);
        }

        return $user instanceof Doctor || $this->security->isGranted('ROLE_ADMIN');
    }

    private function canExportData(User $user, ?string $dataType = null): bool
    {
        // Only admins can export data
        return $this->security->isGranted('ROLE_ADMIN');
    }

    private function isPatientOfDoctor(User $patient, Doctor $doctor): bool
    {
        return $this->entityManager->getRepository(Appointment::class)
            ->existsForPatientAndDoctor($patient, $doctor);
    }

    private function logUnauthorizedAccess(string $action, $subject = null): void
    {
        $user = $this->security->getUser();
        $request = $this->requestStack->getCurrentRequest();

        $this->logger->warning('Unauthorized access attempt', [
            'action' => $action,
            'user_id' => $user ? $user->getId() : null,
            'subject' => $subject ? get_class($subject) : null,
            'ip' => $request ? $request->getClientIp() : null,
            'url' => $request ? $request->getUri() : null,
        ]);

        $this->auditService->log('security', 'unauthorized_access', [
            'action' => $action,
            'user_id' => $user ? $user->getId() : null,
            'subject' => $subject ? get_class($subject) : null,
            'ip' => $request ? $request->getClientIp() : null,
        ]);
    }
}
