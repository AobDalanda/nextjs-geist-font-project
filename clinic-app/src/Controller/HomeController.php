<?php

namespace App\Controller;

use App\Form\ProfileType;
use App\Form\ChangePasswordType;
use App\Form\PreferencesType;
use App\Repository\AppointmentRepository;
use App\Repository\DoctorRepository;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(DoctorRepository $doctorRepository, ServiceRepository $serviceRepository): Response
    {
        return $this->render('home/index.html.twig', [
            'doctors' => $doctorRepository->findBy(['isAvailable' => true], ['lastName' => 'ASC'], 4),
            'services' => $serviceRepository->findBy(['isActive' => true], ['name' => 'ASC'], 6)
        ]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function dashboard(
        AppointmentRepository $appointmentRepository,
        ServiceRepository $serviceRepository
    ): Response {
        $user = $this->getUser();

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->isGranted('ROLE_DOCTOR')) {
            // Get upcoming appointments for the doctor
            $appointments = $appointmentRepository->findUpcomingAppointmentsForDoctor($user);
            $template = 'home/doctor_dashboard.html.twig';

            // Get statistics for doctor dashboard
            $todayAppointments = $appointmentRepository->findTodayAppointmentsForDoctor($user);
            $weeklyAppointments = $appointmentRepository->findWeeklyAppointmentsForDoctor($user);
            $completedAppointments = $appointmentRepository->findCompletedAppointmentsForDoctor($user);
            $pendingAppointments = $appointmentRepository->findPendingAppointmentsForDoctor($user);

            return $this->render($template, [
                'user' => $user,
                'appointments' => $appointments,
                'todayAppointments' => $todayAppointments,
                'weeklyAppointments' => $weeklyAppointments,
                'completedAppointments' => $completedAppointments,
                'pendingAppointments' => $pendingAppointments,
            ]);
        } else {
            // Get upcoming appointments for the patient
            $appointments = $appointmentRepository->findUpcomingAppointmentsForPatient($user);
            $template = 'home/patient_dashboard.html.twig';

            return $this->render($template, [
                'user' => $user,
                'appointments' => $appointments,
                'services' => $serviceRepository->findActiveServices(),
            ]);
        }
    }

    #[Route('/profile', name: 'app_profile')]
    #[IsGranted('ROLE_USER')]
    public function profile(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = $this->getUser();
        $isDoctor = $this->isGranted('ROLE_DOCTOR');

        // Profile form
        $profileForm = $this->createForm(ProfileType::class, $user, [
            'is_doctor' => $isDoctor,
        ]);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Vos informations ont été mises à jour avec succès.');
            return $this->redirectToRoute('app_profile');
        }

        // Password form
        $passwordForm = $this->createForm(ChangePasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            if (!$passwordHasher->isPasswordValid($user, $passwordForm->get('currentPassword')->getData())) {
                $this->addFlash('error', 'Le mot de passe actuel est incorrect.');
            } else {
                $user->setPassword(
                    $passwordHasher->hashPassword(
                        $user,
                        $passwordForm->get('newPassword')->getData()
                    )
                );
                $entityManager->flush();
                $this->addFlash('success', 'Votre mot de passe a été modifié avec succès.');
            }
            return $this->redirectToRoute('app_profile');
        }

        // Preferences form
        $preferencesForm = $this->createForm(PreferencesType::class, $user, [
            'is_doctor' => $isDoctor,
        ]);
        $preferencesForm->handleRequest($request);

        if ($preferencesForm->isSubmitted() && $preferencesForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Vos préférences ont été mises à jour avec succès.');
            return $this->redirectToRoute('app_profile');
        }

        return $this->render('home/profile.html.twig', [
            'user' => $user,
            'profileForm' => $profileForm->createView(),
            'passwordForm' => $passwordForm->createView(),
            'preferencesForm' => $preferencesForm->createView(),
        ]);
    }

    #[Route('/contact', name: 'app_contact')]
    public function contact(): Response
    {
        return $this->render('home/contact.html.twig');
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }
}
