<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Rating (1 to 5) and optional comment; mapped to an array. */
final class ReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rating', ChoiceType::class, [
                'label' => 'Votre note',
                'choices' => [
                    '1 sur 5' => 1,
                    '2 sur 5' => 2,
                    '3 sur 5' => 3,
                    '4 sur 5' => 4,
                    '5 sur 5' => 5,
                ],
                'expanded' => true,
                'multiple' => false,
                'constraints' => [new NotBlank(message: 'Choisissez une note.')],
                'invalid_message' => 'Choisissez une note de 1 à 5.',
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Votre commentaire (facultatif)',
                'required' => false,
                'constraints' => [new Length(max: 1000, maxMessage: 'Le commentaire ne doit pas dépasser 1000 caractères.')],
                'attr' => ['rows' => 5, 'maxlength' => 1000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }

    public function getBlockPrefix(): string
    {
        return 'review';
    }
}
