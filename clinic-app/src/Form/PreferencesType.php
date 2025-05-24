<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('preferredNotification', ChoiceType::class, [
                'label' => 'Mode de notification préféré',
                'choices' => [
                    'Email' => 'email',
                    'SMS' => 'sms',
                    'Email et SMS' => 'both',
                ],
                'expanded' => true,
                'multiple' => false,
                'required' => true,
            ]);

        // Add availability toggle for doctors
        if ($options['is_doctor']) {
            $builder
                ->add('isAvailable', CheckboxType::class, [
                    'label' => 'Je suis disponible pour des rendez-vous',
                    'required' => false,
                ])
                ->add('workingHours', ChoiceType::class, [
                    'label' => 'Horaires de consultation',
                    'choices' => [
                        'Matin (8h-12h)' => 'morning',
                        'Après-midi (14h-18h)' => 'afternoon',
                        'Journée complète (8h-18h)' => 'full_day',
                    ],
                    'expanded' => true,
                    'multiple' => true,
                    'required' => false,
                ])
                ->add('breakTime', ChoiceType::class, [
                    'label' => 'Pause déjeuner',
                    'choices' => [
                        '12h00 - 13h00' => '12-13',
                        '12h30 - 13h30' => '12:30-13:30',
                        '13h00 - 14h00' => '13-14',
                    ],
                    'expanded' => false,
                    'multiple' => false,
                    'required' => false,
                    'placeholder' => 'Choisir une plage horaire',
                ])
                ->add('appointmentDuration', ChoiceType::class, [
                    'label' => 'Durée par défaut des consultations',
                    'choices' => [
                        '15 minutes' => 15,
                        '20 minutes' => 20,
                        '30 minutes' => 30,
                        '45 minutes' => 45,
                        '1 heure' => 60,
                    ],
                    'expanded' => false,
                    'multiple' => false,
                    'required' => true,
                ])
                ->add('maxDailyAppointments', ChoiceType::class, [
                    'label' => 'Nombre maximum de rendez-vous par jour',
                    'choices' => array_combine(
                        range(5, 20, 5),
                        range(5, 20, 5)
                    ),
                    'expanded' => false,
                    'multiple' => false,
                    'required' => true,
                ])
                ->add('allowUrgentAppointments', CheckboxType::class, [
                    'label' => 'Accepter les rendez-vous urgents',
                    'required' => false,
                    'help' => 'Les patients pourront vous contacter pour des urgences',
                ])
                ->add('autoConfirmAppointments', CheckboxType::class, [
                    'label' => 'Confirmation automatique des rendez-vous',
                    'required' => false,
                    'help' => 'Les rendez-vous seront automatiquement confirmés sans votre validation',
                ]);
        }

        // Add notification preferences for all users
        $builder
            ->add('emailNotifications', ChoiceType::class, [
                'label' => 'Notifications par email',
                'choices' => [
                    'Confirmation de rendez-vous' => 'appointment_confirmation',
                    'Rappel de rendez-vous' => 'appointment_reminder',
                    'Modification de rendez-vous' => 'appointment_modification',
                    'Annulation de rendez-vous' => 'appointment_cancellation',
                ],
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ])
            ->add('smsNotifications', ChoiceType::class, [
                'label' => 'Notifications par SMS',
                'choices' => [
                    'Confirmation de rendez-vous' => 'appointment_confirmation',
                    'Rappel de rendez-vous' => 'appointment_reminder',
                    'Modification de rendez-vous' => 'appointment_modification',
                    'Annulation de rendez-vous' => 'appointment_cancellation',
                ],
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ])
            ->add('reminderTiming', ChoiceType::class, [
                'label' => 'Rappel avant le rendez-vous',
                'choices' => [
                    '1 heure avant' => 'PT1H',
                    '2 heures avant' => 'PT2H',
                    '12 heures avant' => 'PT12H',
                    '24 heures avant' => 'P1D',
                    '48 heures avant' => 'P2D',
                ],
                'expanded' => false,
                'multiple' => false,
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_doctor' => false,
        ]);

        $resolver->setAllowedTypes('is_doctor', 'bool');
    }
}
