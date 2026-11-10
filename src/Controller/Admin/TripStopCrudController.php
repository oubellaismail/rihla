<?php

namespace App\Controller\Admin;

use App\Entity\TripStop;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class TripStopCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TripStop::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Arrêt')
            ->setEntityLabelInPlural('Arrêts')
            ->setDefaultSort(['departureAt' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('trip', 'Trajet');
        yield IntegerField::new('position', 'Position');
        yield AssociationField::new('city', 'Ville');
        yield DateTimeField::new('departureAt', 'Départ');
        yield TextField::new('meetingPoint', 'Point de rendez-vous');
        yield IntegerField::new('legPriceToNext', 'Prix tronçon suivant (MAD)');
        yield IntegerField::new('legDistanceKmToNext', 'Distance tronçon suivant (km)');
    }
}
