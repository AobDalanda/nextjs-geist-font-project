<?php

namespace App\Service;

use App\Entity\Doctor;
use App\Entity\Service as MedicalService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SeoService
{
    private const DEFAULT_TITLE = 'Clinique - Votre santé, notre priorité';
    private const DEFAULT_DESCRIPTION = 'Centre médical proposant des consultations avec des médecins spécialistes, des services médicaux de qualité et une prise en charge personnalisée.';
    private const SITE_NAME = 'Clinique';

    private $requestStack;
    private $router;
    private $translator;
    private $breadcrumbService;

    private $metadata = [
        'title' => self::DEFAULT_TITLE,
        'description' => self::DEFAULT_DESCRIPTION,
        'keywords' => [],
        'robots' => 'index, follow',
        'canonical' => null,
        'og' => [],
        'twitter' => [],
        'schema' => [],
    ];

    public function __construct(
        RequestStack $requestStack,
        RouterInterface $router,
        TranslatorInterface $translator,
        BreadcrumbService $breadcrumbService
    ) {
        $this->requestStack = $requestStack;
        $this->router = $router;
        $this->translator = $translator;
        $this->breadcrumbService = $breadcrumbService;
    }

    public function setTitle(string $title, bool $appendSiteName = true): self
    {
        $this->metadata['title'] = $appendSiteName 
            ? sprintf('%s | %s', $title, self::SITE_NAME)
            : $title;

        // Update OpenGraph and Twitter titles
        $this->metadata['og']['title'] = $title;
        $this->metadata['twitter']['title'] = $title;

        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->metadata['description'] = $description;
        $this->metadata['og']['description'] = $description;
        $this->metadata['twitter']['description'] = $description;

        return $this;
    }

    public function setKeywords(array $keywords): self
    {
        $this->metadata['keywords'] = $keywords;
        return $this;
    }

    public function setRobots(string $robots): self
    {
        $this->metadata['robots'] = $robots;
        return $this;
    }

    public function setCanonical(?string $url = null): self
    {
        $request = $this->requestStack->getCurrentRequest();
        $this->metadata['canonical'] = $url ?? $request->getUri();
        return $this;
    }

    public function setImage(string $imageUrl): self
    {
        $this->metadata['og']['image'] = $imageUrl;
        $this->metadata['twitter']['image'] = $imageUrl;
        return $this;
    }

    public function addSchema(array $schema): self
    {
        $this->metadata['schema'][] = $schema;
        return $this;
    }

    public function getMetadata(): array
    {
        // Ensure canonical URL is set
        if (!$this->metadata['canonical']) {
            $this->setCanonical();
        }

        // Add breadcrumbs schema
        $this->addSchema($this->breadcrumbService->generateSchema());

        return $this->metadata;
    }

    public function configureDoctorPage(Doctor $doctor): self
    {
        $title = sprintf('Dr. %s - %s', $doctor->getFullName(), $doctor->getSpeciality());
        $description = sprintf(
            'Prenez rendez-vous avec Dr. %s, spécialiste en %s. Consultations disponibles à notre clinique.',
            $doctor->getFullName(),
            $doctor->getSpeciality()
        );

        $this->setTitle($title)
            ->setDescription($description)
            ->setKeywords([
                'docteur',
                $doctor->getFullName(),
                $doctor->getSpeciality(),
                'rendez-vous médical',
                'consultation',
                'clinique',
            ]);

        // Add doctor schema
        $this->addSchema([
            '@context' => 'https://schema.org',
            '@type' => 'Physician',
            'name' => 'Dr. ' . $doctor->getFullName(),
            'medicalSpecialty' => $doctor->getSpeciality(),
            'worksFor' => [
                '@type' => 'MedicalOrganization',
                'name' => self::SITE_NAME,
            ],
            'availableService' => $doctor->getServices()->map(function($service) {
                return [
                    '@type' => 'MedicalProcedure',
                    'name' => $service->getName(),
                ];
            })->toArray(),
        ]);

        return $this;
    }

    public function configureServicePage(MedicalService $service): self
    {
        $title = sprintf('%s - %s', $service->getName(), self::SITE_NAME);
        $description = $service->getDescription();

        $this->setTitle($title)
            ->setDescription($description)
            ->setKeywords([
                $service->getName(),
                'service médical',
                'consultation',
                'clinique',
                $service->getCategory(),
            ]);

        // Add medical service schema
        $this->addSchema([
            '@context' => 'https://schema.org',
            '@type' => 'MedicalProcedure',
            'name' => $service->getName(),
            'description' => $service->getDescription(),
            'performedBy' => [
                '@type' => 'MedicalOrganization',
                'name' => self::SITE_NAME,
            ],
            'price' => $service->getPrice(),
            'priceCurrency' => 'EUR',
        ]);

        return $this;
    }

    public function configureHomePage(): self
    {
        return $this->setTitle(self::DEFAULT_TITLE, false)
            ->setDescription(self::DEFAULT_DESCRIPTION)
            ->setKeywords([
                'clinique',
                'médecin',
                'rendez-vous médical',
                'consultation',
                'spécialiste',
                'santé',
            ])
            ->addSchema([
                '@context' => 'https://schema.org',
                '@type' => 'MedicalOrganization',
                'name' => self::SITE_NAME,
                'description' => self::DEFAULT_DESCRIPTION,
                '@id' => $this->router->generate('app_home', [], RouterInterface::ABSOLUTE_URL),
                'url' => $this->router->generate('app_home', [], RouterInterface::ABSOLUTE_URL),
                'logo' => '/images/logo.png', // Update with actual logo path
                'contactPoint' => [
                    '@type' => 'ContactPoint',
                    'telephone' => '+33123456789', // Update with actual phone
                    'contactType' => 'customer service',
                ],
            ]);
    }

    public function configureContactPage(): self
    {
        return $this->setTitle('Contact')
            ->setDescription('Contactez notre clinique pour toute question ou prise de rendez-vous. Notre équipe est à votre écoute.')
            ->setKeywords([
                'contact',
                'clinique',
                'rendez-vous',
                'téléphone',
                'email',
                'adresse',
            ])
            ->addSchema([
                '@context' => 'https://schema.org',
                '@type' => 'ContactPage',
                'name' => 'Contact ' . self::SITE_NAME,
                'description' => 'Page de contact de la clinique',
                'mainEntity' => [
                    '@type' => 'Organization',
                    'name' => self::SITE_NAME,
                    'telephone' => '+33123456789', // Update with actual phone
                    'email' => 'contact@clinique.fr', // Update with actual email
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => '123 Rue de la Santé',
                        'addressLocality' => 'Paris',
                        'postalCode' => '75000',
                        'addressCountry' => 'FR',
                    ],
                ],
            ]);
    }

    public function getJsonLd(): string
    {
        return json_encode(['@graph' => $this->metadata['schema']], JSON_PRETTY_PRINT);
    }
}
