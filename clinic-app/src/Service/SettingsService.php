<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;

class SettingsService
{
    private const CACHE_KEY = 'app_settings';
    private const CACHE_TTL = 3600; // 1 hour

    private $entityManager;
    private $cache;
    private $params;
    private $logger;
    private $settings = null;

    public function __construct(
        EntityManagerInterface $entityManager,
        AdapterInterface $cache,
        ParameterBagInterface $params,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->cache = $cache;
        $this->params = $params;
        $this->logger = $logger;
    }

    public function get(string $key, $default = null)
    {
        $settings = $this->getAllSettings();
        return $settings[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        try {
            $setting = $this->entityManager->getRepository(\App\Entity\Setting::class)
                ->findOneBy(['key' => $key]);

            if (!$setting) {
                $setting = new \App\Entity\Setting();
                $setting->setKey($key);
            }

            $setting->setValue($value);
            $this->entityManager->persist($setting);
            $this->entityManager->flush();

            // Clear cache
            $this->clearCache();

            $this->logger->info('Setting updated', [
                'key' => $key,
                'value' => $value,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update setting', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getAllSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        try {
            $cacheItem = $this->cache->getItem(self::CACHE_KEY);

            if ($cacheItem->isHit()) {
                $this->settings = $cacheItem->get();
                return $this->settings;
            }

            $settings = $this->loadSettingsFromDatabase();
            
            $cacheItem->set($settings);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);

            $this->settings = $settings;
            return $settings;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load settings', [
                'error' => $e->getMessage(),
            ]);
            return $this->getDefaultSettings();
        }
    }

    public function clearCache(): void
    {
        $this->settings = null;
        $this->cache->deleteItem(self::CACHE_KEY);
    }

    private function loadSettingsFromDatabase(): array
    {
        $settings = [];
        $entities = $this->entityManager->getRepository(\App\Entity\Setting::class)->findAll();

        foreach ($entities as $entity) {
            $settings[$entity->getKey()] = $entity->getValue();
        }

        return array_merge($this->getDefaultSettings(), $settings);
    }

    private function getDefaultSettings(): array
    {
        return [
            // General Settings
            'site_name' => 'Clinique',
            'site_description' => 'Votre santé, notre priorité',
            'contact_email' => 'contact@clinique.fr',
            'contact_phone' => '+33123456789',
            'address' => '123 Rue de la Santé, 75000 Paris',

            // Appointment Settings
            'appointment_duration' => 30, // minutes
            'min_appointment_notice' => 24, // hours
            'max_future_booking' => 90, // days
            'cancellation_deadline' => 24, // hours
            'allow_weekend_appointments' => false,
            'working_hours' => [
                'monday' => ['09:00', '18:00'],
                'tuesday' => ['09:00', '18:00'],
                'wednesday' => ['09:00', '18:00'],
                'thursday' => ['09:00', '18:00'],
                'friday' => ['09:00', '18:00'],
            ],

            // Notification Settings
            'enable_email_notifications' => true,
            'enable_sms_notifications' => false,
            'reminder_timing' => 24, // hours before appointment
            'notification_types' => [
                'appointment_confirmation',
                'appointment_reminder',
                'appointment_cancellation',
                'doctor_unavailable',
            ],

            // Security Settings
            'max_login_attempts' => 5,
            'login_attempt_timeout' => 15, // minutes
            'password_reset_timeout' => 60, // minutes
            'session_lifetime' => 3600, // seconds
            'require_2fa' => false,

            // File Upload Settings
            'max_upload_size' => 10485760, // 10MB in bytes
            'allowed_file_types' => [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],

            // SEO Settings
            'enable_sitemap' => true,
            'enable_robots' => true,
            'google_analytics_id' => '',
            'meta_keywords' => 'clinique, médecin, santé, rendez-vous, consultation',

            // Social Media
            'social_media' => [
                'facebook' => '',
                'twitter' => '',
                'linkedin' => '',
                'instagram' => '',
            ],

            // Maintenance Settings
            'maintenance_mode' => false,
            'maintenance_message' => 'Site en maintenance. Merci de revenir plus tard.',
            'allowed_ips' => [],

            // Performance Settings
            'enable_cache' => true,
            'cache_lifetime' => 3600,
            'minify_html' => true,
            'enable_compression' => true,

            // Integration Settings
            'sms_provider' => '',
            'sms_api_key' => '',
            'payment_gateway' => '',
            'payment_api_key' => '',
            'maps_api_key' => '',
        ];
    }

    public function getSettingsByCategory(string $category): array
    {
        $allSettings = $this->getAllSettings();
        $categorySettings = [];

        switch ($category) {
            case 'general':
                $keys = ['site_name', 'site_description', 'contact_email', 'contact_phone', 'address'];
                break;
            case 'appointment':
                $keys = ['appointment_duration', 'min_appointment_notice', 'max_future_booking', 
                        'cancellation_deadline', 'allow_weekend_appointments', 'working_hours'];
                break;
            case 'notification':
                $keys = ['enable_email_notifications', 'enable_sms_notifications', 
                        'reminder_timing', 'notification_types'];
                break;
            case 'security':
                $keys = ['max_login_attempts', 'login_attempt_timeout', 'password_reset_timeout',
                        'session_lifetime', 'require_2fa'];
                break;
            case 'upload':
                $keys = ['max_upload_size', 'allowed_file_types'];
                break;
            case 'seo':
                $keys = ['enable_sitemap', 'enable_robots', 'google_analytics_id', 'meta_keywords'];
                break;
            case 'social':
                $keys = ['social_media'];
                break;
            case 'maintenance':
                $keys = ['maintenance_mode', 'maintenance_message', 'allowed_ips'];
                break;
            case 'performance':
                $keys = ['enable_cache', 'cache_lifetime', 'minify_html', 'enable_compression'];
                break;
            case 'integration':
                $keys = ['sms_provider', 'sms_api_key', 'payment_gateway', 
                        'payment_api_key', 'maps_api_key'];
                break;
            default:
                return [];
        }

        foreach ($keys as $key) {
            if (isset($allSettings[$key])) {
                $categorySettings[$key] = $allSettings[$key];
            }
        }

        return $categorySettings;
    }

    public function validateSetting(string $key, $value): bool
    {
        // Add validation rules for specific settings
        switch ($key) {
            case 'contact_email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            
            case 'appointment_duration':
            case 'min_appointment_notice':
            case 'max_future_booking':
            case 'cancellation_deadline':
                return is_numeric($value) && $value > 0;
            
            case 'max_upload_size':
                return is_numeric($value) && $value > 0 && $value <= 104857600; // Max 100MB
            
            case 'working_hours':
                return $this->validateWorkingHours($value);
            
            default:
                return true;
        }
    }

    private function validateWorkingHours(array $hours): bool
    {
        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        
        foreach ($hours as $day => $times) {
            if (!in_array($day, $validDays)) {
                return false;
            }
            
            if (!is_array($times) || count($times) !== 2) {
                return false;
            }
            
            foreach ($times as $time) {
                if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                    return false;
                }
            }
            
            if (strtotime($times[0]) >= strtotime($times[1])) {
                return false;
            }
        }
        
        return true;
    }
}
