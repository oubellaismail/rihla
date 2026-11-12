<?php

namespace App\Form;

use App\Entity\Vehicle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

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
            ->add('trunkLitres', IntegerType::class, [
                'label' => 'Volume du coffre',
                'help' => 'Le chiffre de la fiche technique, sièges en place. Ex. : Peugeot 208 ≈ 310 L, Renault Clio ≈ 390 L, Dacia Duster ≈ 445 L, Dacia Logan ≈ 530 L.',
                'attr' => ['min' => 50, 'max' => 3000, 'inputmode' => 'numeric', 'placeholder' => '530'],
                'constraints' => [new NotNull(message: 'Indiquez le volume du coffre.')],
                // An empty value must reach validation, not the non-nullable setter.
                'setter' => static function (Vehicle $vehicle, ?int $litres): void {
                    if (null !== $litres) {
                        $vehicle->setTrunkLitres($litres);
                    }
                },
            ])
            ->add('save', SubmitType::class, ['label' => 'Ajouter le véhicule', 'attr' => ['class' => 'btn btn--primary']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Vehicle::class]);
    }
}
