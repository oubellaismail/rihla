<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class SecurityController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
            'login_message' => $this->loginMessage($this->getTargetPath($request->getSession(), 'main')),
        ]);
    }

    private function loginMessage(?string $targetPath): ?string
    {
        if (null === $targetPath) {
            return null;
        }
        $path = (string) parse_url($targetPath, PHP_URL_PATH);

        return match (true) {
            1 === preg_match('#^/trips/\d+/(seats|book)#', $path) => 'Connectez-vous pour réserver votre place.',
            '/trips/new' === $path => 'Connectez-vous pour publier un trajet.',
            default => null,
        };
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key of the firewall.');
    }
}
