<?php

namespace App\Controller\Admin;

use App\Entity\Trip;
use App\Enum\TripStatus;
use App\Exception\CancellationNotAllowedException;
use App\Service\CancellationService;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;

class TripCrudController extends AbstractCrudController
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Trip::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Trajet')
            ->setEntityLabelInPlural('Trajets')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    /** Read-only, except for the dedicated cancellation (a POST form with a CSRF token, see the template). */
    public function configureActions(Actions $actions): Actions
    {
        $cancel = Action::new('cancelTrip', 'Annuler le trajet', 'fa fa-ban')
            ->linkToCrudAction('cancelTrip')
            ->setTemplatePath('admin/action/cancel_trip.html.twig')
            ->displayIf(fn (Trip $trip): bool => TripStatus::Published === $trip->getStatus()
                && $this->clock->now() < $trip->getFirstStop()?->getDepartureAt());

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_DETAIL, $cancel);
    }

    /** Same cancellation as the driver's: bookings are cancelled and fully refunded. */
    #[AdminRoute('/{id}/cancel', name: 'cancel_trip', options: ['methods' => ['POST']])]
    public function cancelTrip(Trip $trip, Request $request, CancellationService $service): Response
    {
        $back = $this->container->get(AdminUrlGeneratorInterface::class)
            ->setController(self::class)->setAction(Action::DETAIL)->setEntityId($trip->getId())->generateUrl();

        if (!$this->isCsrfTokenValid('admin_cancel_trip_'.$trip->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Le formulaire a expiré. Veuillez réessayer.');

            return $this->redirect($back);
        }

        try {
            $count = $service->cancelTripByAdmin($trip);
            $this->addFlash('success', sprintf(
                'Trajet annulé. %d %s.',
                $count,
                $count > 1 ? 'passagers remboursés intégralement' : 'passager remboursé intégralement',
            ));
        } catch (CancellationNotAllowedException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($back);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('driver', 'Conducteur');
        yield AssociationField::new('vehicle', 'Véhicule');
        yield IntegerField::new('seatsOffered', 'Places proposées');
        yield IntegerField::new('trunkLitres', 'Coffre (L)');
        yield ChoiceField::new('status', 'Statut')->formatValue(static fn (?TripStatus $s) => $s?->label());
        yield AssociationField::new('stops', 'Arrêts')->hideOnForm();
        yield AssociationField::new('bookings', 'Réservations')->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }
}
