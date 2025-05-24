<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MaintenanceSubscriber implements EventSubscriberInterface
{
    private $twig;
    private $params;
    private $maintenanceFilePath;

    public function __construct(Environment $twig, ParameterBagInterface $params)
    {
        $this->twig = $twig;
        $this->params = $params;
        $this->maintenanceFilePath = $params->get('kernel.project_dir') . '/maintenance.lock';
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequest', 100], // High priority to run before other listeners
            ],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Check if maintenance mode is enabled
        if (!file_exists($this->maintenanceFilePath)) {
            return;
        }

        $request = $event->getRequest();
        
        // Allow access to admin routes during maintenance
        if ($this->isAdminRoute($request->getPathInfo())) {
            return;
        }

        // Get maintenance data
        $maintenanceData = json_decode(file_get_contents($this->maintenanceFilePath), true) ?? [];
        
        // Check if maintenance period is over
        if (isset($maintenanceData['end_time']) && time() > strtotime($maintenanceData['end_time'])) {
            unlink($this->maintenanceFilePath);
            return;
        }

        // Return maintenance page
        $content = $this->twig->render('maintenance.html.twig', [
            'start_time' => $maintenanceData['start_time'] ?? null,
            'end_time' => $maintenanceData['end_time'] ?? null,
            'message' => $maintenanceData['message'] ?? 'Site en maintenance',
        ]);

        $event->setResponse(new Response(
            $content,
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => 3600]
        ));
    }

    private function isAdminRoute(string $pathInfo): bool
    {
        $adminRoutes = [
            '/admin',
            '/login',
            '/_profiler',
            '/_wdt',
        ];

        foreach ($adminRoutes as $route) {
            if (str_starts_with($pathInfo, $route)) {
                return true;
            }
        }

        return false;
    }
}
