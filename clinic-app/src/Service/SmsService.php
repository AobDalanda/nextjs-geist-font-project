<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\User;
use App\Entity\SmsLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;

class SmsService
{
    private const SMS_TYPES = [
        'appointment_confirmation',
        'appointment_reminder',
        'appointment_cancellation',
        'appointment_modification',
        'doctor_unavailable',
        'password_reset',
        'verification_code',
    ];

    private const SMS_PROVIDERS = [
        'twilio' => \App\Service\SmsProvider\TwilioProvider::class,
        'nexmo' => \App\Service\SmsProvider\NexmoProvider::class,
        'ovh' => \App\Service\SmsProvider\OvhProvider::class,
    ];

    private $entityManager;
    private $params;
    private $logger;
    private $settingsService;
    private $provider;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $params,
        LoggerInterface $logger,
        SettingsService $settingsService
    ) {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->initializeProvider();
    }

    public function sendAppointmentConfirmation(Appointment $appointment): bool
    {
        $patient = $appointment->getPatient();
        if (!$this->canReceiveSms($patient)) {
            return false;
        }

        $message = $this->renderMessage('appointment_confirmation', [
            'patient_name' => $patient->getFullName(),
            'doctor_name' => $appointment->getDoctor()->getFullName(),
            'date' => $appointment->getDateTime()->format('d/m/Y'),
            'time' => $appointment->getDateTime()->format('H:i'),
            'service' => $appointment->getService()->getName(),
        ]);

        return $this->send($patient->getPhoneNumber(), $message, 'appointment_confirmation', [
            'appointment_id' => $appointment->getId(),
        ]);
    }

    public function sendAppointmentReminder(Appointment $appointment): bool
    {
        $patient = $appointment->getPatient();
        if (!$this->canReceiveSms($patient)) {
            return false;
        }

        $message = $this->renderMessage('appointment_reminder', [
            'patient_name' => $patient->getFullName(),
            'doctor_name' => $appointment->getDoctor()->getFullName(),
            'date' => $appointment->getDateTime()->format('d/m/Y'),
            'time' => $appointment->getDateTime()->format('H:i'),
        ]);

        return $this->send($patient->getPhoneNumber(), $message, 'appointment_reminder', [
            'appointment_id' => $appointment->getId(),
        ]);
    }

    public function sendAppointmentCancellation(Appointment $appointment, string $reason = null): bool
    {
        $patient = $appointment->getPatient();
        if (!$this->canReceiveSms($patient)) {
            return false;
        }

        $message = $this->renderMessage('appointment_cancellation', [
            'patient_name' => $patient->getFullName(),
            'doctor_name' => $appointment->getDoctor()->getFullName(),
            'date' => $appointment->getDateTime()->format('d/m/Y'),
            'time' => $appointment->getDateTime()->format('H:i'),
            'reason' => $reason,
        ]);

        return $this->send($patient->getPhoneNumber(), $message, 'appointment_cancellation', [
            'appointment_id' => $appointment->getId(),
            'reason' => $reason,
        ]);
    }

    public function sendVerificationCode(User $user, string $code): bool
    {
        if (!$this->canReceiveSms($user)) {
            return false;
        }

        $message = $this->renderMessage('verification_code', [
            'code' => $code,
            'expiry_minutes' => 15,
        ]);

        return $this->send($user->getPhoneNumber(), $message, 'verification_code', [
            'user_id' => $user->getId(),
        ]);
    }

    public function sendPasswordResetCode(User $user, string $code): bool
    {
        if (!$this->canReceiveSms($user)) {
            return false;
        }

        $message = $this->renderMessage('password_reset', [
            'code' => $code,
            'expiry_minutes' => 15,
        ]);

        return $this->send($user->getPhoneNumber(), $message, 'password_reset', [
            'user_id' => $user->getId(),
        ]);
    }

    private function send(string $phoneNumber, string $message, string $type, array $metadata = []): bool
    {
        if (!$this->isEnabled()) {
            $this->logger->warning('SMS service is disabled');
            return false;
        }

        try {
            // Normalize phone number
            $phoneNumber = $this->normalizePhoneNumber($phoneNumber);

            // Send SMS through provider
            $result = $this->provider->send($phoneNumber, $message);

            // Log the SMS
            $this->logSms($phoneNumber, $message, $type, $metadata, $result);

            $this->logger->info('SMS sent successfully', [
                'phone_number' => $phoneNumber,
                'type' => $type,
                'metadata' => $metadata,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send SMS', [
                'phone_number' => $phoneNumber,
                'type' => $type,
                'error' => $e->getMessage(),
                'metadata' => $metadata,
            ]);

            return false;
        }
    }

    private function renderMessage(string $type, array $parameters = []): string
    {
        $template = $this->getMessageTemplate($type);
        
        return preg_replace_callback('/\{(\w+)\}/', function($matches) use ($parameters) {
            return $parameters[$matches[1]] ?? $matches[0];
        }, $template);
    }

    private function getMessageTemplate(string $type): string
    {
        $templates = [
            'appointment_confirmation' => "Bonjour {patient_name}, votre RDV avec {doctor_name} est confirmé pour le {date} à {time}. Service: {service}",
            'appointment_reminder' => "Rappel: Votre RDV avec {doctor_name} est demain {date} à {time}.",
            'appointment_cancellation' => "Votre RDV du {date} à {time} avec {doctor_name} a été annulé. Raison: {reason}",
            'verification_code' => "Votre code de vérification est: {code}. Il expire dans {expiry_minutes} minutes.",
            'password_reset' => "Votre code de réinitialisation est: {code}. Il expire dans {expiry_minutes} minutes.",
        ];

        return $templates[$type] ?? '';
    }

    private function logSms(string $phoneNumber, string $message, string $type, array $metadata, array $result): void
    {
        $log = new SmsLog();
        $log->setPhoneNumber($phoneNumber)
            ->setMessage($message)
            ->setType($type)
            ->setMetadata($metadata)
            ->setProvider($this->getProviderName())
            ->setProviderId($result['provider_id'] ?? null)
            ->setStatus($result['status'] ?? 'unknown')
            ->setSentAt(new \DateTime())
            ->setCost($result['cost'] ?? 0);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        // Remove all non-digit characters
        $number = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Ensure number starts with country code
        if (!str_starts_with($number, '+')) {
            $number = '+33' . ltrim($number, '0'); // Default to French numbers
        }

        return $number;
    }

    private function initializeProvider(): void
    {
        $providerName = $this->settingsService->get('sms_provider', 'twilio');
        
        if (!isset(self::SMS_PROVIDERS[$providerName])) {
            throw new \RuntimeException(sprintf('Unknown SMS provider: %s', $providerName));
        }

        $providerClass = self::SMS_PROVIDERS[$providerName];
        $this->provider = new $providerClass(
            $this->settingsService->get('sms_' . $providerName . '_api_key'),
            $this->settingsService->get('sms_' . $providerName . '_api_secret'),
            $this->settingsService->get('sms_sender_id')
        );
    }

    private function isEnabled(): bool
    {
        return $this->settingsService->get('enable_sms_notifications', false);
    }

    private function canReceiveSms(User $user): bool
    {
        return $user->getPhoneNumber() &&
               $user->isPhoneVerified() &&
               $user->getNotificationPreferences()['sms'] ?? false;
    }

    private function getProviderName(): string
    {
        return $this->settingsService->get('sms_provider', 'twilio');
    }

    public function getMessageTemplates(): array
    {
        $templates = [];
        foreach (self::SMS_TYPES as $type) {
            $templates[$type] = $this->getMessageTemplate($type);
        }
        return $templates;
    }

    public function getSmsLogs(array $criteria = [], array $orderBy = ['sentAt' => 'DESC'], int $limit = 100): array
    {
        return $this->entityManager->getRepository(SmsLog::class)
            ->findBy($criteria, $orderBy, $limit);
    }

    public function getStats(\DateTime $start = null, \DateTime $end = null): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(s.id) as total_sent, SUM(s.cost) as total_cost')
           ->from(SmsLog::class, 's')
           ->where('s.status = :status')
           ->setParameter('status', 'delivered');

        if ($start) {
            $qb->andWhere('s.sentAt >= :start')
               ->setParameter('start', $start);
        }

        if ($end) {
            $qb->andWhere('s.sentAt <= :end')
               ->setParameter('end', $end);
        }

        $result = $qb->getQuery()->getSingleResult();

        return [
            'total_sent' => $result['total_sent'] ?? 0,
            'total_cost' => $result['total_cost'] ?? 0,
            'success_rate' => $this->calculateSuccessRate($start, $end),
        ];
    }

    private function calculateSuccessRate(\DateTime $start = null, \DateTime $end = null): float
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(s.id) as total, SUM(CASE WHEN s.status = :status THEN 1 ELSE 0 END) as delivered')
           ->from(SmsLog::class, 's')
           ->setParameter('status', 'delivered');

        if ($start) {
            $qb->andWhere('s.sentAt >= :start')
               ->setParameter('start', $start);
        }

        if ($end) {
            $qb->andWhere('s.sentAt <= :end')
               ->setParameter('end', $end);
        }

        $result = $qb->getQuery()->getSingleResult();

        if ($result['total'] == 0) {
            return 0;
        }

        return ($result['delivered'] / $result['total']) * 100;
    }
}
