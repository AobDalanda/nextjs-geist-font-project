<?php

namespace App\Service;

use App\Entity\Service as MedicalService;
use App\Entity\Doctor;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\String\Slugger\SluggerInterface;

class MedicalServiceManager
{
    private $entityManager;
    private $serviceRepository;
    private $security;
    private $slugger;

    public function __construct(
        EntityManagerInterface $entityManager,
        ServiceRepository $serviceRepository,
        Security $security,
        SluggerInterface $slugger
    ) {
        $this->entityManager = $entityManager;
        $this->serviceRepository = $serviceRepository;
        $this->security = $security;
        $this->slugger = $slugger;
    }

    public function createService(array $data): MedicalService
    {
        $this->validateAdminAccess();

        // Check for duplicate name
        if ($this->serviceRepository->findOneBy(['name' => $data['name']])) {
            throw new BadRequestException('Un service avec ce nom existe déjà.');
        }

        $service = new MedicalService();
        $service->setName($data['name'])
                ->setDescription($data['description'])
                ->setDuration($data['duration'])
                ->setPrice($data['price'])
                ->setIsActive(true)
                ->setSlug($this->createSlug($data['name']));

        if (isset($data['category'])) {
            $service->setCategory($data['category']);
        }

        $this->entityManager->persist($service);
        $this->entityManager->flush();

        return $service;
    }

    public function updateService(MedicalService $service, array $data): MedicalService
    {
        $this->validateAdminAccess();

        // Check for duplicate name if name is being changed
        if ($data['name'] !== $service->getName() &&
            $this->serviceRepository->findOneBy(['name' => $data['name']])) {
            throw new BadRequestException('Un service avec ce nom existe déjà.');
        }

        $service->setName($data['name'])
                ->setDescription($data['description'])
                ->setDuration($data['duration'])
                ->setPrice($data['price'])
                ->setSlug($this->createSlug($data['name']));

        if (isset($data['category'])) {
            $service->setCategory($data['category']);
        }

        if (isset($data['isActive'])) {
            $service->setIsActive($data['isActive']);
        }

        $this->entityManager->flush();

        return $service;
    }

    public function deleteService(MedicalService $service): void
    {
        $this->validateAdminAccess();

        // Check if service has any appointments
        if ($service->getAppointments()->count() > 0) {
            throw new BadRequestException('Ce service ne peut pas être supprimé car il est lié à des rendez-vous.');
        }

        $this->entityManager->remove($service);
        $this->entityManager->flush();
    }

    public function toggleServiceStatus(MedicalService $service): void
    {
        $this->validateAdminAccess();

        $service->setIsActive(!$service->isActive());
        $this->entityManager->flush();
    }

    public function assignDoctorToService(MedicalService $service, Doctor $doctor): void
    {
        $this->validateAdminAccess();

        if (!$service->getDoctors()->contains($doctor)) {
            $service->addDoctor($doctor);
            $this->entityManager->flush();
        }
    }

    public function removeDoctorFromService(MedicalService $service, Doctor $doctor): void
    {
        $this->validateAdminAccess();

        if ($service->getDoctors()->contains($doctor)) {
            $service->removeDoctor($doctor);
            $this->entityManager->flush();
        }
    }

    public function getActiveServices(): array
    {
        return $this->serviceRepository->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    public function getServicesByCategory(string $category): array
    {
        return $this->serviceRepository->findBy(
            ['category' => $category, 'isActive' => true],
            ['name' => 'ASC']
        );
    }

    public function getServicesByDoctor(Doctor $doctor): array
    {
        return $this->serviceRepository->findServicesByDoctor($doctor);
    }

    public function searchServices(string $query): array
    {
        return $this->serviceRepository->searchServices($query);
    }

    private function validateAdminAccess(): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new BadRequestException('Vous n\'avez pas les droits nécessaires pour gérer les services.');
        }
    }

    private function createSlug(string $name): string
    {
        return strtolower($this->slugger->slug($name));
    }

    public function getServiceStatistics(): array
    {
        return [
            'total' => $this->serviceRepository->count([]),
            'active' => $this->serviceRepository->count(['isActive' => true]),
            'inactive' => $this->serviceRepository->count(['isActive' => false]),
            'mostRequested' => $this->serviceRepository->findMostRequestedServices(),
            'averagePrice' => $this->serviceRepository->getAveragePrice(),
            'categoryCounts' => $this->serviceRepository->getServiceCountByCategory(),
        ];
    }

    public function validateServiceAvailability(MedicalService $service, Doctor $doctor, \DateTime $dateTime): bool
    {
        // Check if service is active
        if (!$service->isActive()) {
            return false;
        }

        // Check if doctor provides this service
        if (!$service->getDoctors()->contains($doctor)) {
            return false;
        }

        // Check if doctor is available at the given time
        $dayOfWeek = strtolower($dateTime->format('l'));
        $schedule = $doctor->getSchedule()[$dayOfWeek] ?? null;

        if (!$schedule) {
            return false;
        }

        $time = $dateTime->format('H:i');
        return $time >= $schedule['start'] && $time <= $schedule['end'];
    }
}
