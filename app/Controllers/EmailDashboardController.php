<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

/**
 * Kullanıcı email modülü giriş sayfası (sipariş, şablon, rehber vb. kısayollar).
 */
class EmailDashboardController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? null;
        $user = $userId ? $this->em->find(User::class, $userId) : null;

        $html = $this->twig->render('email-dashboard/index.twig', [
            'user' => $user,
            'email_credit' => $user ? $user->getEmailCredit() : 0,
        ]);
        $response->getBody()->write($html);

        return $response;
    }
}
