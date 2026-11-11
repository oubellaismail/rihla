<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Exception\TripNotPublishableException;
use App\Form\TripPublishType;
use App\Repository\CityRepository;
use App\Repository\VehicleRepository;
use App\Service\TripPublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TripPublishController extends AbstractController
{
    #[Route('/trips/new', name: 'app_trip_new', methods: ['GET', 'POST'])]
    public function new(Request $request, TripPublisher $publisher, VehicleRepository $vehicles, CityRepository $cities): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (0 === $vehicles->count(['owner' => $user])) {
            $this->addFlash('info', 'Ajoutez d\'abord votre véhicule pour publier un trajet.');

            return $this->redirectToRoute('app_vehicles');
        }

        // Departure and arrival to start with; the driver adds intermediate stops.
        $trip = (new Trip())->addStop(new TripStop())->addStop(new TripStop());
        $form = $this->createForm(TripPublishType::class, $trip, ['owner' => $user]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $publisher->publish($trip, $user);
                $this->addFlash('success', 'Votre trajet est publié.');

                return $this->redirectToRoute('app_driver_trip_show', ['id' => $trip->getId()]);
            } catch (TripNotPublishableException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('publish/new.html.twig', ['form' => $form, 'cityCoordinates' => $this->cityCoordinates($cities)], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /** @return array<int, array{float, float}> city id => [latitude, longitude], cities without coordinates left out */
    private function cityCoordinates(CityRepository $cities): array
    {
        $coordinates = [];
        foreach ($cities->findAll() as $city) {
            if ($city->isGeocoded()) {
                $coordinates[$city->getId()] = [$city->getLatitude(), $city->getLongitude()];
            }
        }

        return $coordinates;
    }
}
