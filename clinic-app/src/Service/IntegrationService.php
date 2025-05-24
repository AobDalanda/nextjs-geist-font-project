<?php

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

class IntegrationService
{
    private const INTEGRATIONS = [
        'google_calendar',
        'stripe',
        'mailchimp',
        'twilio',
        'google_maps',
        'doctolib',
        'vitale_card',
    ];

    private const CACHE_TTL = [
        'short' => 300,    // 5 minutes
        'medium' => 3600,  // 1 hour
        'long' => 86400,   // 24 hours
    ];

    private $client;
    private $params;
    private $cache;
    private $logger;
    private $settingsService;

    private $apiKeys = [];
    private $endpoints = [];
    private $configs = [];

    public function __construct(
        HttpClientInterface $client,
        ParameterBagInterface $params,
        AdapterInterface $cache,
        LoggerInterface $logger,
        SettingsService $settingsService
    ) {
        $this->client = $client;
        $this->params = $params;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->settingsService = $settingsService;

        $this->initializeConfigurations();
    }

    public function syncGoogleCalendar(array $events, string $calendarId = null): array
    {
        $this->validateIntegration('google_calendar');

        try {
            $response = $this->makeRequest('google_calendar', 'POST', '/calendar/v3/calendars/' . $calendarId . '/events/sync', [
                'json' => ['events' => $events],
            ]);

            return $response['items'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Google Calendar sync failed', [
                'error' => $e->getMessage(),
                'calendar_id' => $calendarId,
            ]);
            throw $e;
        }
    }

    public function processPayment(array $paymentData): array
    {
        $this->validateIntegration('stripe');

        try {
            $response = $this->makeRequest('stripe', 'POST', '/v1/payment_intents', [
                'json' => $paymentData,
            ]);

            $this->logger->info('Payment processed', [
                'payment_intent_id' => $response['id'],
                'amount' => $paymentData['amount'],
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Payment processing failed', [
                'error' => $e->getMessage(),
                'payment_data' => $paymentData,
            ]);
            throw $e;
        }
    }

    public function syncMailingList(array $subscribers): array
    {
        $this->validateIntegration('mailchimp');

        try {
            $listId = $this->configs['mailchimp']['list_id'];
            $response = $this->makeRequest('mailchimp', 'POST', "/lists/{$listId}/members", [
                'json' => ['members' => $subscribers],
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Mailing list sync failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function sendSms(string $to, string $message): array
    {
        $this->validateIntegration('twilio');

        try {
            $response = $this->makeRequest('twilio', 'POST', '/2010-04-01/Accounts/{account_sid}/Messages.json', [
                'form_params' => [
                    'To' => $to,
                    'From' => $this->configs['twilio']['phone_number'],
                    'Body' => $message,
                ],
            ]);

            $this->logger->info('SMS sent', [
                'to' => $to,
                'message_id' => $response['sid'],
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('SMS sending failed', [
                'error' => $e->getMessage(),
                'to' => $to,
            ]);
            throw $e;
        }
    }

    public function geocodeAddress(string $address): array
    {
        $this->validateIntegration('google_maps');

        $cacheKey = 'geocode_' . md5($address);
        
        return $this->cache->get($cacheKey, function() use ($address) {
            $response = $this->makeRequest('google_maps', 'GET', '/maps/api/geocode/json', [
                'query' => [
                    'address' => $address,
                    'key' => $this->apiKeys['google_maps'],
                ],
            ]);

            return $response['results'][0] ?? [];
        });
    }

    public function syncWithDoctolib(array $data): array
    {
        $this->validateIntegration('doctolib');

        try {
            $response = $this->makeRequest('doctolib', 'POST', '/api/v1/sync', [
                'json' => $data,
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Doctolib sync failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function readVitaleCard(array $cardData): array
    {
        $this->validateIntegration('vitale_card');

        try {
            $response = $this->makeRequest('vitale_card', 'POST', '/api/v1/read', [
                'json' => $cardData,
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Vitale card reading failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getIntegrationStatus(string $integration): array
    {
        if (!in_array($integration, self::INTEGRATIONS)) {
            throw new \InvalidArgumentException(sprintf('Invalid integration: %s', $integration));
        }

        try {
            $response = $this->makeRequest($integration, 'GET', '/status');
            
            return [
                'status' => 'active',
                'last_check' => new \DateTime(),
                'details' => $response,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'last_check' => new \DateTime(),
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getAllIntegrationsStatus(): array
    {
        $status = [];
        foreach (self::INTEGRATIONS as $integration) {
            $status[$integration] = $this->getIntegrationStatus($integration);
        }
        return $status;
    }

    private function makeRequest(string $integration, string $method, string $path, array $options = []): array
    {
        $baseUrl = $this->endpoints[$integration] ?? null;
        if (!$baseUrl) {
            throw new \RuntimeException(sprintf('No endpoint configured for integration: %s', $integration));
        }

        $options['headers'] = array_merge(
            $options['headers'] ?? [],
            $this->getAuthHeaders($integration)
        );

        try {
            $response = $this->client->request(
                $method,
                $baseUrl . $path,
                $options
            );

            return $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('API request failed', [
                'integration' => $integration,
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function initializeConfigurations(): void
    {
        $this->apiKeys = [
            'google_calendar' => $this->settingsService->get('google_calendar_api_key'),
            'stripe' => $this->settingsService->get('stripe_api_key'),
            'mailchimp' => $this->settingsService->get('mailchimp_api_key'),
            'twilio' => $this->settingsService->get('twilio_api_key'),
            'google_maps' => $this->settingsService->get('google_maps_api_key'),
            'doctolib' => $this->settingsService->get('doctolib_api_key'),
            'vitale_card' => $this->settingsService->get('vitale_card_api_key'),
        ];

        $this->endpoints = [
            'google_calendar' => 'https://www.googleapis.com',
            'stripe' => 'https://api.stripe.com',
            'mailchimp' => 'https://api.mailchimp.com/3.0',
            'twilio' => 'https://api.twilio.com',
            'google_maps' => 'https://maps.googleapis.com',
            'doctolib' => 'https://api.doctolib.fr',
            'vitale_card' => 'https://api.vitale.fr',
        ];

        $this->configs = [
            'mailchimp' => [
                'list_id' => $this->settingsService->get('mailchimp_list_id'),
            ],
            'twilio' => [
                'account_sid' => $this->settingsService->get('twilio_account_sid'),
                'phone_number' => $this->settingsService->get('twilio_phone_number'),
            ],
        ];
    }

    private function validateIntegration(string $integration): void
    {
        if (!in_array($integration, self::INTEGRATIONS)) {
            throw new \InvalidArgumentException(sprintf('Invalid integration: %s', $integration));
        }

        if (!isset($this->apiKeys[$integration]) || !$this->apiKeys[$integration]) {
            throw new \RuntimeException(sprintf('API key not configured for integration: %s', $integration));
        }
    }

    private function getAuthHeaders(string $integration): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        switch ($integration) {
            case 'google_calendar':
            case 'google_maps':
                $headers['Authorization'] = 'Bearer ' . $this->apiKeys[$integration];
                break;
            case 'stripe':
                $headers['Authorization'] = 'Bearer ' . $this->apiKeys[$integration];
                break;
            case 'mailchimp':
                $headers['Authorization'] = 'Basic ' . base64_encode('user:' . $this->apiKeys[$integration]);
                break;
            case 'twilio':
                $headers['Authorization'] = 'Basic ' . base64_encode(
                    $this->configs['twilio']['account_sid'] . ':' . $this->apiKeys[$integration]
                );
                break;
            case 'doctolib':
            case 'vitale_card':
                $headers['X-API-Key'] = $this->apiKeys[$integration];
                break;
        }

        return $headers;
    }
}
