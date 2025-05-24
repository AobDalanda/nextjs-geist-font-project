<?php

namespace App\Service;

use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\RouterInterface;

class NavigationService
{
    private $security;
    private $requestStack;
    private $translator;
    private $router;

    public function __construct(
        Security $security,
        RequestStack $requestStack,
        TranslatorInterface $translator,
        RouterInterface $router
    ) {
        $this->security = $security;
        $this->requestStack = $requestStack;
        $this->translator = $translator;
        $this->router = $router;
    }

    public function getMainMenu(): array
    {
        $menu = [
            'home' => [
                'label' => 'menu.home',
                'route' => 'app_home',
                'icon' => 'fas fa-home',
            ],
            'services' => [
                'label' => 'menu.services',
                'route' => 'app_services',
                'icon' => 'fas fa-stethoscope',
            ],
            'doctors' => [
                'label' => 'menu.doctors',
                'route' => 'app_doctors',
                'icon' => 'fas fa-user-md',
            ],
            'appointments' => [
                'label' => 'menu.appointments',
                'route' => 'app_appointments',
                'icon' => 'fas fa-calendar-alt',
                'requires_auth' => true,
            ],
            'contact' => [
                'label' => 'menu.contact',
                'route' => 'app_contact',
                'icon' => 'fas fa-envelope',
            ],
        ];

        return $this->filterMenuItems($menu);
    }

    public function getDashboardMenu(): array
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $this->getAdminDashboardMenu();
        }

        if ($this->security->isGranted('ROLE_DOCTOR')) {
            return $this->getDoctorDashboardMenu();
        }

        return $this->getPatientDashboardMenu();
    }

    public function getUserMenu(): array
    {
        $menu = [
            'profile' => [
                'label' => 'menu.profile',
                'route' => 'app_profile',
                'icon' => 'fas fa-user',
                'requires_auth' => true,
            ],
            'preferences' => [
                'label' => 'menu.preferences',
                'route' => 'app_preferences',
                'icon' => 'fas fa-cog',
                'requires_auth' => true,
            ],
            'password' => [
                'label' => 'menu.change_password',
                'route' => 'app_change_password',
                'icon' => 'fas fa-key',
                'requires_auth' => true,
            ],
            'logout' => [
                'label' => 'menu.logout',
                'route' => 'app_logout',
                'icon' => 'fas fa-sign-out-alt',
                'requires_auth' => true,
            ],
        ];

        return $this->filterMenuItems($menu);
    }

    private function getAdminDashboardMenu(): array
    {
        $menu = [
            'dashboard' => [
                'label' => 'menu.admin_dashboard',
                'route' => 'app_admin_dashboard',
                'icon' => 'fas fa-tachometer-alt',
            ],
            'users' => [
                'label' => 'menu.manage_users',
                'route' => 'app_admin_users',
                'icon' => 'fas fa-users',
                'submenu' => [
                    'all_users' => [
                        'label' => 'menu.all_users',
                        'route' => 'app_admin_users',
                    ],
                    'doctors' => [
                        'label' => 'menu.doctors',
                        'route' => 'app_admin_doctors',
                    ],
                    'patients' => [
                        'label' => 'menu.patients',
                        'route' => 'app_admin_patients',
                    ],
                ],
            ],
            'services' => [
                'label' => 'menu.manage_services',
                'route' => 'app_admin_services',
                'icon' => 'fas fa-clipboard-list',
            ],
            'appointments' => [
                'label' => 'menu.manage_appointments',
                'route' => 'app_admin_appointments',
                'icon' => 'fas fa-calendar-check',
            ],
            'statistics' => [
                'label' => 'menu.statistics',
                'route' => 'app_admin_statistics',
                'icon' => 'fas fa-chart-bar',
            ],
            'settings' => [
                'label' => 'menu.settings',
                'route' => 'app_admin_settings',
                'icon' => 'fas fa-cogs',
            ],
        ];

        return $this->filterMenuItems($menu);
    }

    private function getDoctorDashboardMenu(): array
    {
        $menu = [
            'dashboard' => [
                'label' => 'menu.doctor_dashboard',
                'route' => 'app_doctor_dashboard',
                'icon' => 'fas fa-tachometer-alt',
            ],
            'schedule' => [
                'label' => 'menu.schedule',
                'route' => 'app_doctor_schedule',
                'icon' => 'fas fa-calendar',
            ],
            'appointments' => [
                'label' => 'menu.appointments',
                'route' => 'app_doctor_appointments',
                'icon' => 'fas fa-calendar-check',
                'submenu' => [
                    'upcoming' => [
                        'label' => 'menu.upcoming_appointments',
                        'route' => 'app_doctor_appointments_upcoming',
                    ],
                    'past' => [
                        'label' => 'menu.past_appointments',
                        'route' => 'app_doctor_appointments_past',
                    ],
                ],
            ],
            'patients' => [
                'label' => 'menu.my_patients',
                'route' => 'app_doctor_patients',
                'icon' => 'fas fa-users',
            ],
            'availability' => [
                'label' => 'menu.availability',
                'route' => 'app_doctor_availability',
                'icon' => 'fas fa-clock',
            ],
        ];

        return $this->filterMenuItems($menu);
    }

    private function getPatientDashboardMenu(): array
    {
        $menu = [
            'dashboard' => [
                'label' => 'menu.patient_dashboard',
                'route' => 'app_patient_dashboard',
                'icon' => 'fas fa-tachometer-alt',
            ],
            'appointments' => [
                'label' => 'menu.my_appointments',
                'route' => 'app_patient_appointments',
                'icon' => 'fas fa-calendar-check',
                'submenu' => [
                    'upcoming' => [
                        'label' => 'menu.upcoming_appointments',
                        'route' => 'app_patient_appointments_upcoming',
                    ],
                    'past' => [
                        'label' => 'menu.past_appointments',
                        'route' => 'app_patient_appointments_past',
                    ],
                ],
            ],
            'book' => [
                'label' => 'menu.book_appointment',
                'route' => 'app_book_appointment',
                'icon' => 'fas fa-plus-circle',
            ],
            'medical_history' => [
                'label' => 'menu.medical_history',
                'route' => 'app_patient_medical_history',
                'icon' => 'fas fa-file-medical',
            ],
        ];

        return $this->filterMenuItems($menu);
    }

    private function filterMenuItems(array $menu): array
    {
        $filteredMenu = [];
        $currentRoute = $this->requestStack->getCurrentRequest()->get('_route');

        foreach ($menu as $key => $item) {
            // Skip items that require authentication if user is not logged in
            if (isset($item['requires_auth']) && $item['requires_auth'] && !$this->security->getUser()) {
                continue;
            }

            // Skip items that require specific roles
            if (isset($item['roles']) && !$this->security->isGranted($item['roles'])) {
                continue;
            }

            // Translate labels
            $item['label'] = $this->translator->trans($item['label']);

            // Check if this is the active menu item
            $item['active'] = $this->isMenuItemActive($item, $currentRoute);

            // Process submenu if exists
            if (isset($item['submenu'])) {
                $item['submenu'] = $this->filterMenuItems($item['submenu']);
                // If submenu is empty after filtering, skip this item
                if (empty($item['submenu'])) {
                    continue;
                }
            }

            // Generate URL
            $item['url'] = $this->router->generate($item['route'], $item['route_params'] ?? []);

            $filteredMenu[$key] = $item;
        }

        return $filteredMenu;
    }

    private function isMenuItemActive(array $item, string $currentRoute): bool
    {
        if ($item['route'] === $currentRoute) {
            return true;
        }

        if (isset($item['submenu'])) {
            foreach ($item['submenu'] as $subItem) {
                if ($this->isMenuItemActive($subItem, $currentRoute)) {
                    return true;
                }
            }
        }

        return false;
    }
}
