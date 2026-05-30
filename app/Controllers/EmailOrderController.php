<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\EmailOrder;
use App\Domain\Entities\EmailOrderEmail;
use App\Domain\Entities\EmailPhonebook;
use App\Domain\Entities\EmailDataPool;
use App\Domain\Entities\EmailTemplate;
use App\Domain\Entities\User;
use App\Domain\Enum\EmailOrderStatus;
use App\Support\EnumHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailOrderController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    /**
     * Sipariş listesi
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $userId = (int) ($_SESSION['user']['id'] ?? 0);
            if ($userId < 1) {
                return $response->withHeader('Location', '/login')->withStatus(302);
            }
            $user = $this->em->find(User::class, $userId);
            if (!$user) {
                return $response->withHeader('Location', '/login')->withStatus(302);
            }
            $params = $request->getQueryParams();

            $page = max(1, (int) ($params['page'] ?? 1));
            $q = trim((string) ($params['q'] ?? ''));
            $status = trim((string) ($params['status'] ?? ''));
            $perPage = (int) ($params['per_page'] ?? 20);
            $allowedPerPage = [20, 50, 100];
            if (!in_array($perPage, $allowedPerPage, true)) {
                $perPage = 20;
            }

            $listQb = $this->em->createQueryBuilder();
            $listQb->select('o', 't')
                ->from(EmailOrder::class, 'o')
                ->leftJoin('o.template', 't')
                ->where('o.user = :user')
                ->setParameter('user', $user);

            if ($q !== '') {
                $normalizedQ = function_exists('mb_strtolower')
                    ? mb_strtolower($q, 'UTF-8')
                    : strtolower($q);
                $listQb
                    ->andWhere('(LOWER(o.subject) LIKE :q OR CONCAT(\'\', o.id) LIKE :q OR LOWER(o.status) LIKE :q)')
                    ->setParameter('q', '%' . $normalizedQ . '%');
            }

            if ($status !== '') {
                if ($status === 'pending') {
                    $listQb->andWhere('o.status IN (:statusPending)')
                        ->setParameter('statusPending', ['pending_approval', 'pending']);
                } elseif ($status === 'sent') {
                    $listQb->andWhere('o.status IN (:statusCompleted)')
                        ->setParameter('statusCompleted', ['sent', 'completed']);
                } elseif ($status === 'other') {
                    $listQb->andWhere('o.status NOT IN (:statusKnown)')
                        ->setParameter('statusKnown', ['pending_approval', 'pending', 'processing', 'sent', 'completed', 'failed']);
                } else {
                    $listQb->andWhere('o.status = :status')
                        ->setParameter('status', $status);
                }
            }

            $countQb = clone $listQb;
            $total = (int) $countQb
                ->select('COUNT(o.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $orders = $listQb
                ->orderBy('o.createdAt', 'DESC')
                ->setFirstResult(($page - 1) * $perPage)
                ->setMaxResults($perPage)
                ->getQuery()
                ->getResult();
            $templateMetaByOrderId = $this->buildFallbackTemplateMapForOrders($orders);

            // Email Blacklist sayısını al
            $blacklistCount = $this->em->createQueryBuilder()
                ->select('COUNT(eb.id)')
                ->from(\App\Domain\Entities\EmailBlacklist::class, 'eb')
                ->where('eb.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->getSingleScalarResult();

            // Rehberleri çek (sipariş oluşturma için)
            $qb2 = $this->em->createQueryBuilder();
            $phonebooks = $qb2->select('p')
                ->from(EmailPhonebook::class, 'p')
                ->where('p.user = :user')
                ->setParameter('user', $user)
                ->orderBy('p.title', 'ASC')
                ->getQuery()
                ->getResult();

            // Mail şablonlarını çek (sadece onaylı şablonlar sipariş oluşturmada kullanılabilir)
            $qb3 = $this->em->createQueryBuilder();
            $templates = $qb3->select('t')
                ->from(\App\Domain\Entities\EmailTemplate::class, 't')
                // Kullanıcı kendi şablonlarını (onay beklese de) görebilir; global şablonlar onaylı olmalı
                ->where('(t.user = :user) OR (t.isGlobal = true AND t.isApproved = true)')
                ->setParameter('user', $user)
                ->orderBy('t.name', 'ASC')
                ->getQuery()
                ->getResult();

            $html = $this->twig->render('email-orders/index.twig', [
                'orders' => $orders,
                'template_meta_by_order_id' => $templateMetaByOrderId,
                'phonebooks' => $phonebooks,
                'templates' => $templates,
                'q' => $q,
                'selected_status' => $status,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'email_credit' => $user->getEmailCredit() ?? 0,
                'blacklist_count' => $blacklistCount,
                'success' => $_SESSION['success'] ?? null,
                'error' => $_SESSION['error'] ?? null,
                'flash_icon' => $_SESSION['flash_icon'] ?? null
            ]);

            // Session mesajlarını temizle
            unset($_SESSION['success'], $_SESSION['error'], $_SESSION['flash_icon']);

            $response->getBody()->write($html);
            return $response;
        } catch (\Throwable $e) {
            error_log('EmailOrderController::index error: ' . $e->getMessage());
            $_SESSION['error'] = 'Siparişler yüklenirken geçici bir sorun oluştu. Lütfen tekrar deneyin.';
            $_SESSION['flash_icon'] = 'alert-circle';

            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }
    }


    /**
     * Sipariş oluşturma
     */
    public function store(Request $request, Response $response): Response
    {
        // Form verisi: $_POST ve getParsedBody birleştir (biri boş olabilir)
        $post = is_array($_POST ?? null) ? $_POST : [];
        $parsed = $request->getParsedBody();
        $parsed = is_array($parsed) ? $parsed : [];
        // İkisini birleştir - dolu olan öncelikli (post_max_size aşımında $_POST boş olabilir)
        $data = array_merge($post, $parsed);
        if (empty($data)) {
            $_SESSION['error'] = 'Form verisi alınamadı. Sayfayı yenileyip tekrar deneyin. (Mail içeriği çok uzunsa sunucu post_max_size limitini aşmış olabilir)';
            $_SESSION['flash_icon'] = 'alert-circle';
            return $response->withHeader('Location', '/email-orders')->withStatus(302);
        }
        $user = $this->em->find(User::class, $_SESSION['user']['id']);

        try {
            // Email listesini topla
            $emails = [];

            // Rehberden - phonebook_ids veya phonebook_ids[] (farklı parser'lardan gelebilir)
            $phonebookIds = $data['phonebook_ids'] ?? $data['phonebook_ids[]'] ?? [];
            if (!is_array($phonebookIds)) {
                $phonebookIds = $phonebookIds !== '' ? [(int) $phonebookIds] : [];
            }

            // send_type veriden çıkar (form parse sorunlarına karşı dayanıklı)
            $sendType = trim((string) ($data['send_type'] ?? ''));
            if ($sendType === '') {
                $sendType = !empty($phonebookIds) ? 'phonebook'
                    : (!empty(trim((string) ($data['manual_emails'] ?? ''))) ? 'manual'
                    : ((int) ($data['pool_count'] ?? 0) > 0 ? 'pool' : 'manual'));
            }

            // Phonebook ve Manual için template_id zorunlu (body POST'ta gönderilmez, post_max_size önlenir)
            $templateId = (int) ($data['template_id'] ?? 0);
            $orderTemplate = null;
            if (in_array($sendType, ['phonebook', 'manual']) && $templateId > 0) {
                $orderTemplate = $this->em->find(EmailTemplate::class, $templateId);
                if (!$orderTemplate) {
                    $_SESSION['error'] = 'Geçersiz şablon seçildi.';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
                if ($orderTemplate->isGlobal() && !$orderTemplate->isApproved()) {
                    $_SESSION['error'] = 'Seçilen global şablon henüz onaylanmamış.';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
                if (!$orderTemplate->isGlobal() && $orderTemplate->getUser() && $orderTemplate->getUser()->getId() !== $user->getId()) {
                    $_SESSION['error'] = 'Bu şablona erişim yetkiniz yok.';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
            }
            if (in_array($sendType, ['phonebook', 'manual']) && $templateId <= 0) {
                $_SESSION['error'] = 'Lütfen bir mail şablonu seçin.';
                $_SESSION['flash_icon'] = 'alert-circle';
                return $response->withHeader('Location', '/email-orders')->withStatus(302);
            }

            if ($sendType === 'phonebook' && !empty($phonebookIds)) {
                foreach ($phonebookIds as $phonebookId) {
                    $phonebook = $this->em->find(EmailPhonebook::class, (int) $phonebookId);
                    if ($phonebook && $phonebook->getUser()->getId() === $user->getId()) {
                        $qb = $this->em->createQueryBuilder();
                        $contacts = $qb->select('c')
                            ->from(\App\Domain\Entities\EmailContact::class, 'c')
                            ->where('c.phonebook = :phonebook')
                            ->setParameter('phonebook', $phonebook)
                            ->getQuery()
                            ->getResult();

                        foreach ($contacts as $contact) {
                            $emails[$contact->getEmail()] = $contact->getName();
                        }
                    }
                }
            }

            // Manuel email listesi (manual_emails veya manual_emails[])
            $manualEmailsRaw = $data['manual_emails'] ?? $data['manual_emails[]'] ?? '';
            if ($sendType === 'manual' && !empty(trim((string) $manualEmailsRaw))) {
                $manualEmails = $this->parseEmails((string) $manualEmailsRaw);
                foreach ($manualEmails as $emailData) {
                    $emails[$emailData['email']] = $emailData['name'] ?? null;
                }
            }

            // Sistem Havuzundan - Worker havuzdan çekecek, form sadece sayı+şablon gönderir (post_max_size sorunu önlenir)
            $poolRequestedCount = 0;
            $poolTemplate = null;
            $poolCountRaw = $data['pool_count'] ?? $data['pool_count[]'] ?? 0;
            if ($sendType === 'pool' && (int) $poolCountRaw > 0) {
                $poolRequestedCount = (int) $poolCountRaw;
                
                if ($poolRequestedCount <= 0) {
                    $_SESSION['error'] = 'Geçersiz mail sayısı';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }

                // Bakiye kontrolü
                $emailCredit = $user->getEmailCredit() ?? 0;
                if ($poolRequestedCount > $emailCredit) {
                    $_SESSION['error'] = "Yetersiz mail kredisi. İstenen: {$poolRequestedCount}, Mevcut: {$emailCredit}";
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }

                // Havuzda yeterli mail var mı kontrol et (sadece sayı, çekme)
                $totalPoolCount = $this->em->createQueryBuilder()
                    ->select('COUNT(p.id)')
                    ->from(EmailDataPool::class, 'p')
                    ->where('p.isActive = :active')
                    ->setParameter('active', true)
                    ->getQuery()
                    ->getSingleScalarResult();
                
                if ($totalPoolCount < $poolRequestedCount) {
                    $_SESSION['error'] = 'Sistem havuzunda yeterli mail yok';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
                // Pool: template_id zorunlu (body POST'ta gönderilmez, post_max_size önlenir)
                $templateId = (int) ($data['template_id'] ?? 0);
                if ($templateId <= 0) {
                    $_SESSION['error'] = 'Sistem Havuzu için lütfen bir mail şablonu seçin.';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
                $template = $this->em->find(EmailTemplate::class, $templateId);
                if (!$template) {
                    $_SESSION['error'] = 'Geçersiz şablon seçildi.';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
                if ($template->isGlobal() && !$template->isApproved()) {
                    $_SESSION['error'] = 'Seçilen global şablon henüz onaylanmamış.';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
                if (!$template->isGlobal() && $template->getUser() && $template->getUser()->getId() !== $user->getId()) {
                    $_SESSION['error'] = 'Bu şablona erişim yetkiniz yok.';
                    $_SESSION['flash_icon'] = 'alert-circle';
                    return $response->withHeader('Location', '/email-orders')->withStatus(302);
                }
                // Pool için body ve subject şablondan/formdan alınacak (store'da kullanılacak)
                $poolTemplate = $template;
                // Pool için EmailOrderEmail oluşturulmaz - Worker havuzdan çekecek
            }

            if (empty($emails) && $poolRequestedCount <= 0) {
                $errorMsg = empty($data)
                    ? 'Form verisi alınamadı. Sayfayı yenileyip tekrar deneyin. (Mail içeriği çok uzunsa sunucu post_max_size limitini aşmış olabilir)'
                    : match ($sendType) {
                        'phonebook' => 'Lütfen en az bir rehber seçin ve rehberde mail adresi olduğundan emin olun.',
                        'manual' => 'Lütfen geçerli mail adresleri girin (her satıra bir mail).',
                        'pool' => 'Sistem havuzunda yeterli mail bulunamadı.',
                        default => 'En az bir mail adresi gerekli. Rehber seçin, manuel girin veya sistem havuzundan seçin.',
                    };
                $_SESSION['error'] = $errorMsg;
                $_SESSION['flash_icon'] = 'alert-circle';
                return $response->withHeader('Location', '/email-orders')->withStatus(302);
            }

            // Bakiye kontrolü
            $totalEmails = $poolRequestedCount > 0 ? $poolRequestedCount : count($emails);
            $emailCredit = $user->getEmailCredit() ?? 0;

            if ($totalEmails > $emailCredit) {
                $_SESSION['error'] = "Yetersiz mail kredisi. Gerekli: {$totalEmails}, Mevcut: {$emailCredit}";
                $_SESSION['flash_icon'] = 'alert-circle';
                return $response->withHeader('Location', '/email-orders')->withStatus(302);
            }

            // Sipariş oluştur - Admin onayına düşer (bakiye onayda kesilir)
            $order = new EmailOrder();
            $order->setUser($user);
            // Tüm send_type'lar: subject ve body şablondan (POST'ta gönderilmez, post_max_size önlenir)
            $bodyTemplate = $poolTemplate ?? $orderTemplate;
            if ($bodyTemplate) {
                $order->setSubject($bodyTemplate->getSubject());
                $order->setBody($bodyTemplate->getBody());
                $order->setTemplate($bodyTemplate);
            } else {
                $order->setSubject($data['subject'] ?? '');
                $order->setBody($data['body'] ?? '');
                $order->setTemplate(null);
            }
            $order->setTotal($totalEmails);
            $order->setCost($totalEmails);
            $order->setStatus(EmailOrderStatus::PENDING_APPROVAL);

            // Pool siparişleri: Worker havuzdan çekecek
            if ($poolRequestedCount > 0) {
                $order->setSourceType('pool');
            }

            // Delivery percentage (kampanya için 100%, kullanıcı yüzdesi ayrıca uygulanır)
            $order->setDeliveryPercentage(100);

            // Siparişi persist et
            $this->em->persist($order);
            $this->em->flush();
            
            // Pool dışı siparişler için EmailOrderEmail oluştur (Pool: Worker havuzdan çekecek)
            if ($poolRequestedCount <= 0 && !empty($emails)) {
                $orderId = $order->getId();
                $batchSize = 500;
                $count = 0;
                
                foreach ($emails as $email => $name) {
                    if ($count % $batchSize === 0) {
                        $orderRef = $this->em->getReference(EmailOrder::class, $orderId);
                    }
                    
                    $orderEmail = new EmailOrderEmail();
                    $orderEmail->setEmail($email);
                    $orderEmail->setName($name);
                    $orderEmail->setOrder($orderRef);
                    $this->em->persist($orderEmail);
                    
                    $count++;
                    if ($count % $batchSize === 0) {
                        $this->em->flush();
                        $this->em->clear(EmailOrderEmail::class);
                    }
                }
                
                if ($count % $batchSize !== 0) {
                    $this->em->flush();
                }
            }

            $_SESSION['success'] = "Sipariş oluşturuldu! Admin onayından sonra {$totalEmails} mail gönderilecek.";
            $_SESSION['flash_icon'] = 'check-circle';

            return $response
                ->withHeader('Location', '/email-orders')
                ->withStatus(302);

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Sipariş oluşturulurken hata: ' . $e->getMessage();
            $_SESSION['flash_icon'] = 'x-circle';
            
            return $response
                ->withHeader('Location', '/email-orders')
                ->withStatus(302);
        }
    }

    /**
     * Sipariş detayı (AJAX için JSON)
     */
    public function getDetails(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->em->find(User::class, $_SESSION['user']['id']);
            $orderId = (int) $args['id'];
            
            if ($orderId <= 0) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Geçersiz sipariş ID'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $order = $this->em->find(EmailOrder::class, $orderId);

            if (!$order || $order->getUser()->getId() !== $user->getId()) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Sipariş bulunamadı'
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            // Email listesi - Sistem havuzundan gönderilen siparişlerde gizli
            $emails = [];
            $isPoolOrder = false;
            
            // Toplam email sayısını al
            $totalEmailCount = $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from(EmailOrderEmail::class, 'e')
                ->where('e.order = :order')
                ->setParameter('order', $order)
                ->getQuery()
                ->getSingleScalarResult();
            
            // Sistem havuzu: source_type=pool veya ilk email name='___SYSTEM_POOL___' (eski siparişler)
            $isPoolOrder = $order->isPoolOrder();
            if (!$isPoolOrder) {
                $firstEmail = $this->em->createQueryBuilder()
                    ->select('e')
                    ->from(EmailOrderEmail::class, 'e')
                    ->where('e.order = :order')
                    ->setParameter('order', $order)
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
                $isPoolOrder = ($firstEmail && $firstEmail->getName() === '___SYSTEM_POOL___');
            }
            
            if (!$isPoolOrder) {
                try {
                    // Tüm email'leri getir (limit yok)
                    $emails = $this->em->createQueryBuilder()
                        ->select('e')
                        ->from(EmailOrderEmail::class, 'e')
                        ->where('e.order = :order')
                        ->setParameter('order', $order)
                        ->orderBy('e.id', 'ASC')
                        ->getQuery()
                        ->getResult();
                } catch (\Exception $e) {
                    $emails = [];
                }
            }

            $emailData = array_map(function($email) {
                return [
                    'email' => $email->getEmail(),
                    'status' => EnumHelper::normalizeEnumValue($email->getStatus()) ?: 'pending',
                    'sentAt' => $email->getSentAt() ? $email->getSentAt()->format('d.m.Y H:i:s') : null,
                    'deliveredAt' => $email->getDeliveredAt() ? $email->getDeliveredAt()->format('d.m.Y H:i:s') : null,
                    'errorMessage' => $email->getError() ?? null
                ];
            }, $emails);

            $data = [
                'success' => true,
                'order' => [
                    'id' => $order->getId(),
                    'user' => [
                        'name' => $order->getUser()->getName(),
                        'email' => $order->getUser()->getEmail()
                    ],
                    'subject' => $order->getSubject(),
                    'template' => $order->getTemplate() ? [
                        'id' => $order->getTemplate()->getId(),
                        'name' => $order->getTemplate()->getName(),
                    ] : null,
                    'body' => $order->getBody(),
                    'status' => EnumHelper::normalizeEnumValue($order->getStatus()),
                    'total' => $order->getTotal(),
                    'sent' => $order->getSent(),
                    'delivered' => $order->getDelivered(),
                    'bounced' => $order->getBounced() ?? 0,
                    'failed' => $order->getFailed(),
                    'cost' => $order->getCost() ?? 0,
                    'createdAt' => $order->getCreatedAt()->format('d.m.Y H:i:s'),
                    'completedAt' => $order->getCompletedAt() ? $order->getCompletedAt()->format('d.m.Y H:i:s') : null
                ],
                'emails' => $emailData,
                'emailCount' => count($emails),
                'isPoolOrder' => $isPoolOrder,
                'totalEmailCount' => $totalEmailCount
            ];

            $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log('Email Order Details Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Sipariş detayları yüklenirken bir hata oluştu',
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Email listesini parse et
     */
    private function buildFallbackTemplateMapForOrders(array $orders): array
    {
        $subjects = [];
        foreach ($orders as $order) {
            if (!$order instanceof EmailOrder || $order->getTemplate()) {
                continue;
            }
            $subject = trim((string) $order->getSubject());
            if ($subject !== '') {
                $subjects[] = $subject;
            }
        }

        $subjects = array_values(array_unique($subjects));
        if ($subjects === []) {
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

        $subjectMap = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeSubject((string) ($row['subject'] ?? ''));
            if ($normalized === '' || isset($subjectMap[$normalized])) {
                continue;
            }
            $subjectMap[$normalized] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['name'] ?? '')),
            ];
        }

        $result = [];
        foreach ($orders as $order) {
            if (!$order instanceof EmailOrder || $order->getTemplate()) {
                continue;
            }
            $normalized = $this->normalizeSubject((string) $order->getSubject());
            if (isset($subjectMap[$normalized])) {
                $result[$order->getId()] = $subjectMap[$normalized];
            }
        }

        return $result;
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($subject);
        }

        return strtolower($subject);
    }

    /**
     * Email listesini parse et
     */
    private function parseEmails(string $emailsText): array
    {
        $emails = [];
        // Satır sonları: \n, \r\n, \r ve noktalı virgül ile ayrılmış
        $lines = preg_split('/[\r\n;]+/', $emailsText, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (filter_var($line, FILTER_VALIDATE_EMAIL)) {
                $emails[] = ['email' => $line, 'name' => null];
            } elseif (strpos($line, ',') !== false) {
                $parts = explode(',', $line, 2);
                $email = trim($parts[0]);
                $name = trim($parts[1] ?? '');

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = ['email' => $email, 'name' => $name ?: null];
                }
            }
        }

        return array_unique($emails, SORT_REGULAR);
    }

    /**
     * /email-orders/{id} linklerini modal ile göster
     */
    public function redirectToList(Request $request, Response $response, array $args): Response
    {
        $userId = $_SESSION['user']['id'] ?? null;
        $order = $this->em->find(EmailOrder::class, $args['id']);

        if (!$order || $order->getUser()->getId() !== $userId) {
            return $response->withStatus(404);
        }

        // Modal ile açmak için ?modal parametresi ile yönlendir
        return $response
            ->withHeader('Location', '/email-orders?modal=' . $args['id'])
            ->withStatus(302);
    }
}

