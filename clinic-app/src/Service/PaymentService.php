<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\Payment;
use App\Entity\Invoice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Psr\Log\LoggerInterface;
use Twig\Environment;

class PaymentService
{
    private const PAYMENT_METHODS = ['card', 'bank_transfer', 'cash'];
    private const PAYMENT_STATUSES = ['pending', 'completed', 'failed', 'refunded'];
    private const CURRENCIES = ['EUR'];
    private const VAT_RATE = 0.20; // 20%

    private $entityManager;
    private $security;
    private $mailer;
    private $logger;
    private $twig;
    private $settingsService;
    private $notificationService;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        MailerInterface $mailer,
        LoggerInterface $logger,
        Environment $twig,
        SettingsService $settingsService,
        NotificationService $notificationService
    ) {
        $this->entityManager = $entityManager;
        $this->security = $security;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->twig = $twig;
        $this->settingsService = $settingsService;
        $this->notificationService = $notificationService;
    }

    public function processPayment(
        Appointment $appointment,
        string $method,
        array $paymentDetails = []
    ): Payment {
        $this->validatePaymentMethod($method);
        
        try {
            $payment = new Payment();
            $payment->setAppointment($appointment)
                   ->setAmount($this->calculateAmount($appointment))
                   ->setMethod($method)
                   ->setCurrency('EUR')
                   ->setStatus('pending')
                   ->setDetails($paymentDetails);

            // Process payment based on method
            switch ($method) {
                case 'card':
                    $this->processCardPayment($payment, $paymentDetails);
                    break;
                case 'bank_transfer':
                    $this->processBankTransfer($payment, $paymentDetails);
                    break;
                case 'cash':
                    $this->processCashPayment($payment);
                    break;
            }

            $this->entityManager->persist($payment);
            $this->entityManager->flush();

            // Generate and send invoice
            $this->generateInvoice($payment);

            // Send confirmation
            $this->sendPaymentConfirmation($payment);

            $this->logger->info('Payment processed successfully', [
                'payment_id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'method' => $payment->getMethod(),
            ]);

            return $payment;
        } catch (\Exception $e) {
            $this->logger->error('Payment processing failed', [
                'error' => $e->getMessage(),
                'appointment_id' => $appointment->getId(),
                'method' => $method,
            ]);
            throw $e;
        }
    }

    public function refundPayment(Payment $payment, ?string $reason = null): Payment
    {
        if ($payment->getStatus() !== 'completed') {
            throw new BadRequestException('Only completed payments can be refunded.');
        }

        try {
            // Process refund based on payment method
            switch ($payment->getMethod()) {
                case 'card':
                    $this->processCardRefund($payment);
                    break;
                case 'bank_transfer':
                    $this->processBankTransferRefund($payment);
                    break;
                case 'cash':
                    $this->processCashRefund($payment);
                    break;
            }

            $payment->setStatus('refunded')
                   ->setRefundReason($reason)
                   ->setRefundedAt(new \DateTime());

            $this->entityManager->flush();

            // Send refund notification
            $this->notificationService->sendRefundNotification($payment);

            $this->logger->info('Payment refunded successfully', [
                'payment_id' => $payment->getId(),
                'amount' => $payment->getAmount(),
                'reason' => $reason,
            ]);

            return $payment;
        } catch (\Exception $e) {
            $this->logger->error('Payment refund failed', [
                'error' => $e->getMessage(),
                'payment_id' => $payment->getId(),
            ]);
            throw $e;
        }
    }

    public function generateInvoice(Payment $payment): Invoice
    {
        $invoice = new Invoice();
        $invoice->setPayment($payment)
                ->setNumber($this->generateInvoiceNumber())
                ->setDate(new \DateTime())
                ->setDueDate((new \DateTime())->modify('+30 days'))
                ->setAmount($payment->getAmount())
                ->setVatRate(self::VAT_RATE)
                ->setVatAmount($payment->getAmount() * self::VAT_RATE)
                ->setTotalAmount($payment->getAmount() * (1 + self::VAT_RATE))
                ->setStatus($payment->getStatus() === 'completed' ? 'paid' : 'pending');

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        // Send invoice by email
        $this->sendInvoice($invoice);

        return $invoice;
    }

    public function getPaymentMethods(): array
    {
        return array_map(function ($method) {
            return [
                'code' => $method,
                'name' => ucfirst($method),
                'enabled' => $this->isPaymentMethodEnabled($method),
                'fees' => $this->getPaymentMethodFees($method),
            ];
        }, self::PAYMENT_METHODS);
    }

    public function calculateAmount(Appointment $appointment): float
    {
        $baseAmount = $appointment->getService()->getPrice();
        $discount = $this->calculateDiscount($appointment);
        
        return $baseAmount - $discount;
    }

    private function calculateDiscount(Appointment $appointment): float
    {
        $patient = $appointment->getPatient();
        $discount = 0;

        // Loyalty program discount
        if ($this->isEligibleForLoyaltyDiscount($patient)) {
            $discount += $this->calculateLoyaltyDiscount($appointment);
        }

        // Special promotions
        if ($this->hasActivePromotion($appointment)) {
            $discount += $this->calculatePromotionDiscount($appointment);
        }

        return $discount;
    }

    private function processCardPayment(Payment $payment, array $details): void
    {
        // Integrate with payment gateway (e.g., Stripe)
        // This is a placeholder for actual payment processing
        if (!isset($details['token'])) {
            throw new BadRequestException('Payment token is required for card payments.');
        }

        try {
            // Process payment with payment gateway
            // $result = $this->paymentGateway->processPayment($details['token'], $payment->getAmount());
            
            $payment->setStatus('completed')
                   ->setTransactionId('TRANS_' . uniqid())
                   ->setCompletedAt(new \DateTime());
        } catch (\Exception $e) {
            $payment->setStatus('failed')
                   ->setFailureReason($e->getMessage());
            throw $e;
        }
    }

    private function processBankTransfer(Payment $payment, array $details): void
    {
        // Generate bank transfer reference
        $reference = 'BT_' . uniqid();
        
        $payment->setStatus('pending')
               ->setTransactionId($reference)
               ->setDetails(array_merge($details, [
                   'bank_reference' => $reference,
                   'bank_details' => $this->getBankDetails(),
               ]));
    }

    private function processCashPayment(Payment $payment): void
    {
        $payment->setStatus('completed')
               ->setTransactionId('CASH_' . uniqid())
               ->setCompletedAt(new \DateTime());
    }

    private function sendPaymentConfirmation(Payment $payment): void
    {
        $email = (new Email())
            ->from($this->settingsService->get('email_from'))
            ->to($payment->getAppointment()->getPatient()->getEmail())
            ->subject('Confirmation de paiement')
            ->html($this->twig->render('emails/payment_confirmation.html.twig', [
                'payment' => $payment,
            ]));

        $this->mailer->send($email);
    }

    private function sendInvoice(Invoice $invoice): void
    {
        $email = (new Email())
            ->from($this->settingsService->get('email_from'))
            ->to($invoice->getPayment()->getAppointment()->getPatient()->getEmail())
            ->subject('Facture ' . $invoice->getNumber())
            ->html($this->twig->render('emails/invoice.html.twig', [
                'invoice' => $invoice,
            ]));

        $this->mailer->send($email);
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = date('Y');
        $lastInvoice = $this->entityManager->getRepository(Invoice::class)
            ->findOneBy([], ['id' => 'DESC']);
        
        $sequence = $lastInvoice ? (intval(substr($lastInvoice->getNumber(), -5)) + 1) : 1;
        
        return sprintf('%s%05d', $prefix, $sequence);
    }

    private function validatePaymentMethod(string $method): void
    {
        if (!in_array($method, self::PAYMENT_METHODS)) {
            throw new BadRequestException(sprintf(
                'Invalid payment method. Allowed methods: %s',
                implode(', ', self::PAYMENT_METHODS)
            ));
        }

        if (!$this->isPaymentMethodEnabled($method)) {
            throw new BadRequestException(sprintf(
                'Payment method %s is currently disabled.',
                $method
            ));
        }
    }

    private function isPaymentMethodEnabled(string $method): bool
    {
        return $this->settingsService->get('payment_method_' . $method . '_enabled', true);
    }

    private function getPaymentMethodFees(string $method): float
    {
        return $this->settingsService->get('payment_method_' . $method . '_fees', 0.0);
    }

    private function getBankDetails(): array
    {
        return [
            'bank_name' => $this->settingsService->get('bank_name'),
            'iban' => $this->settingsService->get('bank_iban'),
            'bic' => $this->settingsService->get('bank_bic'),
        ];
    }

    private function isEligibleForLoyaltyDiscount(User $patient): bool
    {
        $completedAppointments = $this->entityManager->getRepository(Appointment::class)
            ->countCompletedAppointmentsForPatient($patient);
            
        return $completedAppointments >= 5;
    }

    private function calculateLoyaltyDiscount(Appointment $appointment): float
    {
        return $appointment->getService()->getPrice() * 0.10; // 10% discount
    }

    private function hasActivePromotion(Appointment $appointment): bool
    {
        // Implementation depends on your promotion system
        return false;
    }

    private function calculatePromotionDiscount(Appointment $appointment): float
    {
        // Implementation depends on your promotion system
        return 0;
    }
}
