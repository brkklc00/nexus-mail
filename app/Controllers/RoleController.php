<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\Role;
use App\Domain\Entities\Permission;
use App\Domain\Entities\RolePermission;
use App\Application\Services\AuditLoggerService;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

class RoleController
{
    private EntityManager $em;
    private Environment $twig;
    private AuditLoggerService $auditLogger;

    public function __construct(
        EntityManager $em,
        Environment $twig,
        AuditLoggerService $auditLogger
    ) {
        $this->em = $em;
        $this->twig = $twig;
        $this->auditLogger = $auditLogger;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        
        // Rolleri al
        $qb = $this->em->createQueryBuilder();
        $qb->select('r', 'u', 'p')
           ->from(Role::class, 'r')
           ->leftJoin('r.users', 'u')
           ->leftJoin('r.permissions', 'p')
           ->orderBy('r.createdAt', 'DESC');
        
        // Arama
        if (!empty($search)) {
            $qb->andWhere('r.name LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        
        $roles = $qb->getQuery()->getResult();
        
        // İstatistikler
        $stats = $this->getRoleStats();
        
        // Tüm yetkiler
        $permissions = $this->em->getRepository(Permission::class)->findAll();
        
        // Yetkile göre grupla
        $groupedPermissions = $this->groupPermissionsByModule($permissions);

        $html = $this->twig->render('roles/index.twig', [
            'roles' => $roles,
            'permissions' => $permissions,
            'groupedPermissions' => $groupedPermissions,
            'stats' => $stats,
            'search' => $search,
            'success' => $_SESSION['flash_success'] ?? null,
        ]);

        unset($_SESSION['flash_success']);

        $response->getBody()->write($html);
        return $response;
    }
    
    /**
     * Rol istatistikleri
     */
    private function getRoleStats(): array
    {
        // Toplam rol sayısı
        $totalRoles = $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Role::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
        
        // Sistem rolleri
        $systemRoles = $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Role::class, 'r')
            ->where('r.isReadonly = :readonly')
            ->setParameter('readonly', true)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Kullanıcılı roller
        $rolesWithUsers = $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT r.id)')
            ->from(Role::class, 'r')
            ->leftJoin('r.users', 'u')
            ->where('u.id IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
        
        // Toplam yetki sayısı
        $totalPermissions = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Permission::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();
        
        return [
            'total_roles' => $totalRoles,
            'system_roles' => $systemRoles,
            'custom_roles' => $totalRoles - $systemRoles,
            'roles_with_users' => $rolesWithUsers,
            'total_permissions' => $totalPermissions,
        ];
    }
    
    /**
     * Yetkileri modüle göre grupla
     */
    private function groupPermissionsByModule(array $permissions): array
    {
        $grouped = [
            'Email Yönetimi' => [],
            'Kullanıcı & Rol Yönetimi' => [],
            'Finansal İşlemler' => [],
            'Rehber & Kişiler' => [],
            'Araçlar & Sistem' => [],
            'Destek & Bildirimler' => [],
            'Admin Panel' => [],
            'Diğer' => [],
        ];
        
        foreach ($permissions as $permission) {
            $key = strtolower($permission->getKey());
            $name = $permission->getName();
            
            // Önce key'e göre kontrol et
            if (str_contains($key, 'email') || str_contains($key, 'smtp') || str_contains($key, 'mail')) {
                $grouped['Email Yönetimi'][] = $permission;
            } elseif (str_contains($key, 'user')) {
                $grouped['Kullanıcı & Rol Yönetimi'][] = $permission;
            } elseif (str_contains($key, 'role') || str_contains($key, 'permission')) {
                $grouped['Kullanıcı & Rol Yönetimi'][] = $permission;
            } elseif (str_contains($key, 'phonebook')) {
                $grouped['Rehber & Kişiler'][] = $permission;
            } elseif (str_contains($key, 'blacklist')) {
                $grouped['Rehber & Kişiler'][] = $permission;
            } elseif (str_contains($key, 'transactional_email') || str_contains($key, 'transactional-email')) {
                $grouped['Email Yönetimi'][] = $permission;
            } elseif (str_contains($key, 'transaction') || str_contains($key, 'credit')) {
                $grouped['Finansal İşlemler'][] = $permission;
            } elseif (str_contains($key, 'notification') || str_contains($key, 'support') || str_contains($key, 'ticket')) {
                $grouped['Destek & Bildirimler'][] = $permission;
            } elseif (str_contains($key, 'url') || str_contains($key, 'data_pool') || str_contains($key, 'system_monitor') || str_contains($key, 'settings') || str_contains($key, 'server')) {
                $grouped['Araçlar & Sistem'][] = $permission;
            } elseif (str_contains($key, 'admin')) {
                $grouped['Admin Panel'][] = $permission;
            } else {
                $grouped['Diğer'][] = $permission;
            }
        }
        
        // Boş grupları kaldır
        return array_filter($grouped, function($group) {
            return !empty($group);
        });
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $permissions = $this->em->getRepository(Permission::class)->findAll();

        $html = $this->twig->render('roles/create.twig', [
            'permissions' => $permissions,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $data = $request->getParsedBody();

        $role = new Role();
        $role->setName($data['name'] ?? '');

        // Yetkileri ekle
        if (!empty($data['permissions'])) {
            foreach ($data['permissions'] as $permissionId => $actions) {
                $permission = $this->em->find(Permission::class, $permissionId);
                if ($permission) {
                    $rp = new RolePermission();
                    $rp->setRole($role);
                    $rp->setPermission($permission);
                    $rp->setCanRead(isset($actions['read']));
                    $rp->setCanCreate(isset($actions['create']));
                    $rp->setCanUpdate(isset($actions['update']));
                    $rp->setCanDelete(isset($actions['delete']));
                    $role->addPermission($rp);
                }
            }
        }

        $this->em->persist($role);
        $this->em->flush();

        $this->auditLogger->logCreate(
            $currentUserId,
            'Role',
            $role->getId(),
            ['name' => $role->getName()]
        );

        $_SESSION['flash_success'] = 'Rol başarıyla oluşturuldu.';

        return $response
            ->withHeader('Location', '/roles')
            ->withStatus(302);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $role = $this->em->find(Role::class, $args['id']);

            if (!$role) {
                $response->getBody()->write('<div class="alert alert-danger">Rol bulunamadı.</div>');
                return $response->withStatus(404);
            }

            // Lazy loading'i tetikle
            $role->getPermissions()->count();
            $role->getUsers()->count();

            $html = $this->twig->render('roles/_detail.twig', [
                'role' => $role,
            ]);

            $response->getBody()->write($html);
            return $response;
        } catch (\Exception $e) {
            error_log('Rol detay hatası: ' . $e->getMessage());
            $response->getBody()->write('<div class="alert alert-danger">Hata oluştu: ' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response->withStatus(500);
        }
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = $this->em->find(Role::class, $args['id']);

        if (!$role) {
            return $response->withStatus(404);
        }

        if ($role->isReadonly()) {
            $_SESSION['flash_error'] = 'Bu rol düzenlenemez.';
            return $response
                ->withHeader('Location', '/roles')
                ->withStatus(302);
        }

        $permissions = $this->em->getRepository(Permission::class)->findAll();
        $groupedPermissions = $this->groupPermissionsByModule($permissions);

        $html = $this->twig->render('roles/edit.twig', [
            'role' => $role,
            'permissions' => $permissions,
            'groupedPermissions' => $groupedPermissions,
        ]);

        $response->getBody()->write($html);
        return $response;
    }
    
    /**
     * Rol düzenleme için JSON data endpoint (Modal için)
     */
    public function editData(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = $this->em->find(Role::class, $args['id']);

        if (!$role) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Rol bulunamadı'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }

        // Admin her rolü düzenleyebilir (readonly kontrolü kaldırıldı)

        // Tüm yetkileri al ve grupla
        $permissions = $this->em->getRepository(Permission::class)->findAll();
        $groupedPermissions = $this->groupPermissionsByModule($permissions);
        
        // Grupları JSON uyumlu hale getir
        $groupedArray = [];
        foreach ($groupedPermissions as $module => $perms) {
            $groupedArray[$module] = array_map(function($perm) {
                return [
                    'id' => $perm->getId(),
                    'key' => $perm->getKey(),
                    'name' => $perm->getName(),
                ];
            }, $perms);
        }
        
        // Rol yetkilerini al
        $rolePermissionsArray = [];
        foreach ($role->getPermissions() as $rp) {
            $rolePermissionsArray[] = [
                'permission_id' => $rp->getPermission()->getId(),
                'can_read' => $rp->canRead(),
                'can_create' => $rp->canCreate(),
                'can_update' => $rp->canUpdate(),
                'can_delete' => $rp->canDelete(),
            ];
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'role' => [
                'id' => $role->getId(),
                'name' => $role->getName(),
                'permissions' => $rolePermissionsArray,
            ],
            'groupedPermissions' => $groupedArray,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $role = $this->em->find(Role::class, $args['id']);

        if (!$role) {
            return $response->withStatus(404);
        }
        
        // Admin tüm rolleri güncelleyebilir (readonly kontrolü kaldırıldı)

        $data = $request->getParsedBody();
        $role->setName($data['name'] ?? '');

        // Mevcut yetkileri temizle
        foreach ($role->getPermissions() as $rp) {
            $this->em->remove($rp);
        }
        $this->em->flush();

        // Yeni yetkileri ekle
        if (!empty($data['permissions'])) {
            foreach ($data['permissions'] as $permissionId => $actions) {
                $permission = $this->em->find(Permission::class, $permissionId);
                if ($permission) {
                    $rp = new RolePermission();
                    $rp->setRole($role);
                    $rp->setPermission($permission);
                    $rp->setCanRead(isset($actions['read']));
                    $rp->setCanCreate(isset($actions['create']));
                    $rp->setCanUpdate(isset($actions['update']));
                    $rp->setCanDelete(isset($actions['delete']));
                    $this->em->persist($rp);
                }
            }
        }

        $this->em->flush();

        $this->auditLogger->logUpdate(
            $currentUserId,
            'Role',
            $role->getId(),
            [],
            ['name' => $role->getName()]
        );

        return $response
            ->withHeader('Location', '/roles')
            ->withStatus(302);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $role = $this->em->find(Role::class, $args['id']);

        if (!$role || $role->isReadonly()) {
            return $response->withStatus(404);
        }

        $this->auditLogger->logDelete(
            $currentUserId,
            'Role',
            $role->getId(),
            ['name' => $role->getName()]
        );

        $this->em->remove($role);
        $this->em->flush();

        return $response
            ->withHeader('Location', '/roles')
            ->withStatus(302);
    }
    
    /**
     * Rol kopyalama
     */
    public function clone(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        $sourceRole = $this->em->find(Role::class, $args['id']);
        
        if (!$sourceRole) {
            return $response->withStatus(404);
        }
        
        // Yeni rol oluştur
        $newRole = new Role();
        $newRole->setName($sourceRole->getName() . ' (Kopya)');
        
        // Yetkileri kopyala
        foreach ($sourceRole->getPermissions() as $rp) {
            $newRp = new RolePermission();
            $newRp->setRole($newRole);
            $newRp->setPermission($rp->getPermission());
            $newRp->setCanRead($rp->canRead());
            $newRp->setCanCreate($rp->canCreate());
            $newRp->setCanUpdate($rp->canUpdate());
            $newRp->setCanDelete($rp->canDelete());
            $newRole->addPermission($newRp);
        }
        
        $this->em->persist($newRole);
        $this->em->flush();
        
        $this->auditLogger->logCreate(
            $currentUserId,
            'Role',
            $newRole->getId(),
            ['name' => $newRole->getName(), 'cloned_from' => $sourceRole->getId()]
        );
        
        $_SESSION['flash_success'] = 'Rol başarıyla kopyalandı.';
        
        return $response
            ->withHeader('Location', '/roles')
            ->withStatus(302);
    }
    
    /**
     * Şablondan rol oluştur
     */
    public function createFromTemplate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        
        // JSON body parse
        $body = (string) $request->getBody();
        $data = json_decode($body, true);
        if ($data === null) {
            $data = $request->getParsedBody();
        }
        
        $template = $data['template'] ?? '';
        $customName = $data['name'] ?? '';
        
        $role = new Role();
        $permissions = $this->em->getRepository(Permission::class)->findAll();
        
        switch ($template) {
            case 'admin':
                $role->setName($customName ?: 'Admin');
                // Tüm yetkileri ver
                foreach ($permissions as $permission) {
                    $rp = new RolePermission();
                    $rp->setRole($role);
                    $rp->setPermission($permission);
                    $rp->setCanRead(true);
                    $rp->setCanCreate(true);
                    $rp->setCanUpdate(true);
                    $rp->setCanDelete(true);
                    $role->addPermission($rp);
                }
                break;
                
            case 'editor':
                $role->setName($customName ?: 'Editor');
                // Okuma ve yazma yetkileri ver (silme hariç)
                foreach ($permissions as $permission) {
                    $rp = new RolePermission();
                    $rp->setRole($role);
                    $rp->setPermission($permission);
                    $rp->setCanRead(true);
                    $rp->setCanCreate(true);
                    $rp->setCanUpdate(true);
                    $rp->setCanDelete(false);
                    $role->addPermission($rp);
                }
                break;
                
            case 'viewer':
                $role->setName($customName ?: 'Viewer');
                // Sadece okuma yetkisi ver
                foreach ($permissions as $permission) {
                    $rp = new RolePermission();
                    $rp->setRole($role);
                    $rp->setPermission($permission);
                    $rp->setCanRead(true);
                    $rp->setCanCreate(false);
                    $rp->setCanUpdate(false);
                    $rp->setCanDelete(false);
                    $role->addPermission($rp);
                }
                break;
                
            default:
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Geçersiz şablon'
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
        }
        
        $this->em->persist($role);
        $this->em->flush();
        
        $this->auditLogger->logCreate(
            $currentUserId,
            'Role',
            $role->getId(),
            ['name' => $role->getName(), 'template' => $template]
        );
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Rol şablondan oluşturuldu',
            'role_id' => $role->getId()
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Rolleri karşılaştır
     */
    public function compare(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $role1Id = (int) ($params['role1'] ?? 0);
        $role2Id = (int) ($params['role2'] ?? 0);
        
        if (!$role1Id || !$role2Id) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'İki rol seçmelisiniz'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
        
        $role1 = $this->em->find(Role::class, $role1Id);
        $role2 = $this->em->find(Role::class, $role2Id);
        
        if (!$role1 || !$role2) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Roller bulunamadı'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }
        
        // Yetkileri karşılaştır
        $comparison = [];
        $allPermissions = $this->em->getRepository(Permission::class)->findAll();
        
        foreach ($allPermissions as $permission) {
            $perm1 = null;
            $perm2 = null;
            
            foreach ($role1->getPermissions() as $rp) {
                if ($rp->getPermission()->getId() === $permission->getId()) {
                    $perm1 = $rp;
                    break;
                }
            }
            
            foreach ($role2->getPermissions() as $rp) {
                if ($rp->getPermission()->getId() === $permission->getId()) {
                    $perm2 = $rp;
                    break;
                }
            }
            
            $comparison[] = [
                'permission' => $permission->getName(),
                'role1' => [
                    'read' => $perm1 ? $perm1->canRead() : false,
                    'create' => $perm1 ? $perm1->canCreate() : false,
                    'update' => $perm1 ? $perm1->canUpdate() : false,
                    'delete' => $perm1 ? $perm1->canDelete() : false,
                ],
                'role2' => [
                    'read' => $perm2 ? $perm2->canRead() : false,
                    'create' => $perm2 ? $perm2->canCreate() : false,
                    'update' => $perm2 ? $perm2->canUpdate() : false,
                    'delete' => $perm2 ? $perm2->canDelete() : false,
                ]
            ];
        }
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'role1' => ['id' => $role1->getId(), 'name' => $role1->getName()],
            'role2' => ['id' => $role2->getId(), 'name' => $role2->getName()],
            'comparison' => $comparison
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Export JSON
     */
    public function exportJson(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $role = $this->em->find(Role::class, $args['id']);
        
        if (!$role) {
            return $response->withStatus(404);
        }
        
        $data = [
            'name' => $role->getName(),
            'permissions' => []
        ];
        
        foreach ($role->getPermissions() as $rp) {
            $data['permissions'][] = [
                'permission' => $rp->getPermission()->getName(),
                'read' => $rp->canRead(),
                'create' => $rp->canCreate(),
                'update' => $rp->canUpdate(),
                'delete' => $rp->canDelete(),
            ];
        }
        
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="role_' . $role->getId() . '_' . date('Y-m-d') . '.json"');
    }
    
    /**
     * Rol önizleme - hangi sayfalara erişebilir
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $role = $this->em->find(Role::class, $args['id']);
            
            if (!$role) {
                $response->getBody()->write('<div class="alert alert-danger">Rol bulunamadı.</div>');
                return $response->withStatus(404);
            }
            
            // Lazy loading'i tetikle
            $role->getPermissions()->count();
        
        // Sayfa-izin eşleştirme matrisi
        $pagePermissions = [
            'Dashboard' => ['icon' => 'home', 'permission' => null], // Herkes erişebilir
            
            // Ana Menü
            'Email Gönder' => ['icon' => 'send', 'permission' => 'email_order', 'action' => 'read', 'url' => '/email/orders'],
            'Email Rehberleri' => ['icon' => 'book', 'permission' => 'email_phonebook', 'action' => 'read', 'url' => '/email/phonebooks'],
            'Email Kara Liste' => ['icon' => 'x-circle', 'permission' => 'email_blacklist', 'action' => 'read', 'url' => '/email/blacklists'],
            'Email Şablonları' => ['icon' => 'file-text', 'permission' => 'email_template', 'action' => 'read', 'url' => '/email/templates'],
            'SMTP Yönetimi' => ['icon' => 'server', 'permission' => 'email_smtp', 'action' => 'read', 'url' => '/email/smtp'],
            'İşlemsel Email' => ['icon' => 'mail', 'permission' => 'transactional_email', 'action' => 'read', 'url' => '/transactional-email'],
            'Link Kısaltıcı' => ['icon' => 'link', 'permission' => 'url_shortener', 'action' => 'read', 'url' => '/url-shortener'],
            'Destek Talepleri' => ['icon' => 'help-circle', 'permission' => 'support_ticket', 'action' => 'read', 'url' => '/tickets'],
            
            // Yönetim Menüsü
            'Bildirim Gönder' => ['icon' => 'bell', 'permission' => 'notification', 'action' => 'read', 'url' => '/admin/notifications'],
            'Tüm Email Siparişleri' => ['icon' => 'shopping-cart', 'permission' => 'admin_email_orders', 'action' => 'read', 'url' => '/admin/email-orders'],
            'Tüm Email Rehberleri' => ['icon' => 'book', 'permission' => 'admin_email_phonebooks', 'action' => 'read', 'url' => '/admin/email-phonebooks'],
            'Tüm Email Kara Listeler' => ['icon' => 'slash', 'permission' => 'admin_email_blacklists', 'action' => 'read', 'url' => '/admin/email-blacklists'],
            'Email Şablonları' => ['icon' => 'file-text', 'permission' => 'admin_email_templates', 'action' => 'read', 'url' => '/admin/email-templates'],
            'Müşteri Talepleri' => ['icon' => 'message-circle', 'permission' => 'user', 'action' => 'read', 'url' => '/admin/support-tickets'],
            'Kullanıcılar' => ['icon' => 'users', 'permission' => 'user', 'action' => 'read', 'url' => '/users'],
            'Roller & Yetkiler' => ['icon' => 'shield', 'permission' => 'role', 'action' => 'read', 'url' => '/roles'],
        ];
        
        $accessiblePages = [];
        $blockedPages = [];
        
        foreach ($pagePermissions as $pageName => $pageInfo) {
            if ($pageInfo['permission'] === null) {
                // Herkesin erişebildiği sayfalar
                $accessiblePages[] = array_merge(['name' => $pageName], $pageInfo);
            } else {
                // İzin kontrolü yap
                $hasAccess = false;
                foreach ($role->getPermissions() as $rp) {
                    // getKey() kullan (getName() değil - çünkü key'ler 'phonebook', 'order' vs)
                    if ($rp->getPermission()->getKey() === $pageInfo['permission']) {
                        $action = $pageInfo['action'] ?? 'read';
                        switch ($action) {
                            case 'read':
                                $hasAccess = $rp->canRead();
                                break;
                            case 'create':
                                $hasAccess = $rp->canCreate();
                                break;
                            case 'update':
                                $hasAccess = $rp->canUpdate();
                                break;
                            case 'delete':
                                $hasAccess = $rp->canDelete();
                                break;
                        }
                        break;
                    }
                }
                
                if ($hasAccess) {
                    $accessiblePages[] = array_merge(['name' => $pageName], $pageInfo);
                } else {
                    $blockedPages[] = array_merge(['name' => $pageName], $pageInfo);
                }
            }
        }
        
        $html = $this->twig->render('roles/_preview.twig', [
            'role' => $role,
            'accessible_pages' => $accessiblePages,
            'blocked_pages' => $blockedPages,
        ]);
        
        $response->getBody()->write($html);
        return $response;
        } catch (\Exception $e) {
            error_log('Rol önizleme hatası: ' . $e->getMessage());
            $response->getBody()->write('<div class="alert alert-danger">Hata oluştu: ' . htmlspecialchars($e->getMessage()) . '</div>');
            return $response->withStatus(500);
        }
    }
    
    /**
     * Toplu işlem
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
        $roleIds = $data['role_ids'] ?? [];
        
        if (empty($roleIds) || !is_array($roleIds)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Rol seçilmedi',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
        
        try {
            $count = 0;
            
            switch ($action) {
                case 'delete':
                    foreach ($roleIds as $roleId) {
                        $role = $this->em->find(Role::class, $roleId);
                        if ($role && !$role->isReadonly()) {
                            $this->em->remove($role);
                            $count++;
                        }
                    }
                    $this->em->flush();
                    $message = "{$count} rol silindi";
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
                'role.bulk_action',
                'Role',
                null,
                [
                    'action' => $action,
                    'affected_roles' => $count,
                    'role_ids' => $roleIds,
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
     * YETKİ YÖNETİMİ (Permission CRUD)
     */
    
    /**
     * Yeni yetki oluştur
     */
    public function createPermission(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        
        $body = (string) $request->getBody();
        $data = json_decode($body, true);
        if ($data === null) {
            $data = $request->getParsedBody();
        }
        
        $name = $data['name'] ?? '';
        $key = $data['key'] ?? '';
        
        if (empty($name) || empty($key)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Yetki adı ve anahtarı gerekli'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
        
        // Anahtar benzersiz mi kontrol et
        $existing = $this->em->getRepository(Permission::class)->findOneBy(['key' => $key]);
        if ($existing) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Bu anahtar zaten kullanılıyor'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
        
        try {
            $permission = new Permission();
            $permission->setName($name);
            $permission->setKey($key);
            
            $this->em->persist($permission);
            $this->em->flush();
            
            $this->auditLogger->logCreate(
                $currentUserId,
                'Permission',
                $permission->getId(),
                ['name' => $name, 'key' => $key]
            );
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Yetki başarıyla oluşturuldu',
                'permission_id' => $permission->getId()
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Yetki oluşturulurken hata: ' . $e->getMessage()
            ]));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
    
    /**
     * Yetki güncelle
     */
    public function updatePermission(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        
        $permission = $this->em->find(Permission::class, $args['id']);
        
        if (!$permission) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Yetki bulunamadı'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }
        
        $body = (string) $request->getBody();
        $data = json_decode($body, true);
        if ($data === null) {
            $data = $request->getParsedBody();
        }
        
        $name = $data['name'] ?? '';
        $key = $data['key'] ?? '';
        
        if (empty($name) || empty($key)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Yetki adı ve anahtarı gerekli'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
        
        // Anahtar başka bir yetki tarafından kullanılıyor mu?
        $existing = $this->em->getRepository(Permission::class)
            ->createQueryBuilder('p')
            ->where('p.key = :key')
            ->andWhere('p.id != :id')
            ->setParameter('key', $key)
            ->setParameter('id', $permission->getId())
            ->getQuery()
            ->getOneOrNullResult();
            
        if ($existing) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Bu anahtar başka bir yetki tarafından kullanılıyor'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
        
        try {
            $oldData = [
                'name' => $permission->getName(),
                'key' => $permission->getKey()
            ];
            
            $permission->setName($name);
            $permission->setKey($key);
            
            $this->em->flush();
            
            $this->auditLogger->logUpdate(
                $currentUserId,
                'Permission',
                $permission->getId(),
                $oldData,
                ['name' => $name, 'key' => $key]
            );
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Yetki başarıyla güncellendi'
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Yetki güncellenirken hata: ' . $e->getMessage()
            ]));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
    
    /**
     * Yetki sil
     */
    public function deletePermission(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $currentUserId = $request->getAttribute('user_id');
        
        $permission = $this->em->find(Permission::class, $args['id']);
        
        if (!$permission) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Yetki bulunamadı'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }
        
        try {
            $this->auditLogger->logDelete(
                $currentUserId,
                'Permission',
                $permission->getId(),
                [
                    'name' => $permission->getName(),
                    'key' => $permission->getKey()
                ]
            );
            
            $this->em->remove($permission);
            $this->em->flush();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Yetki başarıyla silindi'
            ]));
            
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Yetki silinirken hata: ' . $e->getMessage()
            ]));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}

