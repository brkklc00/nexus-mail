<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\SupportTicket;
use App\Domain\Entities\SupportMessage;
use App\Domain\Entities\User;
use App\Domain\Enum\TicketStatus;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

class SupportTicketController
{
    private EntityManager $em;
    private Environment $twig;

    public function __construct(EntityManager $em, Environment $twig)
    {
        $this->em = $em;
        $this->twig = $twig;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $userId);

        $tickets = $this->em->getRepository(SupportTicket::class)
            ->findBy(['user' => $user], ['lastActivityAt' => 'DESC']);

        $html = $this->twig->render('tickets/index.twig', [
            'tickets' => $tickets,
        ]);

        // Flash mesajları temizle
        unset($_SESSION['flash_success']);
        unset($_SESSION['flash_error']);

        $response->getBody()->write($html);
        return $response;
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = $this->twig->render('tickets/create.twig', []);
        $response->getBody()->write($html);
        return $response;
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        $user = $this->em->find(User::class, $userId);

        $ticket = new SupportTicket();
        $ticket->setUser($user);
        $ticket->setSubject($data['subject'] ?? '');
        $ticket->setStatus(TicketStatus::OPEN);

        $message = new SupportMessage();
        $message->setTicket($ticket);
        $message->setUser($user);
        $message->setBody($data['message'] ?? '');

        $ticket->addMessage($message);

        $this->em->persist($ticket);
        $this->em->persist($message);
        $this->em->flush();

        $_SESSION['flash_success'] = 'Destek talebiniz başarıyla oluşturuldu.';

        return $response
            ->withHeader('Location', '/tickets')
            ->withStatus(302);
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $ticket = $this->em->find(SupportTicket::class, $args['id']);

        if (!$ticket || $ticket->getUserId() !== $userId) {
            $response->getBody()->write('<div class="alert alert-danger">Talep bulunamadı.</div>');
            return $response->withStatus(404);
        }

        $html = $this->twig->render('tickets/_detail.twig', [
            'ticket' => $ticket,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $ticket = $this->em->find(SupportTicket::class, $args['id']);

        if (!$ticket || $ticket->getUserId() !== $userId) {
            return $response->withStatus(404);
        }

        $html = $this->twig->render('tickets/show.twig', [
            'ticket' => $ticket,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function reply(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $ticket = $this->em->find(SupportTicket::class, $args['id']);

        if (!$ticket || $ticket->getUserId() !== $userId) {
            return $response->withStatus(404);
        }

        $data = $request->getParsedBody();
        $user = $this->em->find(User::class, $userId);

        $message = new SupportMessage();
        $message->setTicket($ticket);
        $message->setUser($user);
        $message->setBody($data['message'] ?? '');

        $ticket->updateActivity();
        $ticket->setStatus(TicketStatus::PENDING);

        $this->em->persist($message);
        $this->em->persist($ticket);
        $this->em->flush();

        return $response
            ->withHeader('Location', '/tickets/' . $ticket->getId())
            ->withStatus(302);
    }

    public function close(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $ticket = $this->em->find(SupportTicket::class, $args['id']);

        if (!$ticket || $ticket->getUserId() !== $userId) {
            return $response->withStatus(404);
        }

        $ticket->setStatus(TicketStatus::CLOSED);
        $this->em->flush();

        return $response
            ->withHeader('Location', '/tickets')
            ->withStatus(302);
    }

    /**
     * Admin: Tüm destek taleplerini listele
     */
    public function adminIndex(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $status = $params['status'] ?? null;

        // Query builder
        $qb = $this->em->createQueryBuilder();
        $qb->select('t', 'u')
           ->from(SupportTicket::class, 't')
           ->leftJoin('t.user', 'u')
           ->setFirstResult($offset)
           ->setMaxResults($perPage)
           ->orderBy('t.lastActivityAt', 'DESC');

        // Durum filtresi
        if ($status && in_array($status, ['open', 'pending', 'closed'])) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', TicketStatus::from($status));
        }

        $tickets = $qb->getQuery()->getResult();

        // Total count
        $totalQb = $this->em->createQueryBuilder();
        $totalQb->select('COUNT(t.id)')
                ->from(SupportTicket::class, 't');
        
        if ($status && in_array($status, ['open', 'pending', 'closed'])) {
            $totalQb->andWhere('t.status = :status')
                    ->setParameter('status', TicketStatus::from($status));
        }

        $total = $totalQb->getQuery()->getSingleScalarResult();
        $totalPages = ceil($total / $perPage);

        // Statistics
        $stats = [
            'open' => $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(SupportTicket::class, 't')
                ->where('t.status = :status')
                ->setParameter('status', TicketStatus::OPEN)
                ->getQuery()->getSingleScalarResult(),
            'pending' => $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(SupportTicket::class, 't')
                ->where('t.status = :status')
                ->setParameter('status', TicketStatus::PENDING)
                ->getQuery()->getSingleScalarResult(),
            'closed' => $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(SupportTicket::class, 't')
                ->where('t.status = :status')
                ->setParameter('status', TicketStatus::CLOSED)
                ->getQuery()->getSingleScalarResult(),
        ];

        $html = $this->twig->render('admin/support-tickets/index.twig', [
            'tickets' => $tickets,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'stats' => $stats,
            'currentStatus' => $status,
            'success' => $_SESSION['flash_success'] ?? null,
        ]);

        unset($_SESSION['flash_success']);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Admin: Talep detayını modal için döndür (AJAX)
     */
    public function adminDetail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ticket = $this->em->find(SupportTicket::class, $args['id']);

        if (!$ticket) {
            $response->getBody()->write('<div class="alert alert-danger m-4">Talep bulunamadı.</div>');
            return $response->withStatus(404);
        }

        // Eager load messages with users
        $qb = $this->em->createQueryBuilder();
        $qb->select('t', 'm', 'u', 'tu')
           ->from(SupportTicket::class, 't')
           ->leftJoin('t.messages', 'm')
           ->leftJoin('m.user', 'u')
           ->leftJoin('t.user', 'tu')
           ->where('t.id = :id')
           ->setParameter('id', $ticket->getId())
           ->orderBy('m.createdAt', 'ASC');

        $ticket = $qb->getQuery()->getOneOrNullResult();

        $html = $this->twig->render('admin/support-tickets/_detail.twig', [
            'ticket' => $ticket,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Admin: Talebe cevap ver
     */
    public function adminReply(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $ticket = $this->em->find(SupportTicket::class, $args['id']);

        if (!$ticket) {
            $_SESSION['flash_error'] = 'Talep bulunamadı.';
            return $response
                ->withHeader('Location', '/admin/support-tickets')
                ->withStatus(302);
        }

        $data = $request->getParsedBody();
        $user = $this->em->find(User::class, $userId);

        $message = new SupportMessage();
        $message->setTicket($ticket);
        $message->setUser($user);
        $message->setBody($data['message'] ?? '');

        $ticket->updateActivity();
        $ticket->setStatus(TicketStatus::PENDING);

        $this->em->persist($message);
        $this->em->persist($ticket);
        $this->em->flush();

        $_SESSION['flash_success'] = 'Cevabınız gönderildi.';

        return $response
            ->withHeader('Location', '/admin/support-tickets')
            ->withStatus(302);
    }

    /**
     * Admin: Talebi kapat
     */
    public function adminClose(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ticket = $this->em->find(SupportTicket::class, $args['id']);

        if (!$ticket) {
            $_SESSION['flash_error'] = 'Talep bulunamadı.';
            return $response
                ->withHeader('Location', '/admin/support-tickets')
                ->withStatus(302);
        }

        $ticket->setStatus(TicketStatus::CLOSED);
        $this->em->flush();

        $_SESSION['flash_success'] = 'Talep kapatıldı.';

        return $response
            ->withHeader('Location', '/admin/support-tickets')
            ->withStatus(302);
    }
}

