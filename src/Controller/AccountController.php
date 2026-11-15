<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Vehicle;
use App\Form\AccountType;
use App\Form\VehicleType;
use App\Repository\TripRepository;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    #[Route('/account', name: 'app_account', methods: ['GET', 'POST'])]
    public function account(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(AccountType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Vos informations sont enregistrées.');

            return $this->redirectToRoute('app_account');
        }

        return $this->render('account/index.html.twig', ['form' => $form, 'user' => $user]);
    }

    #[Route('/account/vehicles', name: 'app_vehicles', methods: ['GET', 'POST'])]
    public function vehicles(Request $request, EntityManagerInterface $em, VehicleRepository $vehicles): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $vehicle = new Vehicle();
        $form = $this->createForm(VehicleType::class, $vehicle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $vehicle->setOwner($user);
            $em->persist($vehicle);
            $em->flush();
            $this->addFlash('success', 'Votre véhicule est ajouté.');

            return $this->redirectToRoute('app_vehicles');
        }

        return $this->renderVehicles($user, $vehicles, $form, null, $form->isSubmitted() ? 422 : 200);
    }

    #[Route('/account/vehicles/{id<\d+>}/edit', name: 'app_vehicle_edit', methods: ['GET', 'POST'])]
    public function editVehicle(Vehicle $vehicle, Request $request, EntityManagerInterface $em, VehicleRepository $vehicles, TripRepository $trips): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($vehicle->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }
        if (null !== $vehicle->getArchivedAt()) {
            throw $this->createNotFoundException();
        }

        $oldTrunkLitres = $vehicle->getTrunkLitres();
        $form = $this->createForm(VehicleType::class, $vehicle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A trip without its own trunk volume follows the vehicle's: freeze the old one so editing never touches trips.
            foreach ($trips->findBy(['vehicle' => $vehicle, 'trunkLitres' => null]) as $trip) {
                $trip->setTrunkLitres($oldTrunkLitres);
            }
            $em->flush();
            $this->addFlash('success', 'Votre véhicule est modifié.');

            return $this->redirectToRoute('app_vehicles');
        }

        if ($form->isSubmitted()) {
            // The rejected values stay in the form only, not on the car shown in the list.
            $em->refresh($vehicle);
        }

        return $this->renderVehicles($user, $vehicles, $form, $vehicle, $form->isSubmitted() ? 422 : 200);
    }

    #[Route('/account/vehicles/{id<\d+>}/delete', name: 'app_vehicle_delete', methods: ['POST'])]
    public function deleteVehicle(Vehicle $vehicle, Request $request, EntityManagerInterface $em, VehicleRepository $vehicles, ClockInterface $clock): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($vehicle->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('delete-vehicle-'.$vehicle->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Le formulaire a expiré, veuillez réessayer.');

            return $this->redirectToRoute('app_vehicles');
        }

        $now = $clock->now();
        $usage = $vehicles->usageOf($user, $now)[$vehicle->getId()] ?? null;
        if (null !== $usage && null !== $usage['nextDeparture']) {
            $this->addFlash('error', 'Ce véhicule est prévu pour un trajet à venir. Annulez ou terminez ce trajet avant de le retirer.');

            return $this->redirectToRoute('app_vehicles');
        }

        if (null !== $usage) {
            // Past or cancelled trips still show this car: keep the row, hide it.
            $vehicle->setArchivedAt($now);
            $em->flush();
            $this->addFlash('success', 'Le véhicule est retiré.');
        } else {
            $em->remove($vehicle);
            $em->flush();
            $this->addFlash('success', 'Le véhicule est supprimé.');
        }

        return $this->redirectToRoute('app_vehicles');
    }

    private function renderVehicles(User $user, VehicleRepository $vehicles, FormInterface $form, ?Vehicle $editing, int $status): Response
    {
        return $this->render('account/vehicles.html.twig', [
            'form' => $form,
            'editing' => $editing,
            'vehicles' => $vehicles->activeOf($user),
            'usage' => $vehicles->usageOf($user, $this->clock->now()),
        ], new Response(status: $status));
    }
}
