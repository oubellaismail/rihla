<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Enum\BookingStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class BookingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Booking::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /** Owned by the application's own flows: the back-office only looks at it. */
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('trip', 'Trajet');
        yield AssociationField::new('passenger', 'Passager');
        yield AssociationField::new('fromStop', 'Monte à');
        yield AssociationField::new('toStop', 'Descend à');
        yield IntegerField::new('seats', 'Places');
        // ArrayField, not TextField (which rejects arrays), shown as a plain string.
        yield ArrayField::new('seatCodes', 'Sièges')
            ->hideOnForm()
            ->setTemplatePath('admin/field/formatted.html.twig')
            ->formatValue(static fn ($value, Booking $booking) => implode(', ', $booking->getSeatCodes()) ?: '—');
        yield IntegerField::new('cabinBags', 'Bagages cabine');
        yield IntegerField::new('mediumBags', 'Bagages moyens');
        yield IntegerField::new('largeBags', 'Bagages grands');
        yield IntegerField::new('totalPrice', 'Prix total (MAD)');
        yield ChoiceField::new('status', 'Statut')->formatValue(static fn (?BookingStatus $s) => $s?->label());
        yield DateTimeField::new('createdAt', 'Réservée le')->hideOnForm();
    }
}
