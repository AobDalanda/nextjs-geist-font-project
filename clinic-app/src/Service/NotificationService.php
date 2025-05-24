<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Appointment;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class NotificationService
{
    private $mailer;
    private $params;
    private $translator;
    private $twig;
    private $logger;

    public function __construct(
        MailerInterface $mailer,
        ParameterBagInterface $params,
        TranslatorInterface $translator,
        Environment $twig,
        LoggerInterface $logger
    ) {
        $this->mailer = $mailer;
        $this->params = $params;
        $this->translator = $translator;
        $this->twig = $twig;
        $this->logger = $logger;
    }

    public function sendAppointmentConfirmation(Appointment $appointment): void
    {
        $patient = $appointment->getPatient();
        $doctor = $appointment->getDoctor();

        // Send email to patient
        $this->sendEmail(
            'appointment_confirmation',
            $patient->getEmail(),
            'Confirmation de votre rendez-vous',
            [
                'appointment' => $appointment,
                'patient' => $patient,
                'doctor' => $doctor,
            ]
        );

        // Send email to doctor
        $this->sendEmail(
            'appointment_confirmation_doctor',
            $doctor->getEmail(),
            'Nouveau rendez-vous',
            [
                'appointment' => $appointment,
                'patient' => $patient,
                'doctor' => $doctor,
            ]
        );

        // Send SMS if enabled
        if ($patient->getPreferredNotification() === 'sms' || $patient->getPreferredNotification() === 'both') {
            $this->sendSMS(
                $patient->getPhoneNumber(),
                $this->translator->trans('notification.appointment.confirmation_sms', [
                    '%date%' => $appointment->getDateTime()->format('d/m/Y'),
                    '%time%' => $appointment->getDateTime()->format('H:i'),
                    '%doctor%' => $doctor->getFullName(),
                ])
            );
        }
    }

    public function sendAppointmentReminder(Appointment $appointment): void
    {
        $patient = $appointment->getPatient();
        $doctor = $appointment->getDoctor();

        // Send email reminder
        $this->sendEmail(
            'appointment_reminder',
            $patient->getEmail(),
            'Rappel de votre rendez-vous',
            [
                'appointment' => $appointment,
                'patient' => $patient,
                'doctor' => $doctor,
            ]
        );

        // Send SMS reminder if enabled
        if ($patient->getPreferredNotification() === 'sms' || $patient->getPreferredNotification() === 'both') {
            $this->sendSMS(
                $patient->getPhoneNumber(),
                $this->translator->trans('notification.appointment.reminder_sms', [
                    '%date%' => $appointment->getDateTime()->format('d/m/Y'),
                    '%time%' => $appointment->getDateTime()->format('H:i'),
                    '%doctor%' => $doctor->getFullName(),
                ])
            );
        }
    }

    public function sendAppointmentCancellation(Appointment $appointment, string $reason = null): void
    {
        $patient = $appointment->getPatient();
        $doctor = $appointment->getDoctor();

        // Send email to patient
        $this->sendEmail(
            'appointment_cancellation',
            $patient->getEmail(),
            'Annulation de votre rendez-vous',
            [
                'appointment' => $appointment,
                'patient' => $patient,
                'doctor' => $doctor,
                'reason' => $reason,
            ]
        );

        // Send email to doctor
        $this->sendEmail(
            'appointment_cancellation_doctor',
            $doctor->getEmail(),
            'Rendez-vous annulé',
            [
                'appointment' => $appointment,
                'patient' => $patient,
                'doctor' => $doctor,
                'reason' => $reason,
            ]
        );

        // Send SMS if enabled
        if ($patient->getPreferredNotification() === 'sms' || $patient->getPreferredNotification() === 'both') {
            $this->sendSMS(
                $patient->getPhoneNumber(),
                $this->translator->trans('notification.appointment.cancellation_sms', [
                    '%date%' => $appointment->getDateTime()->format('d/m/Y'),
                    '%time%' => $appointment->getDateTime()->format('H:i'),
                    '%doctor%' => $doctor->getFullName(),
                ])
            );
        }
    }

    public function sendPasswordResetLink(User $user, string $resetToken): void
    {
        $this->sendEmail(
            'reset_password',
            $user->getEmail(),
            'Réinitialisation de votre mot de passe',
            [
                'user' => $user,
                'resetUrl' => $this->generateResetUrl($resetToken),
            ]
        );
    }

    private function sendEmail(string $template, string $to, string $subject, array $context = []): void
    {
        try {
            $email = (new TemplatedEmail())
                ->from($this->params->get('app.mail_from'))
                ->to($to)
                ->subject($subject)
                ->htmlTemplate("emails/{$template}.html.twig")
                ->context($context);

            $this->mailer->send($email);
            
            $this->logger->info('Email sent successfully', [
                'template' => $template,
                'to' => $to,
                'subject' => $subject,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email', [
                'template' => $template,
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendSMS(string $phoneNumber, string $message): void
    {
        // This is a placeholder for SMS sending functionality
        // You would implement this using your preferred SMS provider
        $this->logger->info('SMS would be sent', [
            'phone' => $phoneNumber,
            'message' => $message,
        ]);
    }

    private function generateResetUrl(string $token): string
    {
        return $this->params->get('app.url') . '/reset-password/' . $token;
    }
}
