<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'New Password',
                    'attr' => [
                        'class' => 'form-control',
                        'autocomplete' => 'new-password'
                    ],
                    'constraints' => [
                        new NotBlank([
                            'message' => 'Please enter a password',
                        ]),
                        new Length([
                            'min' => 8,
                            'minMessage' => 'Your password should be at least {{ limit }} characters',
                            'max' => 4096,
                        ]),
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirm New Password',
                    'attr' => [
                        'class' => 'form-control',
                        'autocomplete' => 'new-password'
                    ],
                ],
                'invalid_message' => 'The password fields must match.',
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
