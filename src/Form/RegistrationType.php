<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name']])
            ->add('lastName', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'family-name']])
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail', 'attr' => ['autocomplete' => 'email']])
            ->add('phone', TelType::class, ['label' => 'Téléphone (facultatif)', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'help' => '8 caractères au minimum.',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(message: 'Choisissez un mot de passe.'),
                    new Length(min: 8, minMessage: 'Votre mot de passe doit contenir au moins {{ limit }} caractères.'),
                ],
            ])
            ->add('save', SubmitType::class, ['label' => 'Créer mon compte', 'attr' => ['class' => 'btn btn--primary btn--block']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
