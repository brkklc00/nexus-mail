<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Application\Services\EmailSmtpSelector;
use App\Application\Services\EmailSmtpService;
use App\Domain\Entities\EmailTemplate;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailTemplateController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    /**
     * Yeni şablon oluşturma formu (admin)
     */
    public function create(Request $request, Response $response): Response
    {
        $html = $this->twig->render('admin/email-templates/create.twig');
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Yeni şablon kaydet (admin - global ve onaylı olarak oluşturulur)
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $user = $this->em->find(User::class, $_SESSION['user']['id']);

        try {
            $template = new EmailTemplate();
            $template->setUser($user);
            $template->setName(trim($data['name'] ?? ''));
            $template->setSubject(trim($data['subject'] ?? ''));
            $template->setBody($data['body'] ?? '');
            $template->setCategory(!empty($data['category']) ? trim($data['category']) : null);
            $template->setTags(!empty($data['tags']) ? trim($data['tags']) : null);
            $template->setIsGlobal(true); // Admin oluşturduğu şablonlar global (tüm kullanıcılar kullanabilir)
            $template->setIsApproved(true); // Admin oluşturduğu şablonlar otomatik onaylı

            $this->em->persist($template);
            $this->em->flush();

            $_SESSION['success'] = 'Şablon oluşturuldu. Tüm kullanıcılar bu şablonu kullanabilir.';
            $_SESSION['flash_icon'] = 'check-circle';
            return $response->withHeader('Location', '/admin/email-templates')->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
            $_SESSION['flash_icon'] = 'alert-circle';
            return $response->withHeader('Location', '/admin/email-templates/create')->withStatus(302);
        }
    }

    /**
     * Tüm email şablonlarını listele (onay bekleyenler öne)
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $status = $params['status'] ?? '';
        $search = trim($params['search'] ?? '');
        $userId = isset($params['user_id']) ? (int) $params['user_id'] : 0;
        $dateRange = trim((string) ($params['date_range'] ?? 'all'));
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }
        if ($page < 1) {
            $page = 1;
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('t', 'u')
            ->from(EmailTemplate::class, 't')
            ->leftJoin('t.user', 'u')
            ->where('t.isGlobal = false')
            ->orderBy('t.isApproved', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC');

        if ($status === 'pending') {
            $qb->andWhere('t.isApproved = false');
        } elseif ($status === 'approved') {
            $qb->andWhere('t.isApproved = true');
        }

        if ($search) {
            if (ctype_digit($search)) {
                $qb->andWhere('(t.name LIKE :search OR t.subject LIKE :search OR u.name LIKE :search OR u.email LIKE :search OR t.id = :searchId)')
                    ->setParameter('searchId', (int) $search);
            } else {
                $qb->andWhere('(t.name LIKE :search OR t.subject LIKE :search OR u.name LIKE :search OR u.email LIKE :search)');
            }
            $qb->setParameter('search', '%' . $search . '%');
        }
        if ($userId > 0) {
            $qb->andWhere('u.id = :userId')->setParameter('userId', $userId);
        }
        $this->applyDateRangeFilterDql($qb, $dateRange, 't.createdAt');

        $countQb = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(EmailTemplate::class, 't')
            ->leftJoin('t.user', 'u2')
            ->where('t.isGlobal = false');
        if ($status === 'pending') {
            $countQb->andWhere('t.isApproved = false');
        } elseif ($status === 'approved') {
            $countQb->andWhere('t.isApproved = true');
        }
        if ($search) {
            if (ctype_digit($search)) {
                $countQb->andWhere('(t.name LIKE :search2 OR t.subject LIKE :search2 OR u2.name LIKE :search2 OR u2.email LIKE :search2 OR t.id = :searchId2)')
                    ->setParameter('searchId2', (int) $search);
            } else {
                $countQb->andWhere('(t.name LIKE :search2 OR t.subject LIKE :search2 OR u2.name LIKE :search2 OR u2.email LIKE :search2)');
            }
            $countQb->setParameter('search2', '%' . $search . '%');
        }
        if ($userId > 0) {
            $countQb->andWhere('u2.id = :userId2')->setParameter('userId2', $userId);
        }
        $this->applyDateRangeFilterDql($countQb, $dateRange, 't.createdAt');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $templates = $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $users = $this->em->createQueryBuilder()
            ->select('u.id', 'u.name', 'u.email')
            ->from(User::class, 'u')
            ->where('u.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        $stats = [
            'total' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(EmailTemplate::class, 't')
                ->where('t.isGlobal = false')
                ->getQuery()
                ->getSingleScalarResult(),
            'pending' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(EmailTemplate::class, 't')
                ->where('t.isGlobal = false')
                ->andWhere('t.isApproved = false')
                ->getQuery()
                ->getSingleScalarResult(),
            'approved' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(EmailTemplate::class, 't')
                ->where('t.isGlobal = false')
                ->andWhere('t.isApproved = true')
                ->getQuery()
                ->getSingleScalarResult(),
        ];

        $html = $this->twig->render('admin/email-templates/index.twig', [
            'templates' => $templates,
            'users' => $users,
            'stats' => $stats,
            'search' => $search,
            'status' => $status,
            'selected_user' => $userId > 0 ? (string) $userId : '',
            'date_range' => $dateRange ?: 'all',
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total' => $total,
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null,
            'flash_icon' => $_SESSION['flash_icon'] ?? null,
        ]);

        unset($_SESSION['success'], $_SESSION['error'], $_SESSION['flash_icon']);
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Şablon verisi (edit modal için)
     */
    public function editData(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $template = $this->em->find(EmailTemplate::class, $id);

        if (!$template) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Şablon bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'id' => $template->getId(),
            'name' => $template->getName(),
            'subject' => $template->getSubject(),
            'body' => $template->getBody(),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Şablon önizleme (admin)
     */
    public function preview(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $template = $this->em->find(EmailTemplate::class, $id);

        if (!$template) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Şablon bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'id' => $template->getId(),
            'name' => $template->getName(),
            'subject' => $template->getSubject(),
            'body' => $template->getBody(),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Şablonu onayla
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        if (!$this->canModerateTemplates()) {
            return $this->json($response, ['success' => false, 'message' => 'Bu işlemi gerçekleştirmek için yetkiniz yok.'], 403);
        }

        $id = (int) $args['id'];
        error_log('Template approve clicked ID=' . $id);
        $template = $this->em->find(EmailTemplate::class, $id);

        if (!$template) {
            return $this->json($response, ['success' => false, 'message' => 'Şablon bulunamadı.'], 404);
        }

        if ($template->isApproved()) {
            return $this->json($response, [
                'success' => true,
                'already_approved' => true,
                'message' => 'Şablon zaten onaylı.',
            ]);
        }

        $template->setIsApproved(true);
        $this->em->flush();

        return $this->json($response, ['success' => true, 'message' => 'Şablon onaylandı.']);
    }

    /**
     * Şablonu reddet
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        if (!$this->canModerateTemplates()) {
            return $this->json($response, ['success' => false, 'message' => 'Bu işlemi gerçekleştirmek için yetkiniz yok.'], 403);
        }

        $id = (int) $args['id'];
        $template = $this->em->find(EmailTemplate::class, $id);

        if (!$template) {
            return $this->json($response, ['success' => false, 'message' => 'Şablon bulunamadı.'], 404);
        }

        if ($template->isApproved()) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Onaylı şablon reddedilemez.',
            ], 422);
        }

        $this->em->remove($template);
        $this->em->flush();

        return $this->json($response, ['success' => true, 'message' => 'Şablon reddedildi ve silindi.']);
    }

    /**
     * Şablonu güncelle (admin düzenleme)
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = (string) $request->getBody();
        $data = json_decode($body, true) ?: $request->getParsedBody() ?: [];

        $template = $this->em->find(EmailTemplate::class, $id);

        if (!$template) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Şablon bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        if (!empty($data['name'])) {
            $template->setName(trim($data['name']));
        }
        if (!empty($data['subject'])) {
            $template->setSubject(trim($data['subject']));
        }
        if (isset($data['body'])) {
            $template->setBody($data['body']);
        }

        $this->em->flush();

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Şablon güncellendi',
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function apiList(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $search = trim((string) ($params['search'] ?? ''));
            $status = trim((string) ($params['status'] ?? ''));
            $userId = (int) ($params['user_id'] ?? 0);
            $dateRange = trim((string) ($params['date_range'] ?? 'all'));
            $page = max(1, (int) ($params['page'] ?? 1));
            $perPage = (int) ($params['per_page'] ?? 25);
            if ($perPage < 1) {
                $perPage = 25;
            }
            if ($perPage > 100) {
                $perPage = 100;
            }

            $conn = $this->em->getConnection();
            $queryParts = ['t.is_global = 0'];
            $bind = [];
            $types = [];

            if ($search !== '') {
                $queryParts[] = '(CAST(t.id AS CHAR) LIKE :search OR t.name LIKE :search OR t.subject LIKE :search OR u.name LIKE :search OR u.email LIKE :search)';
                $bind['search'] = '%' . $search . '%';
            }
            if ($status === 'approved') {
                $queryParts[] = 't.is_approved = 1';
            } elseif ($status === 'pending') {
                $queryParts[] = 't.is_approved = 0';
            }
            if ($userId > 0) {
                $queryParts[] = 'u.id = :user_id';
                $bind['user_id'] = $userId;
                $types['user_id'] = \PDO::PARAM_INT;
            }

            [$dateClause, $dateBind] = $this->buildDateRangeSqlClause('t.created_at', $dateRange);
            if ($dateClause !== '') {
                $queryParts[] = $dateClause;
                foreach ($dateBind as $k => $v) {
                    $bind[$k] = $v;
                }
            }

            $whereSql = implode(' AND ', $queryParts);
            $total = (int) $conn->executeQuery(
                "SELECT COUNT(t.id)
                 FROM email_templates t
                 LEFT JOIN users u ON u.id = t.user_id
                 WHERE {$whereSql}",
                $bind,
                $types
            )->fetchOne();

            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;

            $rows = $conn->executeQuery(
                "SELECT
                    t.id,
                    t.name,
                    t.subject,
                    t.is_approved,
                    t.created_at,
                    t.updated_at,
                    u.id AS user_id,
                    u.name AS user_name,
                    u.email AS user_email
                 FROM email_templates t
                 LEFT JOIN users u ON u.id = t.user_id
                 WHERE {$whereSql}
                 ORDER BY t.is_approved ASC, t.created_at DESC
                 LIMIT :limit OFFSET :offset",
                array_merge($bind, ['limit' => $perPage, 'offset' => $offset]),
                array_merge($types, ['limit' => \PDO::PARAM_INT, 'offset' => \PDO::PARAM_INT])
            )->fetchAllAssociative();

            $items = array_map(function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'subject' => (string) ($row['subject'] ?? ''),
                    'status' => ((int) ($row['is_approved'] ?? 0) === 1) ? 'approved' : 'pending',
                    'isApproved' => ((int) ($row['is_approved'] ?? 0) === 1),
                    'user' => [
                        'id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                        'name' => (string) ($row['user_name'] ?? '-'),
                        'email' => (string) ($row['user_email'] ?? '-'),
                    ],
                    'createdAt' => isset($row['created_at']) ? date('d.m.Y', strtotime((string) $row['created_at'])) : null,
                    'updatedAt' => isset($row['updated_at']) ? date('d.m.Y', strtotime((string) $row['updated_at'])) : null,
                ];
            }, $rows);

            return $this->json($response, [
                'success' => true,
                'items' => $items,
                'stats' => $this->fetchTemplateStats(),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('Admin EmailTemplate apiList error: ' . $e->getMessage());
            return $this->json($response, ['success' => false, 'message' => 'Liste alınırken hata oluştu.'], 500);
        }
    }

    public function apiUsers(Request $request, Response $response): Response
    {
        try {
            $rows = $this->em->getConnection()->fetchAllAssociative(
                'SELECT
                    u.id,
                    u.name,
                    u.email,
                    COUNT(t.id) AS template_count
                 FROM users u
                 INNER JOIN email_templates t ON t.user_id = u.id
                 WHERE t.is_global = 0
                 GROUP BY u.id, u.name, u.email
                 ORDER BY u.name ASC'
            );

            $items = array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'template_count' => (int) ($row['template_count'] ?? 0),
                ];
            }, $rows);

            return $this->json($response, ['success' => true, 'items' => $items]);
        } catch (\Throwable $e) {
            error_log('Admin EmailTemplate apiUsers error: ' . $e->getMessage());
            return $this->json($response, ['success' => false, 'message' => 'Kullanıcı listesi alınamadı.'], 500);
        }
    }

    public function apiShow(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $template = $this->em->find(EmailTemplate::class, $id);
        if (!$template) {
            return $this->json($response, ['success' => false, 'message' => 'Şablon bulunamadı.'], 404);
        }

        return $this->json($response, [
            'success' => true,
            'item' => [
                'id' => $template->getId(),
                'name' => $template->getName(),
                'subject' => $template->getSubject(),
                'body' => $template->getBody(),
                'status' => $template->isApproved() ? 'approved' : 'pending',
                'user' => [
                    'id' => $template->getUser()?->getId(),
                    'name' => $template->getUser()?->getName(),
                    'email' => $template->getUser()?->getEmail(),
                ],
                'createdAt' => $template->getCreatedAt()->format('d.m.Y'),
                'updatedAt' => $template->getUpdatedAt()->format('d.m.Y'),
            ],
        ]);
    }

    public function sendTest(Request $request, Response $response, array $args): Response
    {
        $templateId = (int) ($args['id'] ?? 0);
        $body = json_decode((string) $request->getBody(), true) ?: [];
        $to = trim((string) ($body['to'] ?? ''));
        $variablesRaw = $body['variables'] ?? [];
        $adminUserId = (int) ($_SESSION['user']['id'] ?? 0);

        if ($adminUserId <= 0) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz işlem.'], 401);
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['success' => false, 'message' => 'Geçerli bir email adresi girin.'], 422);
        }

        $variables = is_array($variablesRaw) ? $variablesRaw : [];
        $template = $this->em->find(EmailTemplate::class, $templateId);
        if (!$template) {
            return $this->json($response, ['success' => false, 'message' => 'Şablon bulunamadı.'], 404);
        }

        $this->ensureTestLogTableExists();

        if ($this->isTestRateLimited($adminUserId)) {
            $this->logTestMail($adminUserId, $templateId, $to, 'rate_limited', 'Dakikada en fazla 5 test mail gönderebilirsiniz.');
            return $this->json($response, ['success' => false, 'message' => 'Dakikada en fazla 5 test mail gönderebilirsiniz.'], 429);
        }

        try {
            $renderedSubject = $this->renderTextTemplate($template->getSubject(), $variables);
            $renderedBody = $template->render($variables);
            $selector = new EmailSmtpSelector($this->em);
            $smtp = $selector->selectBestSmtp();

            if (!$smtp) {
                $this->logTestMail($adminUserId, $templateId, $to, 'failed', 'Aktif SMTP hesabı bulunamadı.');
                return $this->json($response, ['success' => false, 'message' => 'Aktif SMTP hesabı bulunamadı.'], 500);
            }

            $smtpService = new EmailSmtpService($this->em);
            $sendResult = $smtpService->sendEmail($smtp, $to, $renderedSubject, $renderedBody);
            if (!($sendResult['success'] ?? false)) {
                $message = (string) ($sendResult['message'] ?? 'Test mail gönderilemedi.');
                $this->logTestMail($adminUserId, $templateId, $to, 'failed', $message);
                return $this->json($response, ['success' => false, 'message' => $message], 500);
            }

            $this->logTestMail($adminUserId, $templateId, $to, 'success', null);
            return $this->json($response, ['success' => true, 'message' => 'Test mail gönderildi.']);
        } catch (\Throwable $e) {
            $this->logTestMail($adminUserId, $templateId, $to, 'failed', $e->getMessage());
            error_log('Admin EmailTemplate sendTest error: ' . $e->getMessage());
            return $this->json($response, ['success' => false, 'message' => 'Test mail gönderilemedi: ' . $e->getMessage()], 500);
        }
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function canModerateTemplates(): bool
    {
        $isAdmin = (bool) ($_SESSION['is_admin'] ?? false);
        if ($isAdmin) {
            return true;
        }

        $roleRaw = (string) ($_SESSION['user']['role']['name'] ?? '');
        $roleName = function_exists('mb_strtolower')
            ? mb_strtolower($roleRaw, 'UTF-8')
            : strtolower($roleRaw);
        return in_array($roleName, ['admin', 'manager', 'yonetici', 'yönetici'], true);
    }

    private function fetchTemplateStats(): array
    {
        return [
            'total' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(EmailTemplate::class, 't')
                ->where('t.isGlobal = false')
                ->getQuery()
                ->getSingleScalarResult(),
            'pending' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(EmailTemplate::class, 't')
                ->where('t.isGlobal = false')
                ->andWhere('t.isApproved = false')
                ->getQuery()
                ->getSingleScalarResult(),
            'approved' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(t.id)')
                ->from(EmailTemplate::class, 't')
                ->where('t.isGlobal = false')
                ->andWhere('t.isApproved = true')
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    private function applyDateRangeFilterDql(\Doctrine\ORM\QueryBuilder $qb, string $dateRange, string $field): void
    {
        $today = new \DateTimeImmutable('today');
        if ($dateRange === 'today') {
            $qb->andWhere($field . ' >= :dateFromToday')->setParameter('dateFromToday', $today);
            return;
        }
        if ($dateRange === '7d') {
            $qb->andWhere($field . ' >= :dateFrom7d')->setParameter('dateFrom7d', $today->modify('-7 days'));
            return;
        }
        if ($dateRange === '30d') {
            $qb->andWhere($field . ' >= :dateFrom30d')->setParameter('dateFrom30d', $today->modify('-30 days'));
        }
    }

    private function buildDateRangeSqlClause(string $field, string $dateRange): array
    {
        if ($dateRange !== 'today' && $dateRange !== '7d' && $dateRange !== '30d') {
            return ['', []];
        }

        $today = new \DateTimeImmutable('today');
        if ($dateRange === 'today') {
            return [$field . ' >= :date_from', ['date_from' => $today->format('Y-m-d H:i:s')]];
        }
        if ($dateRange === '7d') {
            return [$field . ' >= :date_from', ['date_from' => $today->modify('-7 days')->format('Y-m-d H:i:s')]];
        }
        return [$field . ' >= :date_from', ['date_from' => $today->modify('-30 days')->format('Y-m-d H:i:s')]];
    }

    private function isTestRateLimited(int $adminUserId): bool
    {
        try {
            $conn = $this->em->getConnection();
            $count = (int) $conn->fetchOne(
                'SELECT COUNT(id) FROM email_template_test_logs WHERE admin_user_id = :admin_user_id AND created_at >= :window_start',
                [
                    'admin_user_id' => $adminUserId,
                    'window_start' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
                ]
            );
            return $count >= 5;
        } catch (\Throwable $e) {
            error_log('email_template_test_logs rate limit check failed: ' . $e->getMessage());
            return false;
        }
    }

    private function logTestMail(int $adminUserId, int $templateId, string $toEmail, string $status, ?string $errorMessage): void
    {
        try {
            $this->em->getConnection()->insert('email_template_test_logs', [
                'admin_user_id' => $adminUserId,
                'template_id' => $templateId,
                'to_email' => $toEmail,
                'status' => substr($status, 0, 20),
                'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 1000) : null,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('email_template_test_logs insert failed: ' . $e->getMessage());
        }
    }

    private function ensureTestLogTableExists(): void
    {
        try {
            $conn = $this->em->getConnection();
            $dbName = (string) $conn->getDatabase();
            $exists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbName, 'email_template_test_logs']
            );
            if ($exists > 0) {
                return;
            }

            $conn->executeStatement('CREATE TABLE email_template_test_logs (
                id INT AUTO_INCREMENT NOT NULL,
                admin_user_id INT NOT NULL,
                template_id INT NOT NULL,
                to_email VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_email_tpl_test_admin_created (admin_user_id, created_at),
                INDEX idx_email_tpl_test_template_created (template_id, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
        } catch (\Throwable $e) {
            error_log('ensure email_template_test_logs failed: ' . $e->getMessage());
        }
    }

    private function renderTextTemplate(string $text, array $variables): string
    {
        if ($variables === []) {
            return $text;
        }
        $rendered = $text;
        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{' . $key . '}}', (string) $value, $rendered);
        }
        return $rendered;
    }
}
