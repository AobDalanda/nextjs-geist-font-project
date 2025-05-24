<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\Security;
use Psr\Log\LoggerInterface;

class LocaleService
{
    private const SUPPORTED_LOCALES = ['fr', 'en', 'es', 'de'];
    private const DEFAULT_LOCALE = 'fr';
    private const LOCALE_PATTERNS = [
        'fr' => [
            'date' => 'd/m/Y',
            'time' => 'H:i',
            'datetime' => 'd/m/Y H:i',
            'currency' => '€',
            'decimal_separator' => ',',
            'thousands_separator' => ' ',
            'phone' => '+33 X XX XX XX XX',
        ],
        'en' => [
            'date' => 'Y-m-d',
            'time' => 'H:i',
            'datetime' => 'Y-m-d H:i',
            'currency' => '€',
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'phone' => '+33 X XX XX XX XX',
        ],
    ];

    private $requestStack;
    private $localeSwitcher;
    private $translator;
    private $params;
    private $security;
    private $logger;
    private $settingsService;

    public function __construct(
        RequestStack $requestStack,
        LocaleSwitcher $localeSwitcher,
        TranslatorInterface $translator,
        ParameterBagInterface $params,
        Security $security,
        LoggerInterface $logger,
        SettingsService $settingsService
    ) {
        $this->requestStack = $requestStack;
        $this->localeSwitcher = $localeSwitcher;
        $this->translator = $translator;
        $this->params = $params;
        $this->security = $security;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
    }

    public function getCurrentLocale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? self::DEFAULT_LOCALE;
    }

    public function setLocale(string $locale): void
    {
        if (!in_array($locale, self::SUPPORTED_LOCALES)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale: %s', $locale));
        }

        try {
            $this->localeSwitcher->setLocale($locale);
            
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
                $request->setLocale($locale);
            }

            // Update user preference if authenticated
            $user = $this->security->getUser();
            if ($user && method_exists($user, 'setLocale')) {
                $user->setLocale($locale);
            }

            $this->logger->info('Locale changed', [
                'locale' => $locale,
                'user' => $user?->getUserIdentifier(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to change locale', [
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getSupportedLocales(): array
    {
        return array_map(function ($locale) {
            return [
                'code' => $locale,
                'name' => $this->getLocaleName($locale),
                'native_name' => $this->getLocaleNativeName($locale),
                'flag' => $this->getLocaleFlag($locale),
            ];
        }, self::SUPPORTED_LOCALES);
    }

    public function formatDate(\DateTime $date, string $format = null): string
    {
        $locale = $this->getCurrentLocale();
        $pattern = $format ?? self::LOCALE_PATTERNS[$locale]['date'];
        
        return $date->format($pattern);
    }

    public function formatTime(\DateTime $time, string $format = null): string
    {
        $locale = $this->getCurrentLocale();
        $pattern = $format ?? self::LOCALE_PATTERNS[$locale]['time'];
        
        return $time->format($pattern);
    }

    public function formatDateTime(\DateTime $datetime, string $format = null): string
    {
        $locale = $this->getCurrentLocale();
        $pattern = $format ?? self::LOCALE_PATTERNS[$locale]['datetime'];
        
        return $datetime->format($pattern);
    }

    public function formatCurrency(float $amount, string $currency = null): string
    {
        $locale = $this->getCurrentLocale();
        $currencySymbol = $currency ?? self::LOCALE_PATTERNS[$locale]['currency'];
        
        $formattedAmount = number_format(
            $amount,
            2,
            self::LOCALE_PATTERNS[$locale]['decimal_separator'],
            self::LOCALE_PATTERNS[$locale]['thousands_separator']
        );

        return $locale === 'en' ? "$currencySymbol$formattedAmount" : "$formattedAmount$currencySymbol";
    }

    public function formatPhoneNumber(string $phoneNumber): string
    {
        $locale = $this->getCurrentLocale();
        $pattern = self::LOCALE_PATTERNS[$locale]['phone'];
        
        // Remove all non-digit characters
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Format according to pattern
        $formatted = '';
        $digitIndex = 0;
        
        for ($i = 0; $i < strlen($pattern); $i++) {
            if ($pattern[$i] === 'X') {
                $formatted .= $digits[$digitIndex] ?? '';
                $digitIndex++;
            } else {
                $formatted .= $pattern[$i];
            }
        }

        return $formatted;
    }

    public function getDateFormat(): string
    {
        return self::LOCALE_PATTERNS[$this->getCurrentLocale()]['date'];
    }

    public function getTimeFormat(): string
    {
        return self::LOCALE_PATTERNS[$this->getCurrentLocale()]['time'];
    }

    public function getDateTimeFormat(): string
    {
        return self::LOCALE_PATTERNS[$this->getCurrentLocale()]['datetime'];
    }

    public function getDecimalSeparator(): string
    {
        return self::LOCALE_PATTERNS[$this->getCurrentLocale()]['decimal_separator'];
    }

    public function getThousandsSeparator(): string
    {
        return self::LOCALE_PATTERNS[$this->getCurrentLocale()]['thousands_separator'];
    }

    private function getLocaleName(string $locale): string
    {
        return $this->translator->trans('locale.' . $locale);
    }

    private function getLocaleNativeName(string $locale): string
    {
        $names = [
            'fr' => 'Français',
            'en' => 'English',
            'es' => 'Español',
            'de' => 'Deutsch',
        ];

        return $names[$locale] ?? $locale;
    }

    private function getLocaleFlag(string $locale): string
    {
        $flags = [
            'fr' => '🇫🇷',
            'en' => '🇬🇧',
            'es' => '🇪🇸',
            'de' => '🇩🇪',
        ];

        return $flags[$locale] ?? '';
    }

    public function getLocaleMetadata(string $locale = null): array
    {
        $locale = $locale ?? $this->getCurrentLocale();

        return [
            'code' => $locale,
            'name' => $this->getLocaleName($locale),
            'native_name' => $this->getLocaleNativeName($locale),
            'flag' => $this->getLocaleFlag($locale),
            'patterns' => self::LOCALE_PATTERNS[$locale] ?? self::LOCALE_PATTERNS[self::DEFAULT_LOCALE],
            'direction' => $this->getTextDirection($locale),
            'is_default' => $locale === self::DEFAULT_LOCALE,
            'is_current' => $locale === $this->getCurrentLocale(),
        ];
    }

    private function getTextDirection(string $locale): string
    {
        $rtlLocales = ['ar', 'he', 'fa'];
        return in_array($locale, $rtlLocales) ? 'rtl' : 'ltr';
    }

    public function getPreferredLocale(): string
    {
        // Check user preference if authenticated
        $user = $this->security->getUser();
        if ($user && method_exists($user, 'getLocale')) {
            $userLocale = $user->getLocale();
            if ($userLocale && in_array($userLocale, self::SUPPORTED_LOCALES)) {
                return $userLocale;
            }
        }

        // Check Accept-Language header
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $preferredLanguage = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);
            if ($preferredLanguage) {
                return $preferredLanguage;
            }
        }

        return self::DEFAULT_LOCALE;
    }
}
