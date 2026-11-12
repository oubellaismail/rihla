<?php

namespace App\Form;

use App\Entity\Trip;
use App\Entity\User;
use App\Entity\Vehicle;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TripPublishType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $owner = $options['owner'];

        $builder
            ->add('vehicle', EntityType::class, [
                'class' => Vehicle::class,
                'choice_label' => static fn (Vehicle $v) => sprintf('%s %s (%d places)', $v->getModel(), $v->getColor(), $v->getPassengerSeats()),
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('v')
                    ->where('v.owner = :owner')->setParameter('owner', $owner)->orderBy('v.id', 'ASC'),
                'label' => 'Véhicule',
                'placeholder' => 'Choisir un véhicule',
                'constraints' => [new NotNull(message: 'Choisissez un véhicule.')],
                // An empty choice must reach validation, not the non-nullable setter.
                'setter' => static function (Trip $trip, ?Vehicle $vehicle): void {
                    if (null !== $vehicle) {
                        $trip->setVehicle($vehicle);
                    }
                },
            ])
            ->add('seatsOffered', ChoiceType::class, [
                'label' => 'Places proposées',
                'placeholder' => 'Choisir',
                'constraints' => [new NotNull(message: 'Indiquez le nombre de places proposées.')],
                'setter' => static function (Trip $trip, ?int $seats): void {
                    if (null !== $seats) {
                        $trip->setSeatsOffered($seats);
                    }
                },
                'choices' => ['1 place' => 1, '2 places' => 2, '3 places' => 3, '4 places' => 4, '5 places' => 5, '6 places' => 6],
                'help' => 'Les places que vous mettez à disposition, sans compter la vôtre.',
            ])
            ->add('trunkLitres', IntegerType::class, [
                'label' => 'Coffre pour ce trajet',
                'required' => false,
                'help' => 'Volume laissé aux voyageurs, en litres. Laissez vide pour tout le coffre du véhicule ; baissez-le si vous emportez vos propres affaires, 0 pour aucun bagage en soute.',
                'attr' => ['min' => 0, 'max' => 3000, 'inputmode' => 'numeric', 'placeholder' => 'Tout le coffre'],
                // Empty means « the vehicle's whole trunk »: the publisher fills it in.
                'getter' => static fn (Trip $trip): ?int => null,
                'setter' => static function (Trip $trip, ?int $litres): void {
                    $trip->setTrunkLitres($litres);
                },
            ])
            ->add('stops', CollectionType::class, [
                'entry_type' => TripStopType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
            ])
            ->add('save', SubmitType::class, ['label' => 'Publier le trajet', 'attr' => ['class' => 'btn btn--primary']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('owner');
        $resolver->setAllowedTypes('owner', User::class);
        $resolver->setDefaults(['data_class' => Trip::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'trip';
    }
}
