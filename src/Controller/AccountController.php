<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Vehicle;
use App\Form\AccountType;
use App\Form\VehicleType;
use App\Repository\VehicleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
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

        return $this->render('account/vehicles.html.twig', [
            'form' => $form,
            'vehicles' => $vehicles->findBy(['owner' => $user], ['id' => 'ASC']),
            'usedIds' => $vehicles->usedIdsOf($user),
        ]);
    }

    #[Route('/account/vehicles/{id<\d+>}/delete', name: 'app_vehicle_delete', methods: ['POST'])]
    public function deleteVehicle(Vehicle $vehicle, Request $request, EntityManagerInterface $em, VehicleRepository $vehicles): Response
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
        if (\in_array($vehicle->getId(), $vehicles->usedIdsOf($user), true)) {
            $this->addFlash('error', 'Ce véhicule est utilisé par un trajet, vous ne pouvez pas le supprimer.');

            return $this->redirectToRoute('app_vehicles');
        }

        $em->remove($vehicle);
        $em->flush();
        $this->addFlash('success', 'Le véhicule est supprimé.');

        return $this->redirectToRoute('app_vehicles');
    }
}
