<?php

namespace App\Form;

use App\Entity\Vehicle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VehicleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('model', TextType::class, ['label' => 'Modèle', 'attr' => ['placeholder' => 'Dacia Logan']])
            ->add('color', TextType::class, ['label' => 'Couleur', 'attr' => ['placeholder' => 'bleue']])
            ->add('passengerSeats', ChoiceType::class, [
                'label' => 'Places passagers',
                'choices' => [
                    '1 place' => 1,
                    '2 places' => 2,
                    '3 places' => 3,
                    '4 places (berline)' => 4,
                    '5 places' => 5,
                    '6 places (monospace)' => 6,
                ],
                'data' => 4,
            ])
            ->add('save', SubmitType::class, ['label' => 'Ajouter le véhicule', 'attr' => ['class' => 'btn btn--primary']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Vehicle::class]);
    }
}
