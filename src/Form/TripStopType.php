<?php

namespace App\Form;

use App\Entity\City;
use App\Entity\TripStop;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TripStopType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('city', EntityType::class, [
                'class' => City::class,
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                'label' => 'Ville',
                'placeholder' => 'Choisir une ville',
                'constraints' => [new NotNull(message: 'Choisissez une ville.')],
            ])
            ->add('departureAt', DateTimeType::class, [
                'label' => 'Date et heure',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotNull(message: 'Indiquez la date et l\'heure.')],
            ])
            ->add('meetingPoint', TextType::class, [
                'label' => 'Point de rendez-vous',
                'attr' => ['placeholder' => 'Gare routière'],
                'constraints' => [new NotBlank(message: 'Indiquez un point de rendez-vous.')],
            ])
            ->add('legPriceToNext', IntegerType::class, [
                'label' => 'Prix jusqu\'à l\'arrêt suivant',
                'required' => false,
                'help' => 'Prix par place jusqu\'à l\'arrêt suivant (MAD)',
                'attr' => ['min' => 0, 'inputmode' => 'numeric'],
            ])
            ->add('meetingLatitude', HiddenType::class, self::coordinateOptions())
            ->add('meetingLongitude', HiddenType::class, self::coordinateOptions());

        // Hidden fields carry text: turn it into a float (empty → null); the range is checked by the entity.
        foreach (['meetingLatitude', 'meetingLongitude'] as $field) {
            $builder->get($field)->addModelTransformer(new CallbackTransformer(
                static fn (?float $value): string => null === $value ? '' : (string) $value,
                static function (?string $value): ?float {
                    if (null === $value || '' === trim($value)) {
                        return null;
                    }
                    if (!is_numeric($value)) {
                        throw new TransformationFailedException('Not a number.');
                    }

                    return (float) $value;
                },
            ));
        }
    }

    /** Hidden coordinate filled by the map: empty means "not placed"; anything else must be a real coordinate. */
    private static function coordinateOptions(): array
    {
        return [
            'required' => false,
            'empty_data' => null,
            'invalid_message' => 'Coordonnée invalide.',
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TripStop::class]);
    }
}
