<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class ContactController extends AbstractController
{
    #[Route('/contact/send', name: 'app_contact_send', methods: ['POST'])]
    public function send(Request $request, MailerInterface $mailer): Response
    {
        $name = $request->request->get('name');
        $email = $request->request->get('email');
        $subject = $request->request->get('subject');
        $message = $request->request->get('message');

        // Validate form data
        if (!$name || !$email || !$subject || !$message) {
            $this->addFlash('error', 'Tous les champs sont obligatoires.');
            return $this->redirectToRoute('app_contact');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'L\'adresse email n\'est pas valide.');
            return $this->redirectToRoute('app_contact');
        }

        try {
            // Send email to admin
            $adminEmail = (new Email())
                ->from($email)
                ->to('admin@clinique.fr')
                ->subject('Nouveau message de contact - ' . $subject)
                ->html($this->renderView('emails/contact.html.twig', [
                    'name' => $name,
                    'email' => $email,
                    'subject' => $this->getSubjectLabel($subject),
                    'message' => $message,
                ]));

            $mailer->send($adminEmail);

            // Send confirmation email to user
            $userEmail = (new Email())
                ->from('noreply@clinique.fr')
                ->to($email)
                ->subject('Confirmation de votre message')
                ->html($this->renderView('emails/contact_confirmation.html.twig', [
                    'name' => $name,
                ]));

            $mailer->send($userEmail);

            $this->addFlash('success', 'Votre message a été envoyé avec succès. Nous vous répondrons dans les plus brefs délais.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Une erreur est survenue lors de l\'envoi du message. Veuillez réessayer plus tard.');
        }

        return $this->redirectToRoute('app_contact');
    }

    private function getSubjectLabel(string $subject): string
    {
        $subjects = [
            'rdv' => 'Prise de rendez-vous',
            'info' => 'Demande d\'information',
            'urgence' => 'Urgence',
            'autre' => 'Autre',
        ];

        return $subjects[$subject] ?? 'Autre';
    }
}
