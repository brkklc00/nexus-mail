<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Application\Services\TelegramNotificationService;
use App\Domain\Entities\EmailDataPool;
use App\Domain\Entities\EmailDataPoolList;
use App\Domain\Entities\EmailOrder;
use App\Domain\Entities\EmailTemplate;
use App\Domain\Entities\User;
use App\Domain\Enum\EmailOrderStatus;
use App\Services\ExternalMailBalanceService;
use App\Support\EnumHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailOrderController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig,
        private ExternalMailBalanceService $externalMailBalanceApi,
        private TelegramNotificationService $telegramNotificationService
    ) {
    }

    /**
     * Tüm email siparişlerini listele
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = trim((string) ($params['search'] ?? ''));
        $status = trim((string) ($params['status'] ?? ''));
        $userId = trim((string) ($params['user_id'] ?? ''));
        $sort = trim((string) ($params['sort'] ?? 'createdAt'));
        $dir = trim((string) ($params['dir'] ?? 'DESC'));
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 50);
        if (!in_array($perPage, [25, 50, 100], true)) {
            $perPage = 50;
        }
        if ($page < 1) {
            $page = 1;
        }

        try {
            $conn = $this->em->getConnection();
            $hasTemplateIdColumn = $this->emailOrdersHasTemplateIdColumn();

            $sortMap = [
                'id' => 'o.id',
                'createdAt' => 'o.created_at',
                'total' => 'o.total',
                'sent' => 'o.sent',
                'failed' => 'o.failed',
                'status' => 'o.status',
            ];
            $sortField = $sortMap[$sort] ?? 'o.created_at';
            $sortDir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

            $countQb = $conn->createQueryBuilder()
                ->select('COUNT(o.id)')
                ->from('email_orders', 'o')
                ->leftJoin('o', 'users', 'u', 'u.id = o.user_id');

            if ($hasTemplateIdColumn) {
                $countQb->leftJoin('o', 'email_templates', 't', 't.id = o.template_id');
            }

            if ($search !== '') {
                $countSearchExpr = '(o.subject LIKE :search OR u.name LIKE :search OR u.email LIKE :search';
                if ($hasTemplateIdColumn) {
                    $countSearchExpr .= ' OR t.name LIKE :search';
                }
                $countSearchExpr .= ')';
                $countQb->andWhere($countSearchExpr)
                    ->setParameter('search', '%' . $search . '%');
            }
            if ($status !== '') {
                $countQb->andWhere('o.status = :status')
                    ->setParameter('status', $status);
            }
            if ($userId !== '' && ctype_digit($userId)) {
                $countQb->andWhere('o.user_id = :user_id')
                    ->setParameter('user_id', (int) $userId);
            }

            $total = (int) $countQb->executeQuery()->fetchOne();
            $totalPages = (int) max(1, ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $listQb = $conn->createQueryBuilder()
                ->select(
                    'o.id',
                    'o.subject',
                    'o.total',
                    'o.sent',
                    'o.failed',
                    'o.status',
                    'o.source_type AS sourceType',
                    'o.created_at AS createdAt',
                    'u.id AS user_id',
                    'u.name AS user_name',
                    'u.email AS user_email'
                )
                ->from('email_orders', 'o')
                ->leftJoin('o', 'users', 'u', 'u.id = o.user_id')
                ->orderBy($sortField, $sortDir)
                ->setFirstResult(($page - 1) * $perPage)
                ->setMaxResults($perPage);

            if ($hasTemplateIdColumn) {
                $listQb
                    ->addSelect('o.template_id AS template_id')
                    ->addSelect('t.name AS template_name')
                    ->leftJoin('o', 'email_templates', 't', 't.id = o.template_id');
            } else {
                $listQb
                    ->addSelect('NULL AS template_id')
                    ->addSelect('NULL AS template_name');
            }

            if ($search !== '') {
                $listSearchExpr = '(o.subject LIKE :search OR u.name LIKE :search OR u.email LIKE :search';
                if ($hasTemplateIdColumn) {
                    $listSearchExpr .= ' OR t.name LIKE :search';
                }
                $listSearchExpr .= ')';
                $listQb->andWhere($listSearchExpr)
                    ->setParameter('search', '%' . $search . '%');
            }
            if ($status !== '') {
                $listQb->andWhere('o.status = :status')
                    ->setParameter('status', $status);
            }
            if ($userId !== '' && ctype_digit($userId)) {
                $listQb->andWhere('o.user_id = :user_id')
                    ->setParameter('user_id', (int) $userId);
            }

            $rawOrders = $listQb->executeQuery()->fetchAllAssociative();
            $subjectsOnPage = array_values(array_unique(array_filter(array_map(
                fn (array $row): string => $this->scalarToString($row['subject'] ?? ''),
                $rawOrders
            ))));
            $templateMap = $this->buildTemplateMapBySubject($subjectsOnPage);
            $orders = array_map(function (array $row): array {
                $templateId = isset($row['template_id']) && $row['template_id'] !== null ? (int) $row['template_id'] : null;
                $templateName = isset($row['template_name']) && $row['template_name'] !== null ? trim((string) $row['template_name']) : null;
                $subject = $this->scalarToString($row['subject'] ?? '');
                $templateMeta = $templateMap[$this->normalizeSubject($subject)] ?? null;
                if (!$templateId && is_array($templateMeta) && isset($templateMeta['id'])) {
                    $templateId = (int) $templateMeta['id'];
                }
                if (!$templateName && is_array($templateMeta) && isset($templateMeta['name'])) {
                    $templateName = trim((string) $templateMeta['name']);
                }
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'subject' => $subject,
                    'template_id' => $templateId,
                    'template_name' => $templateName ?: null,
                    'template' => $templateId ? [
                        'id' => $templateId,
                        'name' => $templateName ?: null,
                        'subject' => is_array($templateMeta) ? ($templateMeta['subject'] ?? null) : null,
                    ] : null,
                    'status' => $this->scalarToString($row['status'] ?? ''),
                    'sourceType' => $this->scalarToString($row['sourceType'] ?? ''),
                    'total' => (int) ($row['total'] ?? 0),
                    'sent' => (int) ($row['sent'] ?? 0),
                    'failed' => (int) ($row['failed'] ?? 0),
                    'createdAt' => $row['createdAt'] ?? null,
                    'user' => [
                        'id' => (int) ($row['user_id'] ?? 0),
                        'name' => $this->scalarToString($row['user_name'] ?? '-') ?: '-',
                        'email' => $this->scalarToString($row['user_email'] ?? '-') ?: '-',
                    ],
                ];
            }, $rawOrders);

            $stats = $this->getStats();
            $users = $this->em->createQueryBuilder()
                ->select('u.id', 'u.name', 'u.email')
                ->from(User::class, 'u')
                ->where('u.isActive = :active')
                ->setParameter('active', true)
                ->orderBy('u.name', 'ASC')
                ->getQuery()
                ->getResult();

            $html = $this->twig->render('admin/email-orders/index.twig', [
                'orders' => $orders,
                'stats' => $stats,
                'users' => $users,
                'search' => $search,
                'selected_status' => $status,
                'selected_user' => $userId,
                'sort' => $sort,
                'dir' => $dir,
                'page' => $page,
                'total_pages' => $totalPages,
                'total' => $total,
                'per_page' => $perPage,
                'sibling_panels' => (new \App\Application\Services\PanelRegistryService())->publicList(),
            ]);
            $response->getBody()->write($html);
            return $response;
        } catch (\Throwable $e) {
            error_log('Admin EmailOrders index error: ' . $e->getMessage());
            error_log($e->getTraceAsString());

            $fallbackUsers = [];
            try {
                $fallbackUsers = $this->em->createQueryBuilder()
                    ->select('u.id', 'u.name', 'u.email')
                    ->from(User::class, 'u')
                    ->where('u.isActive = :active')
                    ->setParameter('active', true)
                    ->orderBy('u.name', 'ASC')
                    ->getQuery()
                    ->getResult();
            } catch (\Throwable) {
                $fallbackUsers = [];
            }

            $html = $this->twig->render('admin/email-orders/index.twig', [
                'orders' => [],
                'stats' => [
                    'total' => 0,
                    'pending_approval' => 0,
                    'pending' => 0,
                    'processing' => 0,
                    'completed' => 0,
                    'total_emails' => 0,
                ],
                'users' => $fallbackUsers,
                'search' => $search,
                'selected_status' => $status,
                'selected_user' => $userId,
                'sort' => $sort,
                'dir' => $dir,
                'page' => 1,
                'total_pages' => 1,
                'total' => 0,
                'per_page' => $perPage,
                'error' => 'Liste yüklenirken bir hata oluştu. Teknik detaylar loglara yazıldı.',
            ]);
            $response->getBody()->write($html);
            return $response->withStatus(200);
        }
    }

    /**
     * Email sipariş detayı (AJAX için JSON)
     */
    public function getDetails(Request $request, Response $response, array $args): Response
    {
        try {
            $orderId = (int) $args['id'];
            $query = $request->getQueryParams();
            $section = strtolower(trim((string) ($query['section'] ?? 'summary')));
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = (int) ($query['per_page'] ?? 50);
            $perPage = max(1, min(100, $perPage));
            $includeBody = ((int) ($query['include_body'] ?? 0)) === 1;
            $includePoolLists = ((int) ($query['include_pool_lists'] ?? 0)) === 1;
            
            if ($orderId <= 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Geçersiz sipariş ID'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $order = $this->em->find(EmailOrder::class, $orderId);

            if (!$order) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Sipariş bulunamadı'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $orderSummary = $this->buildOrderSummaryPayload($order, $includeBody);

            if ($section === 'summary') {
                $data = [
                    'success' => true,
                    'section' => 'summary',
                    'order' => $orderSummary,
                ];
                if ($includePoolLists && $order->isPoolOrder()) {
                    [$poolListsPayload, $defaultPoolListId] = $this->loadPoolListsPayload();
                    $data['pool_lists'] = $poolListsPayload;
                    $data['default_pool_list_id'] = $defaultPoolListId;
                }

                $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            if ($section === 'recipients') {
                $payload = $this->loadRecipientsPayload($order, $page, $perPage);
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'section' => 'recipients',
                    'order' => $orderSummary,
                    'pagination' => $payload['pagination'],
                    'items' => $payload['items'],
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            if ($section === 'errors') {
                $payload = $this->loadErrorsPayload($order, $page, $perPage);
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'section' => 'errors',
                    'order' => $orderSummary,
                    'pagination' => $payload['pagination'],
                    'items' => $payload['items'],
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            if ($section === 'logs') {
                $payload = $this->loadLogsPayload($order, $page, $perPage);
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'section' => 'logs',
                    'order' => $orderSummary,
                    'pagination' => $payload['pagination'],
                    'items' => $payload['items'],
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            if ($section === 'tracking') {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'section' => 'tracking',
                    'order' => $orderSummary,
                    'tracking' => [
                        'total' => $order->getTotal(),
                        'sent' => $order->getSent(),
                        'delivered' => $order->getDelivered(),
                        'failed' => $order->getFailed(),
                        'bounced' => $order->getBounced() ?? 0,
                        'deliveryRate' => $order->getTotal() > 0
                            ? round((($order->getDelivered() / $order->getTotal()) * 100), 2)
                            : 0,
                    ],
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Geçersiz detay bölümü'
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            
        } catch (\Exception $e) {
            // Log hatayı
            error_log('Email Order Details Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Sipariş detayları yüklenirken bir hata oluştu',
                'error' => $e->getMessage() // Geliştirme ortamında hata mesajını göster
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Kampanyayı onayla (approve) - Bakiye kesilir, worker'a gider
     */
    public function approvalData(Request $request, Response $response, array $args): Response
    {
        // Legacy endpoint: forward to new context payload.
        return $this->approvalContext($request, $response, $args);
    }

    public function approvalContext(Request $request, Response $response, array $args): Response
    {
        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz sipariş ID'], 400);
        }

        $order = $this->em->find(EmailOrder::class, $orderId);
        if (!$order) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Sipariş bulunamadı'], 404);
        }

        [$poolListsPayload, $defaultPoolListId] = $this->loadPoolListsPayload();
        $summary = $this->buildOrderSummaryPayload($order, false);
        $summary['template_id'] = $order->getTemplate()?->getId() ?: ($summary['template']['id'] ?? null);
        $summary['template_name'] = $order->getTemplate()?->getName() ?: ($summary['template']['name'] ?? null);

        return $this->jsonResponse($response, [
            'success' => true,
            'order' => [
                'id' => $summary['id'],
                'subject' => $summary['subject'],
                'total' => $summary['total'],
                'sent' => $summary['sent'],
                'failed' => $summary['failed'],
                'status' => $summary['status'],
                'template' => [
                    'id' => $summary['template_id'],
                    'name' => $summary['template_name'],
                    'subject' => $summary['subject'],
                ],
                'is_pool_order' => $summary['is_pool_order'],
                'selected_data_list_id' => $summary['pool_list_id'] ?: $defaultPoolListId,
            ],
            'data_lists' => $poolListsPayload,
            'data' => [
                'order' => [
                    'id' => $summary['id'],
                    'subject' => $summary['subject'],
                    'total' => (int) $summary['total'],
                    'status' => $summary['status'],
                    'template' => [
                        'id' => $summary['template_id'],
                        'name' => $summary['template_name'],
                    ],
                    'isPoolOrder' => (bool) $summary['is_pool_order'],
                    'selectedDataPoolId' => $summary['pool_list_id'] ?: $defaultPoolListId,
                ],
                'dataPools' => array_map(function (array $list): array {
                    return [
                        'id' => (int) ($list['id'] ?? 0),
                        'name' => (string) ($list['name'] ?? ''),
                        'activeCount' => (int) ($list['active_count'] ?? 0),
                    ];
                }, $poolListsPayload),
            ],
        ]);
    }

    public function showOrder(Request $request, Response $response, array $args): Response
    {
        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz sipariş ID'], 400);
        }

        $order = $this->em->find(EmailOrder::class, $orderId);
        if (!$order) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Sipariş bulunamadı'], 404);
        }

        $summary = $this->buildOrderSummaryPayload($order, false);
        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'order' => [
                    'id' => (int) ($summary['id'] ?? 0),
                    'subject' => (string) ($summary['subject'] ?? ''),
                    'total' => (int) ($summary['total'] ?? 0),
                    'status' => (string) ($summary['status'] ?? ''),
                    'template' => [
                        'id' => isset($summary['template']['id']) ? (int) $summary['template']['id'] : null,
                        'name' => (string) ($summary['template']['name'] ?? ''),
                    ],
                    'isPoolOrder' => (bool) ($summary['is_pool_order'] ?? false),
                    'selectedDataPoolId' => isset($summary['pool_list_id']) ? (int) $summary['pool_list_id'] : null,
                    'sendCount' => (int) ($summary['total'] ?? 0),
                ],
            ],
        ]);
    }

    public function searchCustomers(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $search = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? $query['limit'] ?? 20)));
        $positiveOnly = ((int) ($query['positive_only'] ?? 1)) === 1;

        $result = $this->externalMailBalanceApi->listUsers($search, $page, $perPage);
        if (!$result['success']) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $this->translateExternalApiError($result) ?: 'Müşteri listesi yüklenemedi.',
            ], (int) ($result['status'] ?? 500));
        }

        $items = array_map(function (array $user): array {
            $balance = (int) ($user['mail_balance'] ?? $user['balance'] ?? 0);
            return [
                'id' => (int) ($user['id'] ?? 0),
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'balance' => $balance,
                'credit' => $balance,
            ];
        }, (array) ($result['users'] ?? []));

        if ($positiveOnly) {
            $items = array_values(array_filter($items, static fn (array $row): bool => (int) ($row['balance'] ?? 0) > 0));
        }

        $pagination = $this->normalizeAdminPagination($result['pagination'] ?? [], $page, $perPage, count($items));

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => $pagination,
            ],
        ]);
    }

    public function getCustomerBalance(Request $request, Response $response, array $args): Response
    {
        $customerId = (int) ($args['id'] ?? 0);
        if ($customerId <= 0) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz müşteri ID'], 400);
        }

        $query = $request->getQueryParams();
        $orderId = (int) ($query['order_id'] ?? 0);
        $required = 0;
        if ($orderId > 0) {
            $order = $this->em->find(EmailOrder::class, $orderId);
            if (!$order) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Sipariş bulunamadı'], 404);
            }
            $required = (int) $order->getTotal();
        }

        $result = $this->externalMailBalanceApi->getUser($customerId);
        if (!$result['success']) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $this->translateExternalApiError($result) ?: 'Müşteri bakiyesi alınamadı.',
            ], (int) ($result['status'] ?? 500));
        }

        $user = is_array($result['user'] ?? null) ? $result['user'] : [];
        $balance = (int) ($user['mail_balance'] ?? $user['balance'] ?? 0);
        $remaining = $balance - $required;
        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'userId' => $customerId,
                'balance' => $balance,
                'required' => $required,
                'remaining' => $remaining,
                'canApprove' => $remaining >= 0,
            ],
        ]);
    }

    public function getAvailableDataPools(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $orderId = (int) ($query['order_id'] ?? 0);

        $required = 0;
        $selectedId = null;
        if ($orderId > 0) {
            $order = $this->em->find(EmailOrder::class, $orderId);
            if (!$order) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Sipariş bulunamadı'], 404);
            }
            $required = (int) $order->getTotal();
            $selectedId = $order->getPoolList()?->getId();
        }

        [$poolListsPayload] = $this->loadPoolListsPayload();
        $items = array_map(function (array $list) use ($required): array {
            $activeCount = (int) ($list['active_count'] ?? 0);
            return [
                'id' => (int) ($list['id'] ?? 0),
                'name' => (string) ($list['name'] ?? ''),
                'activeCount' => $activeCount,
                'canUse' => $required > 0 ? $activeCount >= $required : true,
            ];
        }, $poolListsPayload);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'items' => $items,
                'selectedId' => $selectedId,
                'required' => $required,
            ],
        ]);
    }

    public function externalUsers(Request $request, Response $response): Response
    {
        // Legacy endpoint: forward to new proxy endpoint.
        return $this->externalBalanceUsers($request, $response);
    }

    public function externalBalanceUsers(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $q = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));

        $result = $this->externalMailBalanceApi->listUsers($q, $page, $limit);
        if (!$result['success']) {
            error_log('externalBalanceUsers error status=' . (int) ($result['status'] ?? 500) . ' message=' . (string) ($result['message'] ?? 'unknown'));
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $this->translateExternalApiError($result),
                'status' => $result['status'] ?? 500,
                'retryable' => (int) ($result['status'] ?? 500) >= 500 || (int) ($result['status'] ?? 0) === 0,
            ], (int) ($result['status'] ?? 500));
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'users' => $result['users'] ?? [],
            'pagination' => $result['pagination'] ?? [
                'page' => $page,
                'limit' => $limit,
                'total' => count((array) ($result['users'] ?? [])),
                'has_next' => false,
                'total_pages' => 1,
            ],
        ]);
    }

    public function externalUserShow(Request $request, Response $response, array $args): Response
    {
        // Legacy endpoint: forward to new proxy endpoint.
        return $this->externalBalanceUserShow($request, $response, $args);
    }

    public function externalBalanceUserShow(Request $request, Response $response, array $args): Response
    {
        $externalUserId = (int) ($args['id'] ?? 0);
        if ($externalUserId <= 0) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz müşteri ID'], 400);
        }

        $result = $this->externalMailBalanceApi->getUser($externalUserId);
        if (!$result['success']) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $this->translateExternalApiError($result),
                'status' => $result['status'] ?? 500,
                'data' => $result['data'] ?? [],
            ], (int) ($result['status'] ?? 500));
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'user' => $result['user'] ?? [],
        ]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        // Legacy endpoint: keep behavior but new flow name is approveWithBalance.
        return $this->approveWithBalance($request, $response, $args);
    }

    public function approveAndStart(Request $request, Response $response, array $args): Response
    {
        return $this->approveWithBalance($request, $response, $args);
    }

    public function approveWithBalance(Request $request, Response $response, array $args): Response
    {
        $externalDebitDone = false;
        $externalUserId = 0;
        $orderTotal = 0;
        $orderSubject = '';
        $approvalDescription = '';
        $externalUserData = [];
        $selectedDataListId = null;
        $selectedDataListName = null;
        $adminUserId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
        $subtractResult = null;

        try {
            $orderId = (int) $args['id'];
            if ($orderId <= 0) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz sipariş ID'], 400);
            }

            $body = (string) $request->getBody();
            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                $payload = $request->getParsedBody() ?: [];
            }
            $externalUserId = isset($payload['external_user_id'])
                ? (int) $payload['external_user_id']
                : (isset($payload['external_customer_id'])
                    ? (int) $payload['external_customer_id']
                    : (isset($payload['userId']) ? (int) $payload['userId'] : 0));
            if ($externalUserId <= 0) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Lütfen müşteri seçin.'], 400);
            }

            $requestedPoolListId = isset($payload['data_list_id'])
                ? (int) $payload['data_list_id']
                : (isset($payload['pool_list_id'])
                    ? (int) $payload['pool_list_id']
                    : (isset($payload['dataPoolId']) ? (int) $payload['dataPoolId'] : null));
            $conn = $this->em->getConnection();
            $conn->beginTransaction();

            $order = $this->em->find(EmailOrder::class, $orderId);
            if (!$order) {
                $conn->rollBack();
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Sipariş bulunamadı'], 404);
            }
            $this->em->lock($order, LockMode::PESSIMISTIC_WRITE);

            if ($order->getStatus() !== EmailOrderStatus::PENDING_APPROVAL) {
                $conn->rollBack();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Bu sipariş zaten onay sürecinde veya onaylanmış.'
                ], 422);
            }

            if (isset($payload['sendCount'])) {
                $sendCount = (int) $payload['sendCount'];
                if ($sendCount <= 0 || $sendCount !== (int) $order->getTotal()) {
                    $conn->rollBack();
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Gönderim adedi sipariş verisi ile uyuşmuyor.',
                    ], 422);
                }
            }

            if ($order->isPoolOrder()) {
                if ($requestedPoolListId === null || $requestedPoolListId < 1) {
                    $conn->rollBack();
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Sistem havuzu siparişi için hangi veri listesinin kullanılacağını seçmelisiniz.',
                    ], 422);
                }
                $poolList = $this->em->find(EmailDataPoolList::class, $requestedPoolListId);
                if (!$poolList) {
                    $conn->rollBack();
                    return $this->jsonResponse($response, ['success' => false, 'message' => 'Veri listesi bulunamadı'], 404);
                }
                $availableInList = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(p.id)')
                    ->from(EmailDataPool::class, 'p')
                    ->where('p.poolList = :list')
                    ->andWhere('p.isActive = :active')
                    ->setParameter('list', $poolList)
                    ->setParameter('active', true)
                    ->getQuery()
                    ->getSingleScalarResult();
                if ($availableInList < $order->getTotal()) {
                    $conn->rollBack();
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => sprintf(
                            'Seçilen listede yeterli aktif kayıt yok (gerekli: %d, mevcut: %d).',
                            $order->getTotal(),
                            $availableInList
                        ),
                    ], 422);
                }
                $order->setPoolList($poolList);
                $selectedDataListId = $poolList->getId();
                $selectedDataListName = $poolList->getName();
            }

            $orderTotal = max(0, $order->getTotal());
            if ($orderTotal < 1) {
                $conn->rollBack();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Siparişte gönderim adedi bulunamadı.'
                ], 400);
            }

            $orderSubject = trim((string) $order->getSubject());
            $approvalDescription = sprintf('Nexus Mail gönderim onayı - Order #%d - %s', $order->getId(), $orderSubject);

            $externalUserResult = $this->externalMailBalanceApi->getUser($externalUserId);
            if (!$externalUserResult['success']) {
                $translated = $this->translateExternalApiError($externalUserResult);
                $this->insertApprovalLog([
                    'order_id' => $order->getId(),
                    'admin_user_id' => $adminUserId,
                    'external_customer_id' => $externalUserId,
                    'external_customer_name' => null,
                    'external_customer_email' => null,
                    'selected_data_list_id' => $selectedDataListId,
                    'selected_data_list_name' => $selectedDataListName,
                    'order_total' => $orderTotal,
                    'balance_old_amount' => null,
                    'balance_amount' => $orderTotal,
                    'balance_new_amount' => null,
                    'status' => 'failed',
                    'error_message' => $translated,
                    'api_response' => $externalUserResult,
                ], $conn);
                $conn->commit();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => $translated
                ], (int) ($externalUserResult['status'] ?? 400));
            }
            $externalUserData = is_array($externalUserResult['user'] ?? null)
                ? $externalUserResult['user']
                : (is_array($externalUserResult['data']) ? $externalUserResult['data'] : []);
            $currentBalance = (int) ($externalUserData['mail_balance'] ?? $externalUserData['balance'] ?? 0);
            if ($currentBalance > 0 && $currentBalance < $orderTotal) {
                $this->notifyTelegramSafely(
                    TelegramNotificationService::EVENT_BALANCE_LOW,
                    $order,
                    [
                        'status' => $order->getStatus()->value,
                        'send_count' => $orderTotal,
                        'remaining_balance' => $currentBalance,
                        'user_name' => (string) ($externalUserData['name'] ?? ''),
                        'user_email' => (string) ($externalUserData['email'] ?? ''),
                    ]
                );
                if ($currentBalance <= 0) {
                    $this->notifyTelegramSafely(
                        TelegramNotificationService::EVENT_BALANCE_INSUFFICIENT,
                        $order,
                        [
                            'status' => $order->getStatus()->value,
                            'send_count' => $orderTotal,
                            'remaining_balance' => $currentBalance,
                            'user_name' => (string) ($externalUserData['name'] ?? ''),
                            'user_email' => (string) ($externalUserData['email'] ?? ''),
                        ]
                    );
                }
                $conn->rollBack();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => sprintf(
                        'Yetersiz mail bakiyesi. Gerekli: %s, Mevcut: %s',
                        number_format($orderTotal, 0, ',', '.'),
                        number_format($currentBalance, 0, ',', '.')
                    )
                ], 422);
            }

            $subtractResult = $this->externalMailBalanceApi->subtractBalance($externalUserId, $orderTotal, $approvalDescription);
            if (!$subtractResult['success']) {
                $translated = $this->translateExternalApiError($subtractResult);
                $this->insertApprovalLog([
                    'order_id' => $order->getId(),
                    'admin_user_id' => $adminUserId,
                    'external_customer_id' => $externalUserId,
                    'external_customer_name' => (string) ($externalUserData['name'] ?? ''),
                    'external_customer_email' => (string) ($externalUserData['email'] ?? ''),
                    'selected_data_list_id' => $selectedDataListId,
                    'selected_data_list_name' => $selectedDataListName,
                    'order_total' => $orderTotal,
                    'balance_old_amount' => isset($subtractResult['data']['current_balance']) ? (int) $subtractResult['data']['current_balance'] : null,
                    'balance_amount' => $orderTotal,
                    'balance_new_amount' => null,
                    'status' => 'failed',
                    'error_message' => $translated,
                    'api_response' => $subtractResult,
                ], $conn);
                $conn->commit();
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => $translated
                ], (int) ($subtractResult['status'] ?? 400));
            }
            $externalDebitDone = true;

            $order->setStatus(EmailOrderStatus::PENDING);
            $this->em->flush();

            $balanceData = is_array($subtractResult['data'] ?? null) ? $subtractResult['data'] : [];
            $this->insertApprovalLog([
                'order_id' => $order->getId(),
                'admin_user_id' => $adminUserId,
                'external_customer_id' => $externalUserId,
                'external_customer_name' => (string) ($externalUserData['name'] ?? ''),
                'external_customer_email' => (string) ($externalUserData['email'] ?? ''),
                'selected_data_list_id' => $selectedDataListId,
                'selected_data_list_name' => $selectedDataListName,
                'order_total' => $orderTotal,
                'balance_old_amount' => isset($balanceData['old_balance']) ? (int) $balanceData['old_balance'] : null,
                'balance_amount' => isset($balanceData['amount']) ? (int) $balanceData['amount'] : $orderTotal,
                'balance_new_amount' => isset($balanceData['new_balance']) ? (int) $balanceData['new_balance'] : null,
                'status' => 'success',
                'error_message' => null,
                'api_response' => $subtractResult,
            ], $conn);

            $conn->commit();
            $commonContext = [
                'status' => EmailOrderStatus::PENDING->value,
                'send_count' => $orderTotal,
                'remaining_balance' => (int) ($balanceData['new_balance'] ?? 0),
                'data_pool_name' => $selectedDataListName ?? ($order->getPoolList()?->getName() ?? '-'),
                'template_name' => $order->getTemplate()?->getName() ?? '-',
                'user_name' => (string) ($externalUserData['name'] ?? ''),
                'user_email' => (string) ($externalUserData['email'] ?? ''),
            ];
            $this->notifyTelegramSafely(TelegramNotificationService::EVENT_QUEUED, $order, $commonContext);
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Gönderim onaylandı, müşteri bakiyesi düşüldü ve sipariş kuyruğa alındı.',
                'data' => [
                    'orderId' => $order->getId(),
                    'userId' => $externalUserId,
                    'dataPoolId' => $selectedDataListId,
                    'sendCount' => $orderTotal,
                    'amount' => $orderTotal,
                ],
            ]);
        } catch (\Throwable $e) {
            if (isset($conn) && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            if ($externalDebitDone && $externalUserId > 0 && $orderTotal > 0) {
                $rollbackResult = $this->externalMailBalanceApi->addBalance(
                    $externalUserId,
                    $orderTotal,
                    sprintf('Nexus Mail gönderim onayı rollback - Order #%d', (int) ($args['id'] ?? 0))
                );
                $rollbackStatus = $rollbackResult['success'] ? 'rollback_success' : 'rollback_failed';
                $rollbackMessage = $rollbackResult['success']
                    ? 'Onay sonrası sistem hatası nedeniyle bakiye geri eklendi.'
                    : 'Onay sonrası hata oluştu, bakiye geri eklenemedi. Manuel kontrol gerekli.';

                $this->insertApprovalLog([
                    'order_id' => (int) ($args['id'] ?? 0),
                    'admin_user_id' => $adminUserId,
                    'external_customer_id' => $externalUserId,
                    'external_customer_name' => (string) ($externalUserData['name'] ?? ''),
                    'external_customer_email' => (string) ($externalUserData['email'] ?? ''),
                    'selected_data_list_id' => $selectedDataListId,
                    'selected_data_list_name' => $selectedDataListName,
                    'order_total' => $orderTotal,
                    'balance_old_amount' => null,
                    'balance_amount' => $orderTotal,
                    'balance_new_amount' => null,
                    'status' => $rollbackStatus,
                    'error_message' => $rollbackMessage,
                    'api_response' => $rollbackResult,
                ]);

                if ($rollbackResult['success']) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Onay sırasında sistem hatası oluştu. Düşülen bakiye geri eklendi, sipariş onaylanmadı.'
                    ], 500);
                }
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Onay sırasında kritik hata oluştu. Bakiye geri eklenemedi, manuel müdahale gerekli.'
                ], 500);
            }

            error_log(sprintf(
                'Email Order Approve Error: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            error_log($e->getTraceAsString());
            $schemaAwareMessage = $this->buildSchemaAwareApprovalErrorMessage($e);
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $schemaAwareMessage ?? 'Onay işlemi sırasında sistem hatası oluştu. Lütfen tekrar deneyin.'
            ], 500);
        }
    }

    /**
     * Kampanya şablonunu güncelle (subject, body)
     */
    public function updateTemplate(Request $request, Response $response, array $args): Response
    {
        try {
            $orderId = (int) $args['id'];
            $body = (string) $request->getBody();
            $data = json_decode($body, true) ?: $request->getParsedBody() ?: [];

            $order = $this->em->find(EmailOrder::class, $orderId);

            if (!$order) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => 'Sipariş bulunamadı']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            if ($order->getStatus() !== EmailOrderStatus::PENDING_APPROVAL) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => 'Sadece onay bekleyen siparişlerin şablonu düzenlenebilir']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            if (!empty($data['subject'])) {
                $order->setSubject(trim($data['subject']));
            }
            if (isset($data['body'])) {
                $order->setBody($data['body']);
            }

            $this->em->flush();

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Şablon güncellendi',
                'order' => [
                    'subject' => $order->getSubject(),
                    'body' => $order->getBody()
                ]
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            error_log('Email Order UpdateTemplate Error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Güncelleme hatası: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Bitmiş veya iptal edilmiş kampanyayı tekrar kuyruğa al (worker yeniden işler).
     * Tamamlanmış / başarısız: total kadar mail kredisi tekrar kesilir.
     * Yalnızca iptal (gönderim sayaçları sıfırsa): ek kredi kesilmez (ilk onayda kesilen kredi ile devam).
     */
    public function restartCampaign(Request $request, Response $response, array $args): Response
    {
        try {
            $orderId = (int) ($args['id'] ?? 0);
            if ($orderId <= 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Geçersiz sipariş ID',
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $order = $this->em->find(EmailOrder::class, $orderId);
            if (!$order) {
                $response->getBody()->write(json_encode(['success' => false, 'message' => 'Sipariş bulunamadı']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $st = $order->getStatus();
            $restartable = [
                EmailOrderStatus::SENT,
                EmailOrderStatus::COMPLETED,
                EmailOrderStatus::FAILED,
                EmailOrderStatus::CANCELLED,
            ];
            if (!in_array($st, $restartable, true)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Yalnızca gönderilmiş, tamamlanmış, başarısız veya iptal edilmiş kampanyalar yeniden başlatılabilir.',
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $body = (string) $request->getBody();
            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                $payload = $request->getParsedBody() ?: [];
            }
            $requestedPoolListId = isset($payload['pool_list_id']) ? (int) $payload['pool_list_id'] : null;

            $user = $order->getUser();
            $touched = $order->getSent() + $order->getFailed() + $order->getDelivered() + $order->getBounced();
            $chargeCredit = in_array($st, [EmailOrderStatus::SENT, EmailOrderStatus::COMPLETED, EmailOrderStatus::FAILED], true);
            if ($st === EmailOrderStatus::CANCELLED) {
                $hasApprovalDebit = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(t.id)')
                    ->from(\App\Domain\Entities\EmailTransaction::class, 't')
                    ->where('t.user = :user')
                    ->andWhere('t.type = :debit')
                    ->andWhere('t.description LIKE :needle')
                    ->setParameter('user', $user)
                    ->setParameter('debit', \App\Domain\Enum\EmailTransactionType::DEBIT)
                    ->setParameter('needle', '%#' . $orderId . ' onaylandı%')
                    ->getQuery()
                    ->getSingleScalarResult() > 0;
                // Onayda kredi kesilmiş ve hiç gönderim yoksa: ek kesinti yok. Aksi halde (iptal öncesi işlem veya onaysız iptal) kredi gerekir.
                $chargeCredit = ($touched > 0) || !$hasApprovalDebit;
            }

            if ($order->isPoolOrder()) {
                $poolList = $order->getPoolList();
                if ($requestedPoolListId !== null && $requestedPoolListId > 0) {
                    $poolList = $this->em->find(EmailDataPoolList::class, $requestedPoolListId);
                    if (!$poolList) {
                        $response->getBody()->write(json_encode(['success' => false, 'message' => 'Geçersiz havuz listesi']));
                        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                    }
                    $order->setPoolList($poolList);
                }
                if ($order->getPoolList() === null) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'Havuz siparişi için veri listesi seçilmelidir.',
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
                $pl = $order->getPoolList();
                $availableInList = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(p.id)')
                    ->from(EmailDataPool::class, 'p')
                    ->where('p.poolList = :list')
                    ->andWhere('p.isActive = :active')
                    ->setParameter('list', $pl)
                    ->setParameter('active', true)
                    ->getQuery()
                    ->getSingleScalarResult();
                if ($availableInList < $order->getTotal()) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => sprintf(
                            'Seçilen listede yeterli aktif kayıt yok (gerekli: %d, mevcut: %d).',
                            $order->getTotal(),
                            $availableInList
                        ),
                    ], JSON_UNESCAPED_UNICODE));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }

            $totalEmails = $order->getTotal();
            if ($totalEmails < 1) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Siparişte gönderilecek adet tanımlı değil.',
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            if ($chargeCredit) {
                $emailCredit = $user->getEmailCredit() ?? 0;
                if ($totalEmails > $emailCredit) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => "Yetersiz mail kredisi. Gerekli: {$totalEmails}, Mevcut: {$emailCredit}",
                    ], JSON_UNESCAPED_UNICODE));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
            }

            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            try {
                if ($chargeCredit) {
                    $emailCredit = $user->getEmailCredit() ?? 0;
                    $newCredit = $emailCredit - $totalEmails;
                    $user->setEmailCredit($newCredit);

                    $transaction = new \App\Domain\Entities\EmailTransaction();
                    $transaction->setUser($user);
                    $transaction->setType(\App\Domain\Enum\EmailTransactionType::DEBIT);
                    $transaction->setAmount($totalEmails);
                    $transaction->setDescription("Mail siparişi #{$order->getId()} yeniden başlatıldı - {$totalEmails} mail gönderimi");
                    $transaction->setBalanceBefore($emailCredit);
                    $transaction->setBalanceAfter($newCredit);
                    $this->em->persist($transaction);
                    $this->em->persist($user);
                }

                if ($order->isPoolOrder()) {
                    $conn->executeStatement('DELETE FROM email_order_emails WHERE order_id = ?', [$orderId]);
                } else {
                    $conn->executeStatement(
                        "UPDATE email_order_emails SET status = 'pending', sent_at = NULL, delivered_at = NULL, error = NULL, message_id = NULL WHERE order_id = ?",
                        [$orderId]
                    );
                }

                $order->setStatus(EmailOrderStatus::PENDING);
                $order->setSent(0);
                $order->setDelivered(0);
                $order->setBounced(0);
                $order->setFailed(0);
                $order->setLastPoolId(null);
                $order->setLockedAt(null);
                $order->setLockedBy(null);
                $order->setAttemptCount(0);
                $order->setCompletedAt(null);

                $this->em->flush();
                $conn->commit();
                $this->notifyTelegramSafely(
                    TelegramNotificationService::EVENT_RESTARTED,
                    $order,
                    [
                        'status' => EmailOrderStatus::PENDING->value,
                        'send_count' => $totalEmails,
                        'data_pool_name' => $order->getPoolList()?->getName() ?? '-',
                    ]
                );

                $msg = $chargeCredit
                    ? 'Kampanya yeniden kuyruğa alındı; mail kredisi kesildi. Worker işleyecek.'
                    : 'Kampanya yeniden kuyruğa alındı (ek kredi kesilmedi). Worker işleyecek.';
                $response->getBody()->write(json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json');
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            error_log('Email Order restartCampaign Error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Yeniden başlatma hatası: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Kampanyayı durdur (cancel)
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        try {
            $orderId = (int) $args['id'];
            
            if ($orderId <= 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Geçersiz sipariş ID'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $order = $this->em->find(EmailOrder::class, $orderId);

            if (!$order) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Sipariş bulunamadı'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Pending approval, pending veya processing durumundaki kampanyalar durdurulabilir
            if (!in_array($order->getStatus(), [EmailOrderStatus::PENDING_APPROVAL, EmailOrderStatus::PENDING, EmailOrderStatus::PROCESSING], true)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Sadece bekleyen veya işlemdeki kampanyalar durdurulabilir'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Durumu cancelled olarak güncelle
            $order->setStatus(EmailOrderStatus::CANCELLED);
            $order->setCompletedAt(new \DateTime());
            $this->em->flush();
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_CANCELLED,
                $order,
                ['status' => EmailOrderStatus::CANCELLED->value]
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Kampanya durduruldu'
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log('Email Order Cancel Error: ' . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Kampanya durdurulurken bir hata oluştu: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Kampanyayı sil (delete)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $orderId = (int) $args['id'];
            
            if ($orderId <= 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Geçersiz sipariş ID'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $order = $this->em->find(EmailOrder::class, $orderId);

            if (!$order) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Sipariş bulunamadı'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Pending approval hariç - işlemde veya bekleyen kampanyalar önce durdurulmalı
            if (in_array($order->getStatus(), [EmailOrderStatus::PROCESSING, EmailOrderStatus::PENDING], true)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'İşlemdeki veya bekleyen kampanyalar önce durdurulmalıdır'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $this->bulkDeleteEmailOrdersFast([$orderId]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Kampanya silindi'
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log('Email Order Delete Error: ' . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Kampanya silinirken bir hata oluştu: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Kampanyaları toplu sil (bulk delete)
     */
    public function bulkDelete(Request $request, Response $response): Response
    {
        try {
            $body = (string) $request->getBody();
            $data = json_decode($body, true) ?: [];

            if (!isset($data['order_ids']) || !is_array($data['order_ids']) || empty($data['order_ids'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Sipariş ID listesi gerekli'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $orderIds = array_values(array_unique(array_map('intval', $data['order_ids'])));
            $deleted = 0;
            $failed = 0;
            $errors = [];
            $deletableIds = [];

            foreach ($orderIds as $orderId) {
                if ($orderId <= 0) {
                    $failed++;
                    $errors[] = 'Geçersiz sipariş ID';
                    continue;
                }

                try {
                    $order = $this->em->find(EmailOrder::class, $orderId);

                    if (!$order) {
                        $failed++;
                        $errors[] = "Sipariş #{$orderId} bulunamadı";
                        continue;
                    }

                    if (in_array($order->getStatus(), [EmailOrderStatus::PROCESSING, EmailOrderStatus::PENDING], true)) {
                        $failed++;
                        $errors[] = "Sipariş #{$orderId} silinemedi (önce durdurulmalı)";
                        continue;
                    }
                    $deletableIds[] = $orderId;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Sipariş #{$orderId}: " . $e->getMessage();
                }
            }

            if (!empty($deletableIds)) {
                try {
                    $deleted = $this->bulkDeleteEmailOrdersFast($deletableIds);
                } catch (\Exception $e) {
                    $failed += count($deletableIds);
                    $errors[] = 'Toplu silme hatası: ' . $e->getMessage();
                }
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'deleted' => $deleted,
                'failed' => $failed,
                'errors' => $errors,
                'message' => "{$deleted} kampanya silindi" . ($failed > 0 ? ", {$failed} başarısız" : "")
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Toplu silme sırasında hata: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Büyük email siparişi silmelerini SQL batch ile hızlandır.
     */
    private function bulkDeleteEmailOrdersFast(array $orderIds): int
    {
        if (empty($orderIds)) {
            return 0;
        }

        $conn = $this->em->getConnection();
        $deleted = 0;
        $chunks = array_chunk($orderIds, 200);

        $conn->beginTransaction();
        try {
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                $conn->executeStatement(
                    "DELETE FROM email_order_emails WHERE order_id IN ($placeholders)",
                    $chunk
                );
                $deleted += $conn->executeStatement(
                    "DELETE FROM email_orders WHERE id IN ($placeholders)",
                    $chunk
                );
            }

            $conn->commit();
            $this->em->clear();
            return $deleted;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * İstatistikler
     */
    private function getStats(): array
    {
        $conn = $this->em->getConnection();
        $row = $conn->executeQuery(
            "SELECT
                COUNT(id) AS total,
                SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) AS pendingApproval,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                SUM(CASE WHEN status IN ('sent', 'completed') THEN 1 ELSE 0 END) AS completed,
                COALESCE(SUM(total), 0) AS totalEmails
            FROM email_orders"
        )->fetchAssociative();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending_approval' => (int) ($row['pendingApproval'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'total_emails' => (int) ($row['totalEmails'] ?? 0),
        ];
    }

    private function emailOrdersHasTemplateIdColumn(): bool
    {
        try {
            $schemaManager = $this->em->getConnection()->createSchemaManager();
            $columns = $schemaManager->listTableColumns('email_orders');
            return isset($columns['template_id']) || isset($columns['templateid']);
        } catch (\Throwable $e) {
            error_log('emailOrdersHasTemplateIdColumn check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Liste sayfasında subject -> template eşleşmesini hafif payload ile kur.
     */
    private function buildTemplateMapBySubject(array $subjects): array
    {
        if (empty($subjects)) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('t.id AS id', 't.name AS name', 't.subject AS subject')
            ->from(EmailTemplate::class, 't')
            ->where('t.isApproved = :approved')
            ->andWhere('t.subject IN (:subjects)')
            ->setParameter('approved', true)
            ->setParameter('subjects', $subjects)
            ->orderBy('t.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $subject = (string) ($row['subject'] ?? '');
            if ($subject === '') {
                continue;
            }
            $key = $this->normalizeSubject($subject);
            if (isset($map[$key])) {
                continue;
            }
            $map[$key] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'subject' => $subject,
            ];
        }
        return $map;
    }

    private function findTemplateMetaBySubject(?string $subject): ?array
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            return null;
        }

        $row = $this->em->createQueryBuilder()
            ->select('t.id AS id', 't.name AS name')
            ->from(EmailTemplate::class, 't')
            ->where('t.subject = :subject')
            ->andWhere('t.isApproved = :approved')
            ->setParameter('subject', $subject)
            ->setParameter('approved', true)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    private function normalizeSubject(string $subject): string
    {
        $normalized = trim($subject);
        if ($normalized === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($normalized);
        }
        return strtolower($normalized);
    }

    private function scalarToString(mixed $value): string
    {
        $enumValue = EnumHelper::normalizeEnumValue($value);
        if ($enumValue !== '') {
            return $enumValue;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return '';
    }

    private function buildOrderSummaryPayload(EmailOrder $order, bool $includeBody = false): array
    {
        $subject = $this->scalarToString($order->getSubject());
        $template = $order->getTemplate()
            ? ['id' => $order->getTemplate()->getId(), 'name' => $order->getTemplate()->getName()]
            : $this->findTemplateMetaBySubject($subject);
        $latestApproval = $this->getLatestApprovalLog((int) $order->getId());
        return [
            'id' => $order->getId(),
            'user' => [
                'name' => $this->scalarToString($order->getUser()->getName()),
                'email' => $this->scalarToString($order->getUser()->getEmail()),
            ],
            'subject' => $subject,
            'template' => $template,
            'status' => $this->scalarToString($order->getStatus()),
            'source_type' => $this->scalarToString($order->getSourceType()),
            'is_pool_order' => $order->isPoolOrder(),
            'pool_list_id' => $order->getPoolList()?->getId(),
            'total' => $order->getTotal(),
            'sent' => $order->getSent(),
            'delivered' => $order->getDelivered(),
            'bounced' => $order->getBounced() ?? 0,
            'failed' => $order->getFailed(),
            'cost' => $order->getCost() ?? 0,
            'createdAt' => $order->getCreatedAt()->format('d.m.Y'),
            'updatedAt' => $order->getUpdatedAt()->format('d.m.Y'),
            'completedAt' => $order->getCompletedAt() ? $order->getCompletedAt()->format('d.m.Y') : null,
            'body' => $includeBody ? $order->getBody() : null,
            'approval' => $latestApproval,
        ];
    }

    private function loadRecipientsPayload(EmailOrder $order, int $page, int $perPage): array
    {
        $countQb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Domain\Entities\EmailOrderEmail::class, 'e')
            ->where('e.order = :order')
            ->setParameter('order', $order);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $rows = $this->em->createQueryBuilder()
            ->select('e')
            ->from(\App\Domain\Entities\EmailOrderEmail::class, 'e')
            ->where('e.order = :order')
            ->setParameter('order', $order)
            ->orderBy('e.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $items = array_map(function ($email): array {
            return [
                'email' => $email->getEmail(),
                'status' => $this->scalarToString($email->getStatus() ?? 'pending'),
                'sentAt' => $email->getSentAt() ? $email->getSentAt()->format('d.m.Y') : null,
                'deliveredAt' => $email->getDeliveredAt() ? $email->getDeliveredAt()->format('d.m.Y') : null,
                'errorMessage' => $email->getError() ?? null,
            ];
        }, $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    private function loadErrorsPayload(EmailOrder $order, int $page, int $perPage): array
    {
        $countQb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Domain\Entities\EmailOrderEmail::class, 'e')
            ->where('e.order = :order')
            ->andWhere('e.error IS NOT NULL')
            ->setParameter('order', $order);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $rows = $this->em->createQueryBuilder()
            ->select('e')
            ->from(\App\Domain\Entities\EmailOrderEmail::class, 'e')
            ->where('e.order = :order')
            ->andWhere('e.error IS NOT NULL')
            ->setParameter('order', $order)
            ->orderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $items = array_map(function ($email): array {
            return [
                'email' => $email->getEmail(),
                'status' => $this->scalarToString($email->getStatus() ?? ''),
                'errorMessage' => $email->getError(),
            ];
        }, $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    private function loadLogsPayload(EmailOrder $order, int $page, int $perPage): array
    {
        $countQb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Domain\Entities\EmailOrderEmail::class, 'e')
            ->where('e.order = :order')
            ->setParameter('order', $order);
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $rows = $this->em->createQueryBuilder()
            ->select('e')
            ->from(\App\Domain\Entities\EmailOrderEmail::class, 'e')
            ->where('e.order = :order')
            ->setParameter('order', $order)
            ->orderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $items = array_map(function ($email): array {
            return [
                'email' => $email->getEmail(),
                'status' => $this->scalarToString($email->getStatus() ?? ''),
                'sentAt' => $email->getSentAt() ? $email->getSentAt()->format('d.m.Y') : null,
                'deliveredAt' => $email->getDeliveredAt() ? $email->getDeliveredAt()->format('d.m.Y') : null,
            ];
        }, $rows);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    private function normalizeAdminPagination(array $pagination, int $fallbackPage, int $fallbackPerPage, int $fallbackTotal): array
    {
        $page = max(1, (int) ($pagination['page'] ?? $fallbackPage));
        $perPage = max(1, (int) ($pagination['limit'] ?? $pagination['perPage'] ?? $fallbackPerPage));
        $total = max(0, (int) ($pagination['total'] ?? $fallbackTotal));
        $hasNext = array_key_exists('has_next', $pagination)
            ? (bool) $pagination['has_next']
            : ($page < max(1, (int) ceil($total / $perPage)));

        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'hasNext' => $hasNext,
        ];
    }

    /**
     * Onay bekleyen siparişi başka bir panele taşı (sadece sipariş bilgisi gider;
     * alıcı/müşteri/liste hedef panelde onayda seçilir). Kaynak sipariş iptal edilir.
     */
    public function dispatchToPanel(Request $request, Response $response, array $args): Response
    {
        $orderId = (int) ($args['id'] ?? 0);
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $payload = $request->getParsedBody() ?: [];
        }
        $targetKey = trim((string) ($payload['panel'] ?? $payload['target'] ?? ''));

        $order = $this->em->find(EmailOrder::class, $orderId);
        if (!$order) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Sipariş bulunamadı.'], 404);
        }
        if ($order->getStatus() !== EmailOrderStatus::PENDING_APPROVAL) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Yalnızca onay bekleyen siparişler başka panele taşınabilir.'], 422);
        }

        $registry = new \App\Application\Services\PanelRegistryService();
        $panel = $registry->get($targetKey);
        if (!$panel) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Hedef panel bulunamadı. SIBLING_PANELS ayarını kontrol edin.'], 400);
        }

        $reqPayload = [
            'subject' => $order->getSubject(),
            'body' => $order->getBody(),
            'total' => $order->getTotal(),
            'delivery_percentage' => $order->getDeliveryPercentage(),
            'source_type' => $order->getSourceType(),
            'smtp_rotation_limit' => $order->getSmtpRotationLimit(),
            'source_panel' => (string) ($_ENV['SITE_URL'] ?? $_ENV['APP_URL'] ?? 'unknown'),
            'source_order_id' => $order->getId(),
            'owner_username' => $_SESSION['user']['username'] ?? null,
        ];

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 20, 'http_errors' => false]);
            $remote = $client->post($panel['url'] . '/api/email-orders/receive', [
                'headers' => ['X-Api-Token' => $panel['token'], 'Accept' => 'application/json'],
                'json' => $reqPayload,
            ]);
            $remoteBody = json_decode((string) $remote->getBody(), true);
        } catch (\Throwable $e) {
            error_log('Panel dispatch hatası (sipariş #' . $orderId . '): ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Hedef panele bağlanılamadı: ' . $e->getMessage()], 502);
        }

        if (!is_array($remoteBody) || empty($remoteBody['success'])) {
            $msg = is_array($remoteBody) ? (string) ($remoteBody['error'] ?? $remoteBody['message'] ?? 'Bilinmeyen hata') : 'Geçersiz yanıt';
            error_log('Panel dispatch reddedildi (sipariş #' . $orderId . ' → ' . $panel['key'] . '): ' . $msg);
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Hedef panel siparişi kabul etmedi: ' . $msg], 422);
        }

        // Kaynak sipariş taşındı → "taşındı" durumuna al; hedef panel adını sakla (lockedBy).
        $order->setStatus(EmailOrderStatus::TRANSFERRED);
        $order->setLockedBy($panel['label']);
        $this->em->flush();

        $remoteId = $remoteBody['order_id'] ?? '?';
        error_log('Panel dispatch başarılı: sipariş #' . $orderId . ' → ' . $panel['key'] . ' (uzak #' . $remoteId . ')');

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => $panel['label'] . ' paneline taşındı (uzak sipariş #' . $remoteId . '). Kaynak sipariş iptal edildi; hedef panelde onaylayın.',
            'remote_order_id' => $remoteBody['order_id'] ?? null,
        ]);
    }

    /**
     * Kampanyayı kaldığı yerden başka panele taşı (kalan alıcılarla) ve kaynaktan sil.
     * POST /admin/email-orders/{id}/transfer-resume
     */
    public function transferResumeToPanel(Request $request, Response $response, array $args): Response
    {
        @set_time_limit(300);
        $orderId = (int) ($args['id'] ?? 0);
        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload)) {
            $payload = $request->getParsedBody() ?: [];
        }
        $targetKey = trim((string) ($payload['panel'] ?? $payload['target'] ?? ''));

        $order = $this->em->find(EmailOrder::class, $orderId);
        if (!$order) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Sipariş bulunamadı.'], 404);
        }

        $allowed = [EmailOrderStatus::PENDING, EmailOrderStatus::PROCESSING, EmailOrderStatus::PENDING_APPROVAL];
        if (!in_array($order->getStatus(), $allowed, true)) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Bu durumdaki sipariş taşınamaz (yalnızca bekleyen/işlenen/onay bekleyen).'], 422);
        }

        $registry = new \App\Application\Services\PanelRegistryService();
        $panel = $registry->get($targetKey);
        if (!$panel) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Hedef panel bulunamadı (SIBLING_PANELS).'], 400);
        }

        $conn = $this->em->getConnection();

        // Kaynağı kilitle — worker yeni batch almasın
        $prevStatus = $order->getStatus();
        $order->setStatus(EmailOrderStatus::TRANSFERRING);
        $this->em->flush();

        // Kalan (gönderilmemiş) alıcıları topla: pending satırlar + havuzda çekilmemişler
        $emailMap = [];
        foreach ($conn->fetchFirstColumn("SELECT email FROM email_order_emails WHERE order_id = ? AND status = 'pending'", [$orderId]) as $e) {
            $e = strtolower(trim((string) $e));
            if ($e !== '') { $emailMap[$e] = true; }
        }
        $poolListId = $order->getPoolList()?->getId();
        if ($order->getSourceType() === 'pool' && $poolListId) {
            $cursor = (int) ($order->getLastPoolId() ?? 0);
            foreach ($conn->fetchFirstColumn(
                "SELECT email FROM email_data_pool WHERE is_active = 1 AND pool_list_id = ? AND id > ? ORDER BY id ASC",
                [$poolListId, $cursor]
            ) as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '') { $emailMap[$e] = true; }
            }
        }
        $emailList = array_keys($emailMap);
        $remaining = count($emailList);

        if ($remaining < 1) {
            $order->setStatus($prevStatus);
            $this->em->flush();
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Taşınacak gönderilmemiş alıcı yok.'], 422);
        }

        $client = new \GuzzleHttp\Client(['timeout' => 60, 'http_errors' => false]);
        $hdr = ['headers' => ['X-Api-Token' => $panel['token'], 'Accept' => 'application/json']];
        $srcPanel = (string) ($_ENV['SITE_URL'] ?? $_ENV['APP_URL'] ?? 'unknown');

        // ADIM 1 — init
        try {
            $r = $client->post($panel['url'] . '/api/email-orders/transfer-resume/init', $hdr + ['json' => [
                'subject' => $order->getSubject(),
                'body' => $order->getBody(),
                'total' => $remaining,
                'delivery_percentage' => $order->getDeliveryPercentage(),
                'smtp_rotation_limit' => $order->getSmtpRotationLimit(),
                'owner_username' => $_SESSION['user']['username'] ?? null,
                'source_panel' => $srcPanel,
                'source_order_id' => $orderId,
            ]]);
            $rb = json_decode((string) $r->getBody(), true);
        } catch (\Throwable $e) {
            $order->setStatus($prevStatus); $this->em->flush();
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Hedef panele bağlanılamadı: ' . $e->getMessage() . ' (kaynak korundu)'], 502);
        }
        if (!is_array($rb) || empty($rb['success']) || empty($rb['order_id'])) {
            $order->setStatus($prevStatus); $this->em->flush();
            $msg = is_array($rb) ? (string) ($rb['error'] ?? 'init reddedildi') : 'geçersiz yanıt';
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Hedef init reddetti: ' . $msg . ' (kaynak korundu)'], 422);
        }
        $remoteId = (int) $rb['order_id'];

        // ADIM 2 — alıcıları batch batch aktar, ADIM 3 — aktive et
        try {
            foreach (array_chunk($emailList, 5000) as $chunk) {
                $r = $client->post($panel['url'] . '/api/email-orders/transfer-resume/' . $remoteId . '/emails', $hdr + ['json' => ['emails' => $chunk]]);
                $rb = json_decode((string) $r->getBody(), true);
                if (!is_array($rb) || empty($rb['success'])) {
                    throw new \RuntimeException('batch reddedildi: ' . (is_array($rb) ? (string) ($rb['error'] ?? '?') : 'geçersiz yanıt'));
                }
            }
            $r = $client->post($panel['url'] . '/api/email-orders/transfer-resume/' . $remoteId . '/activate', $hdr + ['json' => []]);
            $rb = json_decode((string) $r->getBody(), true);
            if (!is_array($rb) || empty($rb['success'])) {
                throw new \RuntimeException('aktivasyon reddedildi: ' . (is_array($rb) ? (string) ($rb['error'] ?? '?') : 'geçersiz yanıt'));
            }
        } catch (\Throwable $e) {
            // Hedef yarım kaldı → kaynağı GERİ AL (silme!). Hedef order TRANSFERRING kalır, admin temizler.
            $order->setStatus($prevStatus); $this->em->flush();
            error_log('[transfer-resume] sipariş #' . $orderId . ' yarıda kesildi: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Taşıma yarıda kesildi: ' . $e->getMessage() . ' (kaynak korundu, hedefte yarım #' . $remoteId . ')'], 502);
        }

        // Hedef tamam → kaynağı SİL (önce alıcı satırları, sonra order)
        try {
            $conn->executeStatement('DELETE FROM email_order_emails WHERE order_id = ?', [$orderId]);
            $conn->executeStatement('DELETE FROM email_campaign_priority_slots WHERE campaign_id = ?', [$orderId]);
            $conn->executeStatement('DELETE FROM email_orders WHERE id = ?', [$orderId]);
            $this->em->clear();
        } catch (\Throwable $e) {
            error_log('[transfer-resume] kaynak silme hatası #' . $orderId . ': ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => true, 'message' => $panel['label'] . ' paneline taşındı (#' . $remoteId . ', ' . $remaining . ' alıcı) ama kaynak silinemedi: ' . $e->getMessage()], 200);
        }

        error_log('[transfer-resume] sipariş #' . $orderId . ' → ' . $panel['key'] . ' #' . $remoteId . ' (' . $remaining . ' alıcı), kaynak silindi');
        return $this->jsonResponse($response, [
            'success' => true,
            'message' => $panel['label'] . ' paneline taşındı ve kaldığı yerden başlatıldı (' . $remaining . ' alıcı, uzak #' . $remoteId . '). Kaynak silindi.',
            'remote_order_id' => $remoteId,
            'moved' => $remaining,
        ]);
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function translateExternalApiError(array $result): string
    {
        $normalizedMessage = trim((string) ($result['user_message'] ?? ''));
        if ($normalizedMessage !== '') {
            return $normalizedMessage;
        }

        $status = (int) ($result['status'] ?? 500);
        $message = strtolower(trim((string) ($result['message'] ?? '')));

        if ($message === 'timeout' || str_contains($message, 'timed out')) {
            return 'Bakiye API zaman aşımına uğradı.';
        }
        if ($status === 0) {
            return 'Bakiye API bağlantısı kurulamadı.';
        }
        if (str_contains($message, 'insufficient mail balance')) {
            return 'Seçilen müşterinin mail bakiyesi yetersiz.';
        }
        if (str_contains($message, 'validation failed')) {
            return 'Bakiye işlemi doğrulanamadı.';
        }
        if (str_contains($message, 'unauthorized')) {
            return 'Bakiye API anahtarı geçersiz.';
        }
        if (str_contains($message, 'content-type')) {
            return 'API isteği JSON formatında gönderilmelidir.';
        }
        if (str_contains($message, 'too many requests')) {
            return 'Bakiye API limitine ulaşıldı. Lütfen biraz sonra tekrar deneyin.';
        }
        if (str_contains($message, 'user not found')) {
            return 'Seçilen müşteri bulunamadı.';
        }
        if ($status === 401) {
            return 'Bakiye API anahtarı geçersiz.';
        }
        if ($status === 503) {
            return 'Bakiye API yapılandırması eksik.';
        }
        if ($status === 415) {
            return 'API isteği JSON formatında gönderilmelidir.';
        }
        if ($status === 429) {
            return 'Bakiye API limitine ulaşıldı. Lütfen biraz sonra tekrar deneyin.';
        }
        if ($status === 404) {
            return 'Seçilen müşteri bulunamadı.';
        }
        if ($status === 422 && str_contains($message, 'insufficient')) {
            return 'Seçilen müşterinin mail bakiyesi yetersiz.';
        }
        if ($status === 422) {
            return 'Bakiye işlemi doğrulanamadı.';
        }
        if ($status >= 500) {
            return 'Bakiye API tarafında sunucu hatası oluştu.';
        }

        return 'Bakiye API işleminde hata oluştu.';
    }

    private function insertApprovalLog(array $payload, ?\Doctrine\DBAL\Connection $connection = null): void
    {
        try {
            $conn = $connection ?: $this->em->getConnection();
            $conn->insert('email_order_approval_logs', [
                'order_id' => (int) ($payload['order_id'] ?? 0),
                'admin_user_id' => isset($payload['admin_user_id']) ? (int) $payload['admin_user_id'] : null,
                'external_customer_id' => (int) ($payload['external_customer_id'] ?? 0),
                'external_customer_name' => (string) ($payload['external_customer_name'] ?? ''),
                'external_customer_email' => (string) ($payload['external_customer_email'] ?? ''),
                'selected_data_list_id' => isset($payload['selected_data_list_id']) ? (int) $payload['selected_data_list_id'] : null,
                'selected_data_list_name' => (string) ($payload['selected_data_list_name'] ?? ''),
                'order_total' => (int) ($payload['order_total'] ?? 0),
                'balance_old_amount' => isset($payload['balance_old_amount']) ? (int) $payload['balance_old_amount'] : null,
                'balance_amount' => (int) ($payload['balance_amount'] ?? 0),
                'balance_new_amount' => isset($payload['balance_new_amount']) ? (int) $payload['balance_new_amount'] : null,
                'status' => (string) ($payload['status'] ?? 'failed'),
                'error_message' => $payload['error_message'] ?? null,
                'api_response' => json_encode($payload['api_response'] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('email_order_approval_logs insert failed: ' . $e->getMessage());
        }
    }

    private function getLatestApprovalLog(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        try {
            $row = $this->em->getConnection()->executeQuery(
                "SELECT l.*, u.name AS admin_name
                 FROM email_order_approval_logs l
                 LEFT JOIN users u ON u.id = l.admin_user_id
                 WHERE l.order_id = ?
                 ORDER BY l.id DESC
                 LIMIT 1",
                [$orderId]
            )->fetchAssociative();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        return [
            'external_customer_id' => (int) ($row['external_customer_id'] ?? 0),
            'external_customer_name' => (string) ($row['external_customer_name'] ?? ''),
            'external_customer_email' => (string) ($row['external_customer_email'] ?? ''),
            'selected_data_list_id' => isset($row['selected_data_list_id']) ? (int) $row['selected_data_list_id'] : null,
            'selected_data_list_name' => (string) ($row['selected_data_list_name'] ?? ''),
            'balance_old_amount' => isset($row['balance_old_amount']) ? (int) $row['balance_old_amount'] : null,
            'balance_amount' => (int) ($row['balance_amount'] ?? 0),
            'balance_new_amount' => isset($row['balance_new_amount']) ? (int) $row['balance_new_amount'] : null,
            'status' => (string) ($row['status'] ?? ''),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'admin_name' => (string) ($row['admin_name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function loadPoolListsPayload(): array
    {
        $listEntities = $this->em->createQueryBuilder()
            ->select('l')
            ->from(EmailDataPoolList::class, 'l')
            ->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $poolListsPayload = [];
        foreach ($listEntities as $listEntity) {
            // Fast path: use precomputed counters on list table to avoid heavy GROUP BY on large pools.
            $activeCount = (int) $listEntity->getActiveCount();
            $poolListsPayload[] = [
                'id' => $listEntity->getId(),
                'name' => $listEntity->getName(),
                'active_count' => $activeCount,
            ];
        }
        $defaultPoolListId = $poolListsPayload[0]['id'] ?? null;

        return [$poolListsPayload, $defaultPoolListId];
    }

    private function notifyTelegramSafely(string $eventType, ?EmailOrder $order, array $context = []): void
    {
        try {
            $this->telegramNotificationService->notifyEvent($eventType, $order, $context);
        } catch (\Throwable $e) {
            error_log('Telegram notify error (' . $eventType . '): ' . $e->getMessage());
        }
    }

    private function buildSchemaAwareApprovalErrorMessage(\Throwable $e): ?string
    {
        $raw = trim((string) $e->getMessage());
        if ($raw === '') {
            return null;
        }

        $lower = strtolower($raw);
        $schemaKeywords = [
            'worker_paused',
            'worker_stop_requested',
            'campaign_batch_metrics',
            'campaign_runtime_summary',
            'unknown column',
            "doesn't exist",
        ];

        $matched = false;
        foreach ($schemaKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return null;
        }

        $missing = $this->detectMissingWorkerSchemaItems();
        if (!empty($missing)) {
            return 'Veritabanı migration eksik: ' . implode(', ', $missing) . '. Lütfen `bash bin/run-doctrine-migrations.sh` komutunu çalıştırın.';
        }

        return 'Veritabanı migration eksik veya worker şeması güncel değil. Lütfen `bash bin/run-doctrine-migrations.sh` komutunu çalıştırın.';
    }

    private function detectMissingWorkerSchemaItems(): array
    {
        try {
            $conn = $this->em->getConnection();
            $dbName = (string) ($conn->getDatabase() ?: $conn->fetchOne('SELECT DATABASE()'));
            $missing = [];

            $requiredColumns = [
                'email_orders' => ['worker_paused', 'worker_stop_requested'],
            ];
            foreach ($requiredColumns as $table => $columns) {
                foreach ($columns as $column) {
                    $exists = (int) $conn->fetchOne(
                        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$dbName, $table, $column]
                    ) > 0;
                    if (!$exists) {
                        $missing[] = $table . '.' . $column;
                    }
                }
            }

            foreach (['campaign_batch_metrics', 'campaign_runtime_summary'] as $table) {
                $exists = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$dbName, $table]
                ) > 0;
                if (!$exists) {
                    $missing[] = $table;
                }
            }

            return $missing;
        } catch (\Throwable) {
            return [];
        }
    }
}

