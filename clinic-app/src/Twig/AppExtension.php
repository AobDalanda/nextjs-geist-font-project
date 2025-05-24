<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Symfony\Component\Security\Core\Security;

class AppExtension extends AbstractExtension
{
    private $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('price', [$this, 'formatPrice']),
            new TwigFilter('phone', [$this, 'formatPhoneNumber']),
            new TwigFilter('time_ago', [$this, 'timeAgo']),
            new TwigFilter('appointment_status', [$this, 'formatAppointmentStatus']),
            new TwigFilter('initials', [$this, 'getInitials']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_doctor', [$this, 'isDoctor']),
            new TwigFunction('is_patient', [$this, 'isPatient']),
            new TwigFunction('can_modify_appointment', [$this, 'canModifyAppointment']),
            new TwigFunction('avatar_url', [$this, 'getAvatarUrl']),
            new TwigFunction('random_color', [$this, 'getRandomColor']),
        ];
    }

    public function formatPrice($number, string $currency = 'EUR'): string
    {
        $formatter = new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY);
        return $formatter->formatCurrency($number, $currency);
    }

    public function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove all non-digit characters
        $number = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Format French phone numbers
        if (strlen($number) === 10) {
            return vsprintf('%s %s %s %s %s', str_split($number, 2));
        }
        
        return $phoneNumber;
    }

    public function timeAgo(\DateTime $date): string
    {
        $now = new \DateTime();
        $diff = $now->diff($date);

        if ($diff->y > 0) {
            return $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
        }
        if ($diff->m > 0) {
            return $diff->m . ' mois';
        }
        if ($diff->d > 0) {
            return $diff->d . ' jour' . ($diff->d > 1 ? 's' : '');
        }
        if ($diff->h > 0) {
            return $diff->h . ' heure' . ($diff->h > 1 ? 's' : '');
        }
        if ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
        }

        return 'à l\'instant';
    }

    public function formatAppointmentStatus(string $status): string
    {
        $statuses = [
            'scheduled' => '<span class="badge bg-success">Confirmé</span>',
            'pending' => '<span class="badge bg-warning">En attente</span>',
            'cancelled' => '<span class="badge bg-danger">Annulé</span>',
            'completed' => '<span class="badge bg-info">Terminé</span>',
        ];

        return $statuses[$status] ?? '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
    }

    public function getInitials(string $fullName): string
    {
        $words = explode(' ', $fullName);
        $initials = '';
        
        foreach ($words as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
        
        return strlen($initials) > 2 ? substr($initials, 0, 2) : $initials;
    }

    public function isDoctor(): bool
    {
        return $this->security->isGranted('ROLE_DOCTOR');
    }

    public function isPatient(): bool
    {
        return $this->security->isGranted('ROLE_USER') && !$this->security->isGranted('ROLE_DOCTOR');
    }

    public function canModifyAppointment($appointment): bool
    {
        if (!$appointment) {
            return false;
        }

        // Check if the appointment is in the future
        $appointmentDate = $appointment->getDateTime();
        $now = new \DateTime();
        
        if ($appointmentDate <= $now) {
            return false;
        }

        // Check if the user is the patient or the doctor of the appointment
        $user = $this->security->getUser();
        if (!$user) {
            return false;
        }

        return $user === $appointment->getPatient() || 
               ($this->isDoctor() && $user === $appointment->getDoctor());
    }

    public function getAvatarUrl(string $name, int $size = 64): string
    {
        $name = urlencode($name);
        $backgroundColor = substr($this->getRandomColor($name), 1); // Remove #
        return "https://ui-avatars.com/api/?name={$name}&size={$size}&background={$backgroundColor}&color=ffffff";
    }

    public function getRandomColor(string $seed = null): string
    {
        if ($seed) {
            srand(crc32($seed));
        }

        $colors = [
            '#3498db', // Blue
            '#2ecc71', // Green
            '#e74c3c', // Red
            '#f1c40f', // Yellow
            '#9b59b6', // Purple
            '#1abc9c', // Turquoise
            '#e67e22', // Orange
            '#34495e', // Navy
        ];

        $color = $colors[array_rand($colors)];

        if ($seed) {
            srand();
        }

        return $color;
    }
}
