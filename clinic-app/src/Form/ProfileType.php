<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer votre prénom',
                    ]),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer votre nom',
                    ]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer votre email',
                    ]),
                    new Email([
                        'message' => 'Veuillez entrer un email valide',
                    ]),
                ],
            ])
            ->add('phoneNumber', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
            ]);

        // Add speciality field only for doctors
        if ($options['is_doctor']) {
            $builder->add('speciality', ChoiceType::class, [
                'label' => 'Spécialité',
                'choices' => [
                    'Médecin généraliste' => 'Médecin généraliste',
                    'Cardiologue' => 'Cardiologue',
                    'Dermatologue' => 'Dermatologue',
                    'Pédiatre' => 'Pédiatre',
                    'Gynécologue' => 'Gynécologue',
                    'Ophtalmologue' => 'Ophtalmologue',
                    'ORL' => 'ORL',
                    'Psychiatre' => 'Psychiatre',
                    'Dentiste' => 'Dentiste',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez sélectionner votre spécialité',
                    ]),
                ],
            ]);
        }
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
