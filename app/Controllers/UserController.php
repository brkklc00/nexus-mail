<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\User;
use App\Domain\Entities\Role;
use App\Domain\Entities\Credit;
use App\Infrastructure\Security\PasswordHasher;
use App\Application\Services\AuditLoggerService;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

class UserController
{
    private EntityManager $em;
    private Environment $twig;
    private PasswordHasher $passwordHasher;
    private AuditLoggerService $auditLogger;

    public function __construct(
        EntityManager $em,
        Environment $twig,
        PasswordHasher $passwordHasher,
        AuditLoggerService $auditLogger
    ) {
        $this->em = $em;
        $this->twig = $twig;
        $this->passwordHasher = $passwordHasher;
        $this->auditLogger = $auditLogger;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 25);
        $offset = ($page - 1) * $perPage;

        // Filtreleme parametreleri
        $search = $params['search'] ?? '';
        $statusFilter = $params['status'] ?? 'all';
        $roleFilter = $params['role'] ?? 'all';
        $sortBy = $params['sort'] ?? 'createdAt';
        $sortDir = $params['dir'] ?? 'DESC';

        // Base query builder
        $qb = $this->em->createQueryBuilder();
        $qb->select('u', 'r', 'c')
           ->from(User::class, 'u')
           ->leftJoin('u.roles', 'r')
           ->leftJoin('u.credit', 'c');

        // Arama filtresi
        if (!empty($search)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('u.name', ':search'),
                    $qb->expr()->like('u.username', ':search'),
                    $qb->expr()->like('u.email', ':search')
                )
            );
            $qb->setParameter('search', '%' . $search . '%');
        }

        // Durum filtresi
        if ($statusFilter === 'active') {
            $qb->andWhere('u.isActive = :active')
               ->setParameter('active', true);
        } elseif ($statusFilter === 'inactive') {
            $qb->andWhere('u.isActive = :active')
               ->setParameter('active', false);
        }

        // Rol filtresi
        if ($roleFilter !== 'all' && is_numeric($roleFilter)) {
            $qb->andWhere(':roleId MEMBER OF u.roles')
               ->setParameter('roleId', (int)$roleFilter);
        }

        // Count query (filtreleme sonrası)
        $countQb = clone $qb;
        $countQb->select('COUNT(DISTINCT u.id)');
        $total = $countQb->getQuery()->getSingleScalarResult();

        // Sıralama
        $allowedSorts = ['id', 'name', 'username', 'email', 'createdAt', 'lastLoginAt', 'emailDeliveryPercentage'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'createdAt';
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        
        $qb->orderBy('u.' . $sortBy, $sortDir);
        
        // Pagination
        $qb->setFirstResult($offset)
           ->setMaxResults($perPage);
        
        $users = $qb->getQuery()->getResult();
        $totalPages = ceil($total / $perPage);

        // İstatistikler
        $stats = $this->getUserStats();

        // Rolleri al
        $roles = $this->em->getRepository(Role::class)->findAll();

        // Current user'ı al (Account Manager kontrolü için)
        $currentUserId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $currentUser = $currentUserId ? $this->em->find(User::class, $currentUserId) : null;
        $isAccountManager = $currentUser && $currentUser->isAccountManager();

        $html = $this->twig->render('users/index.twig', [
            'users' => $users,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'perPage' => $perPage,
            'roles' => $roles,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'roleFilter' => $roleFilter,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'stats' => $stats,
            'isAccountManager' => $isAccountManager,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Kullanıcı istatistikleri
     */
    private function getUserStats(): array
    {
        $conn = $this->em->getConnection();

        // Toplam kullanıcı sayısı
        $totalUsers = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->getQuery()
            ->getSingleScalarResult();

        // Aktif kullanıcı sayısı
        $activeUsers = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        // Toplam bakiye
        $totalBalance = $this->em->createQueryBuilder()
            ->select('SUM(c.balance)')
            ->from(Credit::class, 'c')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Son 30 gün mail sayısı
        $thirtyDaysAgo = new \DateTime('-30 days');
        $stmt = $conn->prepare("
            SELECT COALESCE(COUNT(*), 0) as total_mail
            FROM email_order_emails eoe
            JOIN email_orders eo ON eo.id = eoe.email_order_id
            WHERE eo.createdAt >= :date
        ");
        $result = $stmt->executeQuery(['date' => $thirtyDaysAgo->format('Y-m-d H:i:s')]);
        $mailCount = $result->fetchAssociative()['total_mail'] ?? 0;

        return [
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'inactive_users' => $totalUsers - $activeUsers,
            'total_balance' => $totalBalance,
            'total_email_30d' => $mailCount,
        ];
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $roles = $this->em->getRepository(Role::class)->findAll();

        $html = $this->twig->render('users/create.twig', [
            'roles' => $roles,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        $user = new User();
        $user->setName($data['name'] ?? '');
        $user->setUsername($data['username'] ?? '');
        $user->setEmail($data['email'] ?? '');
        $user->setPhone($data['phone'] ?? null);
        $user->setPassword($this->passwordHasher->hash($data['password'] ?? ''));
        $user->setIsActive(isset($data['is_active']));
        $user->setEmailDeliveryPercentage((int) ($data['email_delivery_percentage'] ?? 100));
        $user->setRefundEnabled(isset($data['refund_enabled']));

        // Roller
        if (!empty($data['roles'])) {
            foreach ($data['roles'] as $roleId) {
                $role = $this->em->find(Role::class, $roleId);
                if ($role) {
                    $user->addRole($role);
                }
            }
        }

        $this->em->persist($user);
        $this->em->flush();

        // Kredi hesabı oluştur
        $credit = new Credit();
        $credit->setUser($user);
        $credit->setBalance(0);
        $this->em->persist($credit);
        $this->em->flush();

        $this->auditLogger->logCreate(
            $currentUserId,
            'User',
            $user->getId(),
            ['username' => $user->getUsername(), 'email' => $user->getEmail()]
        );

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Eager loading ile ilişkileri önceden yükle
        $qb = $this->em->createQueryBuilder();
        $qb->select('u', 'r', 'c')
           ->from(User::class, 'u')
           ->leftJoin('u.roles', 'r')
           ->leftJoin('u.credit', 'c')
           ->where('u.id = :id')
           ->setParameter('id', $args['id']);
        
        $user = $qb->getQuery()->getOneOrNullResult();

        if (!$user) {
            $response->getBody()->write('<div class="alert alert-danger">Kullanıcı bulunamadı.</div>');
            return $response->withStatus(404);
        }

        // Modal için basit içerik render et
        $html = $this->twig->render('users/_detail.twig', [
            'user' => $user,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            return $response->withStatus(404);
        }

        $roles = $this->em->getRepository(Role::class)->findAll();

        $html = $this->twig->render('users/edit.twig', [
            'user' => $user,
            'roles' => $roles,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function editData(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Eager loading ile kullanıcıyı yükle
        $qb = $this->em->createQueryBuilder();
        $qb->select('u', 'r')
           ->from(User::class, 'u')
           ->leftJoin('u.roles', 'r')
           ->where('u.id = :id')
           ->setParameter('id', $args['id']);
        
        $user = $qb->getQuery()->getOneOrNullResult();

        if (!$user) {
            $response->getBody()->write(json_encode(['error' => 'User not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Rolleri sadece ID olarak al
        $roleIds = [];
        foreach ($user->getRoles() as $role) {
            $roleIds[] = $role->getId();
        }

        $data = [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'isActive' => $user->isActive(),
            'emailDeliveryPercentage' => $user->getEmailDeliveryPercentage(),
            'refundEnabled' => $user->isRefundEnabled(),
            'roles' => $roleIds,
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            return $response->withStatus(404);
        }

        $data = $request->getParsedBody();
        $oldValues = [
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
        ];

        try {
            $user->setName($data['name'] ?? '');
            $user->setUsername($data['username'] ?? $user->getUsername());
            $user->setEmail($data['email'] ?? '');
            $user->setPhone($data['phone'] ?? null);
            $user->setIsActive(isset($data['is_active']));
            $user->setEmailDeliveryPercentage((int) ($data['email_delivery_percentage'] ?? 100));
            $user->setRefundEnabled(isset($data['refund_enabled']));

            // Şifre değiştirildi mi?
            if (!empty($data['password'])) {
                $user->setPassword($this->passwordHasher->hash($data['password']));
            }

            // Rolleri güncelle
            foreach ($user->getRoles() as $role) {
                $user->removeRole($role);
            }

            if (!empty($data['roles'])) {
                foreach ($data['roles'] as $roleId) {
                    $role = $this->em->find(Role::class, $roleId);
                    if ($role) {
                        $user->addRole($role);
                    }
                }
            }

            $this->em->flush();
            
            // Eğer güncellenen kullanıcı şu an oturum açmış kullanıcı ise, session'ı yenile
            if ($currentUserId == $user->getId()) {
                unset($_SESSION['user_permissions']);
                
                // Session'daki user bilgilerini de güncelle
                $_SESSION['user'] = [
                    'id' => $user->getId(),
                    'name' => $user->getName(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'isAccountManager' => $user->isAccountManager(),
                    'isChildAccount' => $user->isChildAccount(),
                ];
            }

            $this->auditLogger->logUpdate(
                $currentUserId,
                'User',
                $user->getId(),
                $oldValues,
                ['name' => $user->getName(), 'email' => $user->getEmail(), 'username' => $user->getUsername()]
            );

            $_SESSION['flash_success'] = 'Kullanıcı başarıyla güncellendi.';

            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
                
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Username veya email zaten kullanılıyor
            if (strpos($e->getMessage(), 'username') !== false) {
                $_SESSION['flash_error'] = 'Bu kullanıcı adı zaten kullanılıyor. Lütfen farklı bir kullanıcı adı seçin.';
            } elseif (strpos($e->getMessage(), 'email') !== false) {
                $_SESSION['flash_error'] = 'Bu e-posta adresi zaten kullanılıyor. Lütfen farklı bir e-posta adresi girin.';
            } else {
                $_SESSION['flash_error'] = 'Bu bilgiler zaten kullanılıyor. Lütfen farklı değerler girin.';
            }
            
            return $response
                ->withHeader('Location', '/users/' . $user->getId() . '/edit')
                ->withStatus(302);
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Kullanıcı güncellenirken hata oluştu: ' . $e->getMessage();
            
            return $response
                ->withHeader('Location', '/users/' . $user->getId() . '/edit')
                ->withStatus(302);
        }
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            return $response->withStatus(404);
        }

        $user->setIsActive(!$user->isActive());
        $this->em->flush();

        $this->auditLogger->log(
            $currentUserId,
            'user.toggle',
            'User',
            $user->getId(),
            null,
            ['is_active' => $user->isActive()]
        );

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı.';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }
        
        // Kendi hesabını silmeye çalışıyor mu?
        if ($user->getId() === $currentUserId) {
            $_SESSION['flash_error'] = 'Kendi hesabınızı silemezsiniz!';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        try {
            $userName = $user->getName();
            $userId = $user->getId();
            
            // Kullanıcıyı sil (CASCADE ile ilişkili veriler de silinir)
            $this->em->remove($user);
            $this->em->flush();

            $this->auditLogger->logDelete(
                $currentUserId,
                'User',
                $userId,
                ['name' => $userName]
            );

            $_SESSION['flash_success'] = "Kullanıcı '{$userName}' başarıyla silindi.";

        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Kullanıcı silinirken hata oluştu: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            return $response->withStatus(404);
        }

        $newPassword = 'Reset' . rand(1000, 9999) . '!';
        $user->setPassword($this->passwordHasher->hash($newPassword));
        $this->em->flush();

        $this->auditLogger->log(
            $currentUserId,
            'user.password_reset',
            'User',
            $user->getId()
        );

        $_SESSION['flash_success'] = "Yeni şifre: {$newPassword}";

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    public function reset2FA(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            return $response->withStatus(404);
        }

        $user->setTwoFactorSecret(null);
        $user->setTwoFactorRecoveryCodes(null);
        $this->em->flush();

        $this->auditLogger->log(
            $currentUserId,
            'user.2fa_reset',
            'User',
            $user->getId()
        );

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    /**
     * Kullanıcıya Mail kredisi ekle
     */
    public function addMailCredit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        $data = $request->getParsedBody();
        $amount = (int) ($data['credits'] ?? $data['amount'] ?? 0);
        $note = $data['note'] ?? 'Manuel mail kredi ekleme';

        if ($amount <= 0) {
            $_SESSION['flash_error'] = 'Geçersiz miktar';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        try {
            // Önceki bakiyeyi kaydet
            $balanceBefore = $user->getEmailCredit();
            
            $user->addEmailCredit($amount);
            
            // Email Transaction kaydı oluştur
            $transaction = new \App\Domain\Entities\EmailTransaction();
            $transaction->setUser($user);
            $transaction->setType(\App\Domain\Enum\EmailTransactionType::CREDIT);
            $transaction->setAmount($amount);
            $transaction->setDescription($note);
            $transaction->setBalanceBefore($balanceBefore);
            $transaction->setBalanceAfter($user->getEmailCredit());
            
            $this->em->persist($transaction);
            $this->em->flush();

            $this->auditLogger->log(
                $currentUserId,
                'user.mail_credit_add',
                'User',
                $user->getId(),
                ['amount' => $amount, 'note' => $note, 'new_balance' => $user->getEmailCredit()]
            );

            $_SESSION['flash_success'] = number_format($amount, 0, ',', '.') . ' mail kredi eklendi';
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'İşlem sırasında hata oluştu: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    /**
     * Kullanıcıdan Mail kredisi çıkar
     */
    public function deductMailCredit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        $data = $request->getParsedBody();
        $amount = (int) ($data['credits'] ?? $data['amount'] ?? 0);
        $note = $data['note'] ?? 'Manuel mail kredi çıkarma';

        if ($amount <= 0) {
            $_SESSION['flash_error'] = 'Geçersiz miktar';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        if ($user->getEmailCredit() < $amount) {
            $_SESSION['flash_error'] = 'Yetersiz mail kredi bakiyesi';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        try {
            // Önceki bakiyeyi kaydet
            $balanceBefore = $user->getEmailCredit();
            
            $user->deductEmailCredit($amount);
            
            // Email Transaction kaydı oluştur
            $transaction = new \App\Domain\Entities\EmailTransaction();
            $transaction->setUser($user);
            $transaction->setType(\App\Domain\Enum\EmailTransactionType::DEBIT);
            $transaction->setAmount($amount);
            $transaction->setDescription($note);
            $transaction->setBalanceBefore($balanceBefore);
            $transaction->setBalanceAfter($user->getEmailCredit());
            
            $this->em->persist($transaction);
            $this->em->flush();

            $this->auditLogger->log(
                $currentUserId,
                'user.mail_credit_deduct',
                'User',
                $user->getId(),
                ['amount' => $amount, 'note' => $note, 'new_balance' => $user->getEmailCredit()]
            );

            $_SESSION['flash_success'] = number_format($amount, 0, ',', '.') . ' mail kredi çıkarıldı';
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'İşlem sırasında hata oluştu: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    /**
     * Toplu işlemler
     */
    public function bulkAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        
        // JSON body parse
        $body = (string) $request->getBody();
        $data = json_decode($body, true);
        if ($data === null) {
            $data = $request->getParsedBody();
        }

        $action = $data['action'] ?? '';
        $userIds = $data['user_ids'] ?? [];

        if (empty($userIds) || !is_array($userIds)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Kullanıcı seçilmedi',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        try {
            $count = 0;
            
            switch ($action) {
                case 'activate':
                    foreach ($userIds as $userId) {
                        $user = $this->em->find(User::class, $userId);
                        if ($user) {
                            $user->setIsActive(true);
                            $count++;
                        }
                    }
                    $this->em->flush();
                    $message = "{$count} kullanıcı aktif edildi";
                    break;

                case 'deactivate':
                    foreach ($userIds as $userId) {
                        $user = $this->em->find(User::class, $userId);
                        if ($user) {
                            $user->setIsActive(false);
                            $count++;
                        }
                    }
                    $this->em->flush();
                    $message = "{$count} kullanıcı pasif edildi";
                    break;

                case 'enable_refund':
                    foreach ($userIds as $userId) {
                        $user = $this->em->find(User::class, $userId);
                        if ($user) {
                            $user->setRefundEnabled(true);
                            $count++;
                        }
                    }
                    $this->em->flush();
                    $message = "{$count} kullanıcı için iade aktif edildi";
                    break;

                case 'disable_refund':
                    foreach ($userIds as $userId) {
                        $user = $this->em->find(User::class, $userId);
                        if ($user) {
                            $user->setRefundEnabled(false);
                            $count++;
                        }
                    }
                    $this->em->flush();
                    $message = "{$count} kullanıcı için iade devre dışı bırakıldı";
                    break;

                case 'delete':
                    foreach ($userIds as $userId) {
                        $user = $this->em->find(User::class, $userId);
                        if ($user && $user->getId() !== $currentUserId) { // Kendini silemesin
                            $this->em->remove($user);
                            $count++;
                        }
                    }
                    $this->em->flush();
                    $message = "{$count} kullanıcı silindi";
                    break;

                default:
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Geçersiz işlem',
                    ]));
                    return $response
                        ->withHeader('Content-Type', 'application/json')
                        ->withStatus(400);
            }

            $this->auditLogger->log(
                $currentUserId,
                'user.bulk_action',
                'User',
                null,
                [
                    'action' => $action,
                    'affected_users' => $count,
                    'user_ids' => $userIds,
                ]
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $message,
                'count' => $count,
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'İşlem sırasında hata oluştu: ' . $e->getMessage(),
            ]));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }

    /**
     * Excel export
     */
    public function exportExcel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        
        // Filtreleme parametreleri
        $search = $params['search'] ?? '';
        $statusFilter = $params['status'] ?? 'all';
        $roleFilter = $params['role'] ?? 'all';
        $sortBy = $params['sort'] ?? 'createdAt';
        $sortDir = $params['dir'] ?? 'DESC';

        // Query builder (aynı filtrelerle)
        $qb = $this->em->createQueryBuilder();
        $qb->select('u', 'r', 'c')
           ->from(User::class, 'u')
           ->leftJoin('u.roles', 'r')
           ->leftJoin('u.credit', 'c');

        if (!empty($search)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('u.name', ':search'),
                    $qb->expr()->like('u.username', ':search'),
                    $qb->expr()->like('u.email', ':search')
                )
            );
            $qb->setParameter('search', '%' . $search . '%');
        }

        if ($statusFilter === 'active') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', true);
        } elseif ($statusFilter === 'inactive') {
            $qb->andWhere('u.isActive = :active')->setParameter('active', false);
        }

        if ($roleFilter !== 'all' && is_numeric($roleFilter)) {
            $qb->andWhere(':roleId MEMBER OF u.roles')->setParameter('roleId', (int)$roleFilter);
        }

        $allowedSorts = ['id', 'name', 'username', 'email', 'createdAt', 'lastLoginAt'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'createdAt';
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        
        $qb->orderBy('u.' . $sortBy, $sortDir);
        
        $users = $qb->getQuery()->getResult();

        // CSV oluştur
        $csv = "ID,Ad Soyad,Kullanıcı Adı,E-posta,Roller,Bakiye,Mail %,İade Durumu,Durum,Kayıt Tarihi,Son Giriş\n";
        
        foreach ($users as $user) {
            $roles = array_map(fn($role) => $role->getName(), $user->getRoles()->toArray());
            $rolesStr = implode(', ', $roles);
            $balance = $user->getCredit() ? $user->getCredit()->getBalance() : 0;
            $lastLogin = $user->getLastLoginAt() ? $user->getLastLoginAt()->format('d.m.Y H:i') : 'Hiç giriş yapmadı';
            
            $csv .= sprintf(
                "%d,\"%s\",\"%s\",\"%s\",\"%s\",%d,%d%%,%s,%s,%s,\"%s\"\n",
                $user->getId(),
                $user->getName(),
                $user->getUsername(),
                $user->getEmail(),
                $rolesStr,
                $balance,
                $user->getEmailDeliveryPercentage(),
                $user->isRefundEnabled() ? 'Aktif' : 'Pasif',
                $user->isActive() ? 'Aktif' : 'Pasif',
                $user->getCreatedAt()->format('d.m.Y H:i'),
                $lastLogin
            );
        }

        // UTF-8 BOM ekle (Excel için)
        $csv = "\xEF\xBB\xBF" . $csv;

        $response->getBody()->write($csv);
        
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="kullanicilar_' . date('Y-m-d_His') . '.csv"');
    }

    /**
     * İade durumunu toggle et
     */
    public function toggleRefund(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $user = $this->em->find(User::class, $args['id']);

        if (!$user) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Kullanıcı bulunamadı',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }

        // JSON body parse
        $body = (string) $request->getBody();
        $data = json_decode($body, true);
        if ($data === null) {
            $data = $request->getParsedBody();
        }

        $newState = isset($data['refund_enabled']) && $data['refund_enabled'];

        try {
            $oldState = $user->isRefundEnabled();
            $user->setRefundEnabled($newState);
            $this->em->flush();

            $this->auditLogger->log(
                $currentUserId,
                'user.refund_toggle',
                'User',
                $user->getId(),
                [
                    'old_state' => $oldState,
                    'new_state' => $newState,
                ]
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'refund_enabled' => $newState,
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'İşlem sırasında hata oluştu: ' . $e->getMessage(),
            ]));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }

    /**
     * Yönetim Paneli: Personel hesaplarını yönet - Modal için JSON data (AJAX)
     */
    public function manageAccountsData(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountManagerId = (int) $args['id'];
        $accountManager = $this->em->find(User::class, $accountManagerId);

        if (!$accountManager) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Hesap yöneticisi bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Alt hesapları getir (eager load)
        $qb = $this->em->createQueryBuilder();
        $qb->select('u', 'r', 'c')
            ->from(User::class, 'u')
            ->leftJoin('u.roles', 'r')
            ->leftJoin('u.credit', 'c')
            ->where('u.parentUser = :parentUser')
            ->setParameter('parentUser', $accountManager)
            ->orderBy('u.createdAt', 'DESC');

        $childUsers = $qb->getQuery()->getResult();

        // Tüm kullanıcılar (henüz hesap yöneticisine atanmamış olanlar)
        $allUsers = $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.parentUser IS NULL OR u.parentUser != :parentUser')
            ->andWhere('u.id != :parentUserId')
            ->setParameter('parentUser', $accountManager)
            ->setParameter('parentUserId', $accountManagerId)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        // JSON formatına çevir
        $childUsersData = [];
        foreach ($childUsers as $childUser) {
            $childUsersData[] = [
                'id' => $childUser->getId(),
                'name' => $childUser->getName(),
                'username' => $childUser->getUsername(),
                'email' => $childUser->getEmail(),
                'isActive' => $childUser->isActive(),
            ];
        }

        $availableUsersData = [];
        foreach ($allUsers as $user) {
            $availableUsersData[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'username' => $user->getUsername(),
            ];
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'childUsers' => $childUsersData,
            'availableUsers' => $availableUsersData,
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Yönetim Paneli: Hesap Yöneticisine personel ekle
     */
    public function addChildUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $accountManagerId = (int) $args['id'];
        $accountManager = $this->em->find(User::class, $accountManagerId);

        if (!$accountManager) {
            $_SESSION['flash_error'] = 'Hesap yöneticisi bulunamadı';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        $data = $request->getParsedBody();
        $childUserId = (int) ($data['child_user_id'] ?? 0);

        if ($childUserId === 0) {
            $_SESSION['flash_error'] = 'Personel seçilmedi';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        $childUser = $this->em->find(User::class, $childUserId);

        if (!$childUser) {
            $_SESSION['flash_error'] = 'Personel bulunamadı';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Zaten başka bir hesap yöneticisine atanmış mı?
        if ($childUser->getParentUser() !== null && $childUser->getParentUser()->getId() !== $accountManagerId) {
            $_SESSION['flash_error'] = 'Bu personel başka bir hesap yöneticisine atanmış';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Kendisini alt hesap olarak atayamaz
        if ($childUserId === $accountManagerId) {
            $_SESSION['flash_error'] = 'Kendinizi alt hesap olarak atayamazsınız';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Personeli hesap yöneticisine ekle
        $accountManager->addChildUser($childUser);
        $this->em->flush();

        // Audit log
        $this->auditLogger->log(
            $currentUserId,
            'user.child_user_added',
            'User',
            $accountManagerId,
            null,
            [
                'account_manager_id' => $accountManagerId,
                'account_manager_name' => $accountManager->getName(),
                'child_user_id' => $childUserId,
                'child_user_name' => $childUser->getName(),
            ]
        );

        $_SESSION['flash_success'] = "Personel '{$childUser->getName()}' hesap yöneticisine eklendi";
        $_SESSION['flash_icon'] = 'user-plus';

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    /**
     * Yönetim Paneli: Hesap Yöneticisinden personel kaldır
     */
    public function removeChildUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $accountManagerId = (int) $args['accountManagerId'];
        $childUserId = (int) $args['childUserId'];

        $accountManager = $this->em->find(User::class, $accountManagerId);
        $childUser = $this->em->find(User::class, $childUserId);

        if (!$accountManager || !$childUser) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Personeli hesap yöneticisinden kaldır
        $accountManager->removeChildUser($childUser);
        $this->em->flush();

        // Audit log
        $this->auditLogger->log(
            $currentUserId,
            'user.child_user_removed',
            'User',
            $accountManagerId,
            [
                'account_manager_id' => $accountManagerId,
                'account_manager_name' => $accountManager->getName(),
                'child_user_id' => $childUserId,
                'child_user_name' => $childUser->getName(),
            ],
            null
        );

        $_SESSION['flash_success'] = "Personel '{$childUser->getName()}' hesap yöneticisinden kaldırıldı";
        $_SESSION['flash_icon'] = 'user-minus';

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    /**
     * Yönetim Paneli: Bir kullanıcıyı Hesap Yöneticisi yap
     */
    public function makeAccountManager(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $userId = (int) $args['id'];
        $user = $this->em->find(User::class, $userId);

        if (!$user) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Zaten hesap yöneticisi mi?
        if ($user->isAccountManager()) {
            $_SESSION['flash_success'] = "Kullanıcı zaten Hesap Yöneticisi";
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Personel hesabı mı? (Personel hesabı hesap yöneticisi olamaz)
        if ($user->isChildAccount()) {
            $_SESSION['flash_error'] = 'Personel hesapları Hesap Yöneticisi olamaz. Önce personeli hesap yöneticisinden ayırın.';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Kullanıcıyı hesap yöneticisi yap (şu an için sadece badge gösterilir, 
        // personel eklediğinde gerçekten hesap yöneticisi olur)
        // Bu fonksiyon sadece kullanıcıyı hazırlar, personel ekleme sayfasına yönlendirir
        
        // Audit log
        $this->auditLogger->log(
            $currentUserId,
            'user.made_account_manager',
            'User',
            $userId,
            null,
            [
                'user_id' => $userId,
                'user_name' => $user->getName(),
            ]
        );

        $_SESSION['flash_success'] = "Kullanıcı '{$user->getName()}' Hesap Yöneticisi olarak işaretlendi. Şimdi personel ekleyebilirsiniz.";
        $_SESSION['flash_icon'] = 'users';

        // Users sayfasına yönlendir (modal ile personel eklenebilir)
        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }

    /**
     * Kullanıcının sayfa erişim izinlerini getir (JSON)
     */
    public function getPageAccess(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Output buffer'ı temizle (JSON response için)
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        $userId = (int) $args['id'];
        $user = $this->em->find(User::class, $userId);

        if (!$user) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Kullanıcı bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Tüm sayfalar listesi
        $allPages = $this->getAllAvailablePages();

        // Kullanıcının izinli sayfaları (null olabilir, ?? kullanma!)
        $allowedPages = $user->getAllowedPages();

        $response->getBody()->write(json_encode([
            'success' => true,
            'allowedPages' => $allowedPages, // null, [] veya dolu array olabilir
            'allPages' => $allPages,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Kullanıcının sayfa erişim izinlerini güncelle
     */
    public function updatePageAccess(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Output buffer'ı temizle (JSON response için)
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        $currentUserId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $userId = (int) $args['id'];
        $user = $this->em->find(User::class, $userId);

        if (!$user) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Kullanıcı bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Content-Type kontrolü - JSON veya FormData
        $contentType = $request->getHeaderLine('Content-Type');
        $data = [];
        
        if (strpos($contentType, 'application/json') !== false) {
            // JSON request
            $body = $request->getBody()->getContents();
            $data = json_decode($body, true) ?? [];
        } else {
            // FormData request
            $data = $request->getParsedBody() ?? [];
        }
        
        // allowedPages null, [] veya dolu array olabilir
        $allowedPages = null;
        if (isset($data['allowed_pages'])) {
            if ($data['allowed_pages'] === null) {
                // Null - tüm sayfalara erişebilir
                $allowedPages = null;
            } elseif (is_array($data['allowed_pages']) && !empty($data['allowed_pages'])) {
                // Dolu array - sadece seçili sayfalar
                $allowedPages = array_map(function($page) {
                    return '/' . ltrim($page, '/');
                }, $data['allowed_pages']);
            } elseif (is_array($data['allowed_pages']) && empty($data['allowed_pages'])) {
                // Boş array - hiçbir sayfaya erişemez
                $allowedPages = [];
            }
        }

        $user->setAllowedPages($allowedPages);
        $this->em->flush();

        // Audit log
        $this->auditLogger->log(
            $currentUserId,
            'user.page_access_updated',
            'User',
            $userId,
            null,
            [
                'user_id' => $userId,
                'user_name' => $user->getName(),
                'allowed_pages' => $allowedPages,
            ]
        );

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Sayfa erişim izinleri güncellendi',
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Tüm mevcut sayfaları listele
     */
    private function getAllAvailablePages(): array
    {
        return [
            ['path' => '/', 'label' => 'Ana Sayfa'],
            ['path' => '/email-orders', 'label' => 'Email Kampanyaları'],
            ['path' => '/email-phonebooks', 'label' => 'Email Rehberleri'],
            ['path' => '/email-blacklist', 'label' => 'Email Kara Liste'],
            ['path' => '/email-templates', 'label' => 'Email Şablonları'],
            ['path' => '/email-transactions', 'label' => 'Email İşlem Geçmişi'],
            ['path' => '/url-shortener', 'label' => 'Link Kısaltıcı'],
            ['path' => '/tickets', 'label' => 'Destek Talepleri'],
        ];
    }

    /**
     * Kullanıcı adına giriş yap (Admin Impersonation)
     */
    public function impersonate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $adminId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $admin = $this->em->find(User::class, $adminId);

        // Admin kontrolü
        if (!$admin || !$_SESSION['is_admin'] ?? false) {
            $_SESSION['flash_error'] = 'Bu işlem için admin yetkisi gereklidir';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        $targetUserId = (int) $args['id'];
        $targetUser = $this->em->find(User::class, $targetUserId);

        if (!$targetUser) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        if (!$targetUser->isActive()) {
            $_SESSION['flash_error'] = 'Bu kullanıcı aktif değil';
            return $response
                ->withHeader('Location', '/users')
                ->withStatus(302);
        }

        // Orijinal admin bilgisini sakla
        $_SESSION['original_admin_id'] = $adminId;
        $_SESSION['original_admin_name'] = $admin->getName();
        $_SESSION['is_impersonating'] = true;

        // Hedef kullanıcı bilgilerini session'a yükle
        $_SESSION['user_id'] = $targetUser->getId();
        $_SESSION['user'] = [
            'id' => $targetUser->getId(),
            'name' => $targetUser->getName(),
            'username' => $targetUser->getUsername(),
            'email' => $targetUser->getEmail(),
            'isAccountManager' => $targetUser->isAccountManager(),
            'isChildAccount' => $targetUser->isChildAccount(),
        ];

        // Rolleri ve yetkileri yükle
        $_SESSION['user_permissions'] = [];
        $_SESSION['is_admin'] = false;
        
        foreach ($targetUser->getRoles() as $role) {
            // Admin rolünü kontrol et
            if (strtolower($role->getName()) === 'admin') {
                $_SESSION['is_admin'] = true;
            }
            
            foreach ($role->getPermissions() as $rolePermission) {
                $permKey = $rolePermission->getPermission()->getKey();
                
                if (!isset($_SESSION['user_permissions'][$permKey])) {
                    $_SESSION['user_permissions'][$permKey] = [
                        'read' => false,
                        'create' => false,
                        'update' => false,
                        'delete' => false,
                    ];
                }
                $_SESSION['user_permissions'][$permKey]['read'] = $_SESSION['user_permissions'][$permKey]['read'] || $rolePermission->canRead();
                $_SESSION['user_permissions'][$permKey]['create'] = $_SESSION['user_permissions'][$permKey]['create'] || $rolePermission->canCreate();
                $_SESSION['user_permissions'][$permKey]['update'] = $_SESSION['user_permissions'][$permKey]['update'] || $rolePermission->canUpdate();
                $_SESSION['user_permissions'][$permKey]['delete'] = $_SESSION['user_permissions'][$permKey]['delete'] || $rolePermission->canDelete();
            }
        }

        $this->auditLogger->log(
            $adminId,
            'user.impersonate',
            'User',
            $targetUserId,
            null,
            [
                'admin_name' => $admin->getName(),
                'target_user_name' => $targetUser->getName(),
            ]
        );

        $_SESSION['flash_success'] = "Kullanıcı '{$targetUser->getName()}' adına giriş yapıldı";
        $_SESSION['flash_icon'] = 'user-check';

        return $response
            ->withHeader('Location', '/dashboard')
            ->withStatus(302);
    }

    /**
     * Impersonation'ı durdur (Admin'e geri dön)
     */
    public function stopImpersonate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!isset($_SESSION['is_impersonating']) || !$_SESSION['is_impersonating']) {
            return $response
                ->withHeader('Location', '/dashboard')
                ->withStatus(302);
        }

        $originalAdminId = $_SESSION['original_admin_id'] ?? null;
        $originalAdmin = $originalAdminId ? $this->em->find(User::class, $originalAdminId) : null;

        if (!$originalAdmin) {
            // Admin bulunamazsa logout yap
            session_destroy();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        // Orijinal admin bilgilerini geri yükle
        $_SESSION['user_id'] = $originalAdmin->getId();
        $_SESSION['user'] = [
            'id' => $originalAdmin->getId(),
            'name' => $originalAdmin->getName(),
            'username' => $originalAdmin->getUsername(),
            'email' => $originalAdmin->getEmail(),
            'isAccountManager' => $originalAdmin->isAccountManager(),
            'isChildAccount' => $originalAdmin->isChildAccount(),
        ];

        // Admin rolleri ve yetkileri geri yükle
        $_SESSION['user_permissions'] = [];
        $_SESSION['is_admin'] = false;
        
        foreach ($originalAdmin->getRoles() as $role) {
            if (strtolower($role->getName()) === 'admin') {
                $_SESSION['is_admin'] = true;
            }
            
            foreach ($role->getPermissions() as $rolePermission) {
                $permKey = $rolePermission->getPermission()->getKey();
                
                if (!isset($_SESSION['user_permissions'][$permKey])) {
                    $_SESSION['user_permissions'][$permKey] = [
                        'read' => false,
                        'create' => false,
                        'update' => false,
                        'delete' => false,
                    ];
                }
                $_SESSION['user_permissions'][$permKey]['read'] = $_SESSION['user_permissions'][$permKey]['read'] || $rolePermission->canRead();
                $_SESSION['user_permissions'][$permKey]['create'] = $_SESSION['user_permissions'][$permKey]['create'] || $rolePermission->canCreate();
                $_SESSION['user_permissions'][$permKey]['update'] = $_SESSION['user_permissions'][$permKey]['update'] || $rolePermission->canUpdate();
                $_SESSION['user_permissions'][$permKey]['delete'] = $_SESSION['user_permissions'][$permKey]['delete'] || $rolePermission->canDelete();
            }
        }

        // Impersonation bilgilerini temizle
        unset($_SESSION['is_impersonating']);
        unset($_SESSION['original_admin_id']);
        unset($_SESSION['original_admin_name']);

        $_SESSION['flash_success'] = "Admin hesabına geri dönüldü";
        $_SESSION['flash_icon'] = 'user-check';

        return $response
            ->withHeader('Location', '/users')
            ->withStatus(302);
    }
}

