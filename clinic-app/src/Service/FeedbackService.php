<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\Doctor;
use App\Entity\Review;
use App\Entity\User;
use App\Entity\Feedback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;

class FeedbackService
{
    private const RATING_MIN = 1;
    private const RATING_MAX = 5;
    private const FEEDBACK_TYPES = ['general', 'service', 'doctor', 'appointment', 'website'];
    private const REVIEW_STATUS = ['pending', 'approved', 'rejected'];

    private $entityManager;
    private $security;
    private $mailer;
    private $logger;
    private $notificationService;
    private $settingsService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        MailerInterface $mailer,
        LoggerInterface $logger,
        NotificationService $notificationService,
        SettingsService $settingsService
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
        $this->settingsService = $settingsService;
    }

    public function submitAppointmentReview(Appointment $appointment, array $data): Review
    {
        $this->validateReviewSubmission($appointment);
        $this->validateRating($data['rating']);

        try {
            $review = new Review();
            $review->setAppointment($appointment)
                  ->setPatient($this->security->getUser())
                  ->setDoctor($appointment->getDoctor())
                  ->setRating($data['rating'])
                  ->setComment($data['comment'] ?? null)
                  ->setStatus('pending')
                  ->setAnonymous($data['anonymous'] ?? false);

            $this->entityManager->persist($review);
            $this->entityManager->flush();

            // Notify doctor and admin
            $this->notifyNewReview($review);

            $this->logger->info('Review submitted', [
                'review_id' => $review->getId(),
                'appointment_id' => $appointment->getId(),
                'rating' => $review->getRating(),
            ]);

            return $review;
        } catch (\Exception $e) {
            $this->logger->error('Failed to submit review', [
                'error' => $e->getMessage(),
                'appointment_id' => $appointment->getId(),
            ]);
            throw $e;
        }
    }

    public function submitGeneralFeedback(array $data): Feedback
    {
        $this->validateFeedbackType($data['type']);

        try {
            $feedback = new Feedback();
            $feedback->setType($data['type'])
                    ->setSubject($data['subject'])
                    ->setContent($data['content'])
                    ->setUser($this->security->getUser())
                    ->setStatus('pending')
                    ->setMetadata($data['metadata'] ?? []);

            $this->entityManager->persist($feedback);
            $this->entityManager->flush();

            // Notify administrators
            $this->notifyNewFeedback($feedback);

            $this->logger->info('Feedback submitted', [
                'feedback_id' => $feedback->getId(),
                'type' => $feedback->getType(),
            ]);

            return $feedback;
        } catch (\Exception $e) {
            $this->logger->error('Failed to submit feedback', [
                'error' => $e->getMessage(),
                'type' => $data['type'],
            ]);
            throw $e;
        }
    }

    public function moderateReview(Review $review, string $status, ?string $moderationNote = null): Review
    {
        $this->validateReviewStatus($status);
        $this->validateModeratorAccess();

        try {
            $review->setStatus($status)
                  ->setModerationNote($moderationNote)
                  ->setModeratedAt(new \DateTime())
                  ->setModeratedBy($this->security->getUser());

            $this->entityManager->flush();

            // Notify patient about moderation result
            $this->notifyReviewModeration($review);

            $this->logger->info('Review moderated', [
                'review_id' => $review->getId(),
                'status' => $status,
                'moderator' => $this->security->getUser()->getId(),
            ]);

            return $review;
        } catch (\Exception $e) {
            $this->logger->error('Failed to moderate review', [
                'error' => $e->getMessage(),
                'review_id' => $review->getId(),
            ]);
            throw $e;
        }
    }

    public function getDoctorReviews(Doctor $doctor, array $criteria = []): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r')
           ->from(Review::class, 'r')
           ->where('r.doctor = :doctor')
           ->andWhere('r.status = :status')
           ->setParameter('doctor', $doctor)
           ->setParameter('status', 'approved')
           ->orderBy('r.createdAt', 'DESC');

        if (isset($criteria['rating'])) {
            $qb->andWhere('r.rating = :rating')
               ->setParameter('rating', $criteria['rating']);
        }

        if (isset($criteria['date_from'])) {
            $qb->andWhere('r.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($criteria['date_from']));
        }

        if (isset($criteria['date_to'])) {
            $qb->andWhere('r.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($criteria['date_to']));
        }

        return $qb->getQuery()->getResult();
    }

    public function getPatientReviews(User $patient): array
    {
        return $this->entityManager->getRepository(Review::class)
            ->findBy(['patient' => $patient], ['createdAt' => 'DESC']);
    }

    public function getPendingReviews(): array
    {
        return $this->entityManager->getRepository(Review::class)
            ->findBy(['status' => 'pending'], ['createdAt' => 'ASC']);
    }

    public function getFeedbackStats(): array
    {
        $stats = [
            'total_reviews' => 0,
            'average_rating' => 0,
            'rating_distribution' => [],
            'recent_feedback' => [],
            'feedback_by_type' => [],
        ];

        // Calculate total reviews and average rating
        $qb = $this->entityManager->createQueryBuilder();
        $result = $qb->select('COUNT(r.id) as total, AVG(r.rating) as average')
                    ->from(Review::class, 'r')
                    ->where('r.status = :status')
                    ->setParameter('status', 'approved')
                    ->getQuery()
                    ->getSingleResult();

        $stats['total_reviews'] = $result['total'];
        $stats['average_rating'] = round($result['average'], 1);

        // Calculate rating distribution
        for ($i = self::RATING_MIN; $i <= self::RATING_MAX; $i++) {
            $count = $qb->select('COUNT(r.id)')
                       ->where('r.rating = :rating')
                       ->andWhere('r.status = :status')
                       ->setParameter('rating', $i)
                       ->setParameter('status', 'approved')
                       ->getQuery()
                       ->getSingleScalarResult();

            $stats['rating_distribution'][$i] = $count;
        }

        // Get recent feedback
        $stats['recent_feedback'] = $this->entityManager->getRepository(Feedback::class)
            ->findBy(
                ['status' => 'pending'],
                ['createdAt' => 'DESC'],
                10
            );

        // Get feedback by type
        foreach (self::FEEDBACK_TYPES as $type) {
            $stats['feedback_by_type'][$type] = $qb->select('COUNT(f.id)')
                ->from(Feedback::class, 'f')
                ->where('f.type = :type')
                ->setParameter('type', $type)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $stats;
    }

    private function validateReviewSubmission(Appointment $appointment): void
    {
        $user = $this->security->getUser();

        if (!$user || $user !== $appointment->getPatient()) {
            throw new BadRequestException('You can only review your own appointments.');
        }

        if ($appointment->getDateTime() > new \DateTime()) {
            throw new BadRequestException('You cannot review future appointments.');
        }

        $existingReview = $this->entityManager->getRepository(Review::class)
            ->findOneBy(['appointment' => $appointment]);

        if ($existingReview) {
            throw new BadRequestException('You have already reviewed this appointment.');
        }
    }

    private function validateRating(int $rating): void
    {
        if ($rating < self::RATING_MIN || $rating > self::RATING_MAX) {
            throw new BadRequestException(sprintf(
                'Rating must be between %d and %d.',
                self::RATING_MIN,
                self::RATING_MAX
            ));
        }
    }

    private function validateFeedbackType(string $type): void
    {
        if (!in_array($type, self::FEEDBACK_TYPES)) {
            throw new BadRequestException(sprintf(
                'Invalid feedback type. Allowed types: %s',
                implode(', ', self::FEEDBACK_TYPES)
            ));
        }
    }

    private function validateReviewStatus(string $status): void
    {
        if (!in_array($status, self::REVIEW_STATUS)) {
            throw new BadRequestException(sprintf(
                'Invalid review status. Allowed statuses: %s',
                implode(', ', self::REVIEW_STATUS)
            ));
        }
    }

    private function validateModeratorAccess(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new BadRequestException('Only administrators can moderate reviews.');
        }
    }

    private function notifyNewReview(Review $review): void
    {
        // Notify doctor
        $this->notificationService->sendNotification(
            $review->getDoctor(),
            'Nouveau avis patient',
            sprintf(
                'Un patient a laissé un avis sur votre consultation du %s.',
                $review->getAppointment()->getDateTime()->format('d/m/Y')
            )
        );

        // Notify administrators
        $this->notificationService->notifyAdministrators(
            'Nouvel avis à modérer',
            sprintf(
                'Un nouvel avis a été soumis pour le Dr. %s',
                $review->getDoctor()->getFullName()
            )
        );
    }

    private function notifyNewFeedback(Feedback $feedback): void
    {
        $this->notificationService->notifyAdministrators(
            'Nouveau feedback reçu',
            sprintf(
                'Un nouveau feedback de type "%s" a été soumis: %s',
                $feedback->getType(),
                $feedback->getSubject()
            )
        );
    }

    private function notifyReviewModeration(Review $review): void
    {
        if ($review->getStatus() === 'approved') {
            $this->notificationService->sendNotification(
                $review->getPatient(),
                'Votre avis a été approuvé',
                'Votre avis a été approuvé et est maintenant visible publiquement.'
            );
        } elseif ($review->getStatus() === 'rejected') {
            $this->notificationService->sendNotification(
                $review->getPatient(),
                'Votre avis n\'a pas été approuvé',
                sprintf(
                    'Votre avis n\'a pas été approuvé. Raison: %s',
                    $review->getModerationNote() ?? 'Non spécifiée'
                )
            );
        }
    }
}
