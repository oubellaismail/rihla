<?php

namespace App\Controller\Admin;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Payment;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ClockInterface $clock)
    {
    }

    public function index(): Response
    {
        $count = fn (string $class, array $criteria = []) => $this->em->getRepository($class)->count($criteria);

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'Utilisateurs' => $count(User::class),
                'Villes' => $count(City::class),
                'Trajets publiés' => $count(Trip::class, ['status' => TripStatus::Published]),
                'Réservations confirmées' => $count(Booking::class, ['status' => BookingStatus::Confirmed]),
                'Avis' => $count(Review::class),
                "Départs aujourd'hui" => $this->departuresToday(),
                'Trajets annulés' => $count(Trip::class, ['status' => TripStatus::Cancelled]),
                'Montant encaissé (MAD)' => $this->paymentSum('amount'),
                'Montant remboursé (MAD)' => $this->paymentSum('refundedAmount'),
            ],
        ]);
    }

    /** Published trips whose first stop departs today (by the clock). */
    private function departuresToday(): int
    {
        $start = $this->clock->now()->setTime(0, 0);

        return (int) $this->em->createQuery(
            'SELECT COUNT(t.id) FROM App\Entity\Trip t JOIN t.stops s
             WHERE t.status = :status AND s.departureAt >= :start AND s.departureAt < :end
             AND s.position = (SELECT MIN(s2.position) FROM App\Entity\TripStop s2 WHERE s2.trip = t)'
        )
            ->setParameter('status', TripStatus::Published)
            ->setParameter('start', $start)
            ->setParameter('end', $start->modify('+1 day'))
            ->getSingleScalarResult();
    }

    /** @param 'amount'|'refundedAmount' $field */
    private function paymentSum(string $field): int
    {
        return (int) $this->em->createQuery('SELECT SUM(p.'.$field.') FROM App\Entity\Payment p')->getSingleScalarResult();
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Rihla · back-office');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-gauge');
        yield MenuItem::section('Trajets');
        yield MenuItem::linkTo(TripCrudController::class, 'Trajets', 'fa fa-route');
        yield MenuItem::linkTo(TripStopCrudController::class, 'Arrêts', 'fa fa-location-dot');
        yield MenuItem::linkTo(BookingCrudController::class, 'Réservations', 'fa fa-ticket');
        yield MenuItem::linkTo(PaymentCrudController::class, 'Paiements', 'fa fa-money-bill');
        yield MenuItem::linkTo(ReviewCrudController::class, 'Avis', 'fa fa-star');
        yield MenuItem::section('Référentiel');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-user');
        yield MenuItem::linkTo(VehicleCrudController::class, 'Véhicules', 'fa fa-car');
        yield MenuItem::linkTo(CityCrudController::class, 'Villes', 'fa fa-city');
        yield MenuItem::section();
        yield MenuItem::linkToLogout('Déconnexion', 'fa fa-right-from-bracket');
    }
}
