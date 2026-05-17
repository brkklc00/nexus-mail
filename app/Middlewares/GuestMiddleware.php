<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class GuestMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Session başlat
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Kullanıcı zaten login olmuşsa dashboard'a yönlendir
        if (isset($_SESSION['user_id'])) {
            $response = new Response();
            return $response
                ->withHeader('Location', '/')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}

