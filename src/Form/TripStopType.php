<?php

namespace App\Form;

use App\Entity\City;
use App\Entity\TripStop;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TripStop::class]);
    }
}
