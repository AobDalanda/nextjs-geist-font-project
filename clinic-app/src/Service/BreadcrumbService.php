<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

class BreadcrumbService
{
    private $requestStack;
    private $router;
    private $security;
    private $translator;
    private $breadcrumbs = [];

    public function __construct(
        RequestStack $requestStack,
        RouterInterface $router,
        Security $security,
        TranslatorInterface $translator
    ) {
        $this->requestStack = $requestStack;
        $this->router = $router;
        $this->security = $security;
        $this->translator = $translator;
    }

    public function addItem(string $label, ?string $route = null, array $routeParams = [], bool $translated = true): self
    {
        $this->breadcrumbs[] = [
            'label' => $translated ? $this->translator->trans($label) : $label,
            'route' => $route ? $this->router->generate($route, $routeParams) : null,
        ];

        return $this;
    }

    public function getBreadcrumbs(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return [];
        }

        // Always start with home
        $breadcrumbs = [[
            'label' => $this->translator->trans('breadcrumb.home'),
            'route' => $this->router->generate('app_home'),
        ]];

        // Add dynamic breadcrumbs based on the current route
        $route = $request->attributes->get('_route');
        $routeParams = $request->attributes->get('_route_params', []);

        // Add context-specific breadcrumbs
        $this->addContextBreadcrumbs($route, $routeParams);

        // Merge with manually added breadcrumbs
        return array_merge($breadcrumbs, $this->breadcrumbs);
    }

    private function addContextBreadcrumbs(string $route, array $routeParams): void
    {
        // Handle authentication routes
        if (str_starts_with($route, 'app_login')) {
            $this->addItem('breadcrumb.login');
        } elseif (str_starts_with($route, 'app_register')) {
            $this->addItem('breadcrumb.register');
        } elseif (str_starts_with($route, 'app_reset_password')) {
            $this->addItem('breadcrumb.reset_password');
        }

        // Handle dashboard routes
        if (str_starts_with($route, 'app_dashboard')) {
            if ($this->security->isGranted('ROLE_DOCTOR')) {
                $this->addItem('breadcrumb.doctor_dashboard', 'app_doctor_dashboard');
            } else {
                $this->addItem('breadcrumb.patient_dashboard', 'app_patient_dashboard');
            }
        }

        // Handle appointment routes
        if (str_starts_with($route, 'app_appointment')) {
            $this->addItem('breadcrumb.appointments', 'app_appointments');

            if (str_contains($route, '_new')) {
                $this->addItem('breadcrumb.new_appointment');
            } elseif (str_contains($route, '_edit') && isset($routeParams['id'])) {
                $this->addItem('breadcrumb.edit_appointment');
            } elseif (str_contains($route, '_show') && isset($routeParams['id'])) {
                $this->addItem('breadcrumb.view_appointment');
            }
        }

        // Handle profile routes
        if (str_starts_with($route, 'app_profile')) {
            $this->addItem('breadcrumb.profile', 'app_profile');

            if (str_contains($route, '_edit')) {
                $this->addItem('breadcrumb.edit_profile');
            } elseif (str_contains($route, '_password')) {
                $this->addItem('breadcrumb.change_password');
            } elseif (str_contains($route, '_preferences')) {
                $this->addItem('breadcrumb.preferences');
            }
        }

        // Handle medical service routes
        if (str_starts_with($route, 'app_service')) {
            $this->addItem('breadcrumb.services', 'app_services');

            if (str_contains($route, '_show') && isset($routeParams['id'])) {
                $this->addItem('breadcrumb.service_details');
            }
        }

        // Handle doctor routes
        if (str_starts_with($route, 'app_doctor')) {
            $this->addItem('breadcrumb.doctors', 'app_doctors');

            if (str_contains($route, '_profile') && isset($routeParams['id'])) {
                $this->addItem('breadcrumb.doctor_profile');
            } elseif (str_contains($route, '_schedule')) {
                $this->addItem('breadcrumb.doctor_schedule');
            }
        }

        // Handle admin routes
        if (str_starts_with($route, 'app_admin')) {
            $this->addItem('breadcrumb.admin', 'app_admin_dashboard');

            if (str_contains($route, '_users')) {
                $this->addItem('breadcrumb.manage_users', 'app_admin_users');
            } elseif (str_contains($route, '_services')) {
                $this->addItem('breadcrumb.manage_services', 'app_admin_services');
            } elseif (str_contains($route, '_appointments')) {
                $this->addItem('breadcrumb.manage_appointments', 'app_admin_appointments');
            } elseif (str_contains($route, '_statistics')) {
                $this->addItem('breadcrumb.statistics', 'app_admin_statistics');
            } elseif (str_contains($route, '_settings')) {
                $this->addItem('breadcrumb.settings', 'app_admin_settings');
            }
        }

        // Handle static pages
        if ($route === 'app_about') {
            $this->addItem('breadcrumb.about');
        } elseif ($route === 'app_contact') {
            $this->addItem('breadcrumb.contact');
        } elseif ($route === 'app_terms') {
            $this->addItem('breadcrumb.terms');
        } elseif ($route === 'app_privacy') {
            $this->addItem('breadcrumb.privacy');
        }
    }

    public function generateSchema(): array
    {
        $items = $this->getBreadcrumbs();
        $listItems = [];
        $position = 1;

        foreach ($items as $item) {
            $listItems[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'item' => [
                    '@id' => $item['route'] ?? '#',
                    'name' => $item['label'],
                ],
            ];
            $position++;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $listItems,
        ];
    }

    public function clear(): void
    {
        $this->breadcrumbs = [];
    }

    public function getCurrentPageTitle(): string
    {
        $breadcrumbs = $this->getBreadcrumbs();
        return end($breadcrumbs)['label'] ?? '';
    }

    public function getParentPage(): ?array
    {
        $breadcrumbs = $this->getBreadcrumbs();
        $count = count($breadcrumbs);
        
        return $count > 1 ? $breadcrumbs[$count - 2] : null;
    }
}
