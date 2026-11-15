<?php

namespace App\Controller\Admin;

use App\Entity\Vehicle;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

class VehicleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Vehicle::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Véhicule')
            ->setEntityLabelInPlural('Véhicules');
    }

    /** Owned by the application's own flows: the back-office only looks at it. */
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('owner', 'Propriétaire');
        yield TextField::new('model', 'Modèle');
        yield TextField::new('color', 'Couleur');
        yield IntegerField::new('passengerSeats', 'Places passagers');
        yield IntegerField::new('trunkLitres', 'Coffre (L)');
        yield DateTimeField::new('archivedAt', 'État')
            ->hideOnForm()
            ->setTemplatePath('admin/field/formatted.html.twig')
            ->formatValue(static fn ($value, Vehicle $v) => null !== $v->getArchivedAt() ? 'Retiré' : 'Actif');
    }
}
