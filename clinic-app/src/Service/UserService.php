<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Doctor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\Uid\Uuid;

class UserService
{
    private $entityManager;
    private $passwordHasher;
    private $security;
    private $notificationService;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
        NotificationService $notificationService
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->security = $security;
        $this->notificationService = $notificationService;
    }

    public function createPatient(array $data): User
    {
        $user = new User();
        $user->setEmail($data['email'])
             ->setFirstName($data['firstName'])
             ->setLastName($data['lastName'])
             ->setPhoneNumber($data['phoneNumber'] ?? null)
             ->setRoles(['ROLE_USER'])
             ->setIsVerified(false);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        // Generate verification token
        $verificationToken = Uuid::v4()->toRfc4122();
        $user->setVerificationToken($verificationToken);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Send verification email
        $this->notificationService->sendEmailVerification($user, $verificationToken);

        return $user;
    }

    public function createDoctor(array $data): Doctor
    {
        $doctor = new Doctor();
        $doctor->setEmail($data['email'])
               ->setFirstName($data['firstName'])
               ->setLastName($data['lastName'])
               ->setPhoneNumber($data['phoneNumber'] ?? null)
               ->setSpeciality($data['speciality'])
               ->setRoles(['ROLE_DOCTOR'])
               ->setIsVerified(false)
               ->setIsAvailable(true);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($doctor, $data['password']);
        $doctor->setPassword($hashedPassword);

        // Set default schedule
        $doctor->setSchedule([
            'monday' => ['start' => '09:00', 'end' => '17:00'],
            'tuesday' => ['start' => '09:00', 'end' => '17:00'],
            'wednesday' => ['start' => '09:00', 'end' => '17:00'],
            'thursday' => ['start' => '09:00', 'end' => '17:00'],
            'friday' => ['start' => '09:00', 'end' => '17:00'],
        ]);

        $this->entityManager->persist($doctor);
        $this->entityManager->flush();

        return $doctor;
    }

    public function verifyEmail(string $token): void
    {
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            throw new UserNotFoundException('Invalid verification token.');
        }

        $user->setIsVerified(true)
             ->setVerificationToken(null);

        $this->entityManager->flush();
    }

    public function requestPasswordReset(string $email): void
    {
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if (!$user) {
            // Don't reveal whether a user was found or not
            return;
        }

        // Generate reset token
        $resetToken = Uuid::v4()->toRfc4122();
        $user->setResetToken($resetToken)
             ->setResetTokenExpiresAt(new \DateTime('+1 hour'));

        $this->entityManager->flush();

        // Send reset email
        $this->notificationService->sendPasswordResetLink($user, $resetToken);
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['resetToken' => $token]);

        if (!$user) {
            throw new UserNotFoundException('Invalid reset token.');
        }

        if ($user->getResetTokenExpiresAt() < new \DateTime()) {
            throw new BadRequestException('Reset token has expired.');
        }

        // Hash new password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        
        $user->setPassword($hashedPassword)
             ->setResetToken(null)
             ->setResetTokenExpiresAt(null);

        $this->entityManager->flush();
    }

    public function updateProfile(User $user, array $data): void
    {
        if (!$this->security->isGranted('EDIT', $user)) {
            throw new BadRequestException('You are not authorized to edit this profile.');
        }

        $user->setFirstName($data['firstName'])
             ->setLastName($data['lastName'])
             ->setPhoneNumber($data['phoneNumber'] ?? null);

        if ($user instanceof Doctor) {
            $user->setSpeciality($data['speciality'] ?? $user->getSpeciality());
        }

        $this->entityManager->flush();
    }

    public function updatePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!$this->security->isGranted('EDIT', $user)) {
            throw new BadRequestException('You are not authorized to change this password.');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new BadRequestException('Current password is incorrect.');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        $this->entityManager->flush();
    }

    public function updateNotificationPreferences(User $user, array $preferences): void
    {
        if (!$this->security->isGranted('EDIT', $user)) {
            throw new BadRequestException('You are not authorized to update these preferences.');
        }

        $user->setPreferredNotification($preferences['preferredNotification'] ?? null)
             ->setEmailNotifications($preferences['emailNotifications'] ?? [])
             ->setSmsNotifications($preferences['smsNotifications'] ?? [])
             ->setReminderTiming($preferences['reminderTiming'] ?? null);

        $this->entityManager->flush();
    }

    public function updateDoctorSchedule(Doctor $doctor, array $schedule): void
    {
        if (!$this->security->isGranted('EDIT', $doctor)) {
            throw new BadRequestException('You are not authorized to update this schedule.');
        }

        $doctor->setSchedule($schedule);
        $this->entityManager->flush();
    }

    public function toggleDoctorAvailability(Doctor $doctor, bool $isAvailable): void
    {
        if (!$this->security->isGranted('EDIT', $doctor)) {
            throw new BadRequestException('You are not authorized to update availability.');
        }

        $doctor->setIsAvailable($isAvailable);
        $this->entityManager->flush();
    }
}
