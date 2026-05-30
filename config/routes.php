<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\UserController;
use App\Controllers\RoleController;
use App\Controllers\SupportTicketController;
use App\Controllers\SettingsController;
use App\Controllers\ApiController;
use App\Controllers\EmailSmtpController;
use App\Controllers\EmailDashboardController;
use App\Controllers\EmailPhoneBookController;
use App\Controllers\EmailOrderController;
use App\Controllers\EmailBlacklistController;
use App\Controllers\EmailTransactionController;
use App\Controllers\EmailTemplateController;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\GuestMiddleware;
use App\Middlewares\PermissionMiddleware;
use Doctrine\ORM\EntityManager;
use Twig\Environment;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Csrf\Guard as CsrfGuard;

return function (App $app) {
    $container = $app->getContainer();
    // CSRF Guard - şimdilik devre dışı
    // $responseFactory = $app->getResponseFactory();
    // $csrfGuard = new CsrfGuard($responseFactory);
    // $app->add($csrfGuard);

    // Postman Collection Routes
    $app->get('/postman/transactional-email', [\App\Controllers\PostmanController::class, 'transactionalEmailCollection'])->setName('postman.transactional-email');

    // WordPress REST API benzeri endpoint
    $app->get('/wp-json/nexus/v1', [\App\Controllers\PostmanController::class, 'restApiIndex'])->setName('rest.api.index');
    $app->get('/api/rest', [\App\Controllers\PostmanController::class, 'restApiIndex'])->setName('rest.api.index.alt');

    // API Routes (No auth middleware - uses API token)
    $app->group('/api', function (RouteCollectorProxy $group) {
        // Email Campaigns API
        $group->get('/email-campaigns/pending', [ApiController::class, 'getPendingEmailCampaigns'])->setName('api.email-campaigns.pending');
        $group->post('/email-campaigns/{id}/claim', [ApiController::class, 'claimEmailCampaign'])->setName('api.email-campaigns.claim');
        $group->post('/email-campaigns/{id}/worker-control', [ApiController::class, 'controlEmailCampaignWorker'])->setName('api.email-campaigns.worker-control');
        $group->get('/email-campaigns/{id}/runtime-metrics', [ApiController::class, 'getEmailCampaignRuntimeMetrics'])->setName('api.email-campaigns.runtime-metrics');
        $group->post('/email-campaigns/cleanup-stuck', [ApiController::class, 'cleanupStuckEmailCampaigns'])->setName('api.email-campaigns.cleanup-stuck');
        $group->get('/email-campaigns/{id}/emails/batch', [ApiController::class, 'getEmailCampaignEmailsBatch'])->setName('api.email-campaigns.emails.batch');
        $group->post('/email-campaigns/{id}/status', [ApiController::class, 'updateEmailCampaignStatus'])->setName('api.email-campaigns.status');
        $group->post('/email-campaigns/{id}/emails', [ApiController::class, 'updateEmailCampaignEmails'])->setName('api.email-campaigns.emails');
        $group->post('/email-campaigns/{id}/refund', [ApiController::class, 'refundFailedEmails'])->setName('api.email-campaigns.refund');
        $group->get('/smtp/select-best', [ApiController::class, 'selectBestSmtp'])->setName('api.smtp.select-best');
        $group->post('/smtp/{id}/usage', [ApiController::class, 'recordSmtpUsage'])->setName('api.smtp.usage');
        $group->get('/email-sending/runtime-config', [ApiController::class, 'getEmailSendingRuntimeConfig'])->setName('api.email-sending.runtime-config');

        // API Key Management
        $group->post('/regenerate-key', [ApiController::class, 'regenerateApiKey'])->setName('api.regenerate-key');

        // Email SMTP API
        $group->get('/email-smtp/select-best', [\App\Controllers\EmailSmtpController::class, 'selectBest'])->setName('api.email-smtp.select');
        $group->post('/email-smtp/{id}/record-usage', [\App\Controllers\EmailSmtpController::class, 'recordUsage'])->setName('api.email-smtp.record');

        // Email Blacklist API
        $group->get('/email-blacklist', [\App\Controllers\EmailBlacklistController::class, 'apiGetBlacklist'])->setName('api.email-blacklist.get');

    });

    // Guest routes (login, register, etc.)
    $app->group('', function (RouteCollectorProxy $group) {
        $group->get('/login', [AuthController::class, 'showLogin'])->setName('auth.login');
        $group->post('/login', [AuthController::class, 'login'])->setName('auth.login.post');
        $group->get('/register', [AuthController::class, 'showRegister'])->setName('auth.register');
        $group->post('/register', [AuthController::class, 'register'])->setName('auth.register.post');
        $group->get('/forgot-password', [AuthController::class, 'showForgotPassword'])->setName('auth.forgot');
        $group->post('/forgot-password', [AuthController::class, 'forgotPassword'])->setName('auth.forgot.post');
    })->add(GuestMiddleware::class);

    // 2FA routes
    $app->group('/2fa', function (RouteCollectorProxy $group) {
        $group->get('/verify', [AuthController::class, 'show2FA'])->setName('auth.2fa');
        $group->post('/verify', [AuthController::class, 'verify2FA'])->setName('auth.2fa.post');
    });

    // Authenticated routes
    $app->group('', function (RouteCollectorProxy $group) use ($container, $app) {
        // Logout
        $group->post('/logout', [AuthController::class, 'logout'])->setName('auth.logout');

        // Dashboard
        $group->get('/', [DashboardController::class, 'index'])->setName('dashboard');
        $group->get('/dashboard', [DashboardController::class, 'index'])->setName('dashboard.alt');

        // Users
        $group->group('/users', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [UserController::class, 'index'])->setName('users.index')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read'));
            $group->get('/create', [UserController::class, 'create'])->setName('users.create')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'create'));
            $group->post('', [UserController::class, 'store'])->setName('users.store')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'create'));
            $group->post('/create', [UserController::class, 'store'])->setName('users.store.alt')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'create'));
            $group->get('/{id}/edit-data', [UserController::class, 'editData'])->setName('users.edit.data')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->get('/{id}/edit', [UserController::class, 'edit'])->setName('users.edit')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/edit', [UserController::class, 'update'])->setName('users.update.post')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->put('/{id}', [UserController::class, 'update'])->setName('users.update')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/impersonate', [UserController::class, 'impersonate'])->setName('users.impersonate')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read'));
            $group->post('/stop-impersonate', [UserController::class, 'stopImpersonate'])->setName('users.stop.impersonate');
            $group->post('/{id}/delete', [UserController::class, 'destroy'])->setName('users.delete')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'delete'));
            $group->post('/{id}/toggle', [UserController::class, 'toggle'])->setName('users.toggle')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/reset-password', [UserController::class, 'resetPassword'])->setName('users.reset.password')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/reset-2fa', [UserController::class, 'reset2FA'])->setName('users.reset.2fa')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/mail-credit/add', [UserController::class, 'addMailCredit'])->setName('users.mail-credit.add')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/mail-credit/deduct', [UserController::class, 'deductMailCredit'])->setName('users.mail-credit.deduct')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/toggle-refund', [UserController::class, 'toggleRefund'])->setName('users.toggle.refund')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/bulk-action', [UserController::class, 'bulkAction'])->setName('users.bulk.action')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->get('/export', [UserController::class, 'exportExcel'])->setName('users.export')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read'));
            $group->get('/{id}', [UserController::class, 'show'])->setName('users.show')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read'));
            // Yönetim Paneli: Hesap Yöneticisi personel yönetimi
            $group->post('/{id}/make-account-manager', [UserController::class, 'makeAccountManager'])->setName('users.make.account.manager')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->get('/{id}/manage-accounts-data', [UserController::class, 'manageAccountsData'])->setName('users.manage.accounts.data')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read'));
            $group->post('/{id}/add-child-user', [UserController::class, 'addChildUser'])->setName('users.add.child')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{accountManagerId}/remove-child-user/{childUserId}', [UserController::class, 'removeChildUser'])->setName('users.remove.child')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            // Sayfa erişim kontrolü
            $group->get('/{id}/page-access', [UserController::class, 'getPageAccess'])->setName('users.get.page.access')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read'));
            $group->post('/{id}/update-page-access', [UserController::class, 'updatePageAccess'])->setName('users.update.page.access')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
        });

        // Roles
        $group->group('/roles', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [RoleController::class, 'index'])->setName('roles.index')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'read'));
            $group->get('/create', [RoleController::class, 'create'])->setName('roles.create')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'create'));
            $group->post('', [RoleController::class, 'store'])->setName('roles.store')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'create'));
            $group->post('/create', [RoleController::class, 'store'])->setName('roles.store.alt')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'create'));
            $group->get('/{id}/edit', [RoleController::class, 'edit'])->setName('roles.edit')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'update'));
            $group->get('/{id}/edit-data', [RoleController::class, 'editData'])->setName('roles.edit.data')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'update'));
            $group->post('/{id}/edit', [RoleController::class, 'update'])->setName('roles.update.post')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'update'));
            $group->put('/{id}', [RoleController::class, 'update'])->setName('roles.update')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'update'));
            $group->post('/{id}/delete', [RoleController::class, 'destroy'])->setName('roles.delete')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'delete'));
            $group->delete('/{id}', [RoleController::class, 'destroy'])->setName('roles.destroy')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'delete'));
            $group->post('/{id}/clone', [RoleController::class, 'clone'])->setName('roles.clone')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'create'));
            $group->post('/create-from-template', [RoleController::class, 'createFromTemplate'])->setName('roles.template')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'create'));
            $group->get('/compare', [RoleController::class, 'compare'])->setName('roles.compare')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'read'));
            $group->get('/{id}/export', [RoleController::class, 'exportJson'])->setName('roles.export')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'read'));
            $group->get('/{id}/preview', [RoleController::class, 'preview'])->setName('roles.preview')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'read'));
            $group->post('/bulk-action', [RoleController::class, 'bulkAction'])->setName('roles.bulk')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'delete'));
            $group->get('/{id}', [RoleController::class, 'show'])->setName('roles.show')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'read'));
        });

        // Permissions (Yetki) Yönetimi
        $group->group('/permissions', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->post('', [RoleController::class, 'createPermission'])->setName('permissions.create')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'create'));
            $group->post('/{id}', [RoleController::class, 'updatePermission'])->setName('permissions.update')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'update'));
            $group->post('/{id}/delete', [RoleController::class, 'deletePermission'])->setName('permissions.delete')
                ->add(new PermissionMiddleware($em, $twig, 'role', 'delete'));
        });

        // Support Tickets
        $group->group('/tickets', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [SupportTicketController::class, 'index'])->setName('tickets.index')
                ->add(new PermissionMiddleware($em, $twig, 'support_ticket', 'read'));
            $group->get('/create', [SupportTicketController::class, 'create'])->setName('tickets.create')
                ->add(new PermissionMiddleware($em, $twig, 'support_ticket', 'create'));
            $group->post('', [SupportTicketController::class, 'store'])->setName('tickets.store')
                ->add(new PermissionMiddleware($em, $twig, 'support_ticket', 'create'));
            $group->get('/{id}/detail', [SupportTicketController::class, 'detail'])->setName('tickets.detail')
                ->add(new PermissionMiddleware($em, $twig, 'support_ticket', 'read'));
            $group->post('/{id}/reply', [SupportTicketController::class, 'reply'])->setName('tickets.reply')
                ->add(new PermissionMiddleware($em, $twig, 'support_ticket', 'create'));
            $group->post('/{id}/close', [SupportTicketController::class, 'close'])->setName('tickets.close')
                ->add(new PermissionMiddleware($em, $twig, 'support_ticket', 'update'));
            $group->get('/{id}', [SupportTicketController::class, 'show'])->setName('tickets.show')
                ->add(new PermissionMiddleware($em, $twig, 'support_ticket', 'read'));
        });

        // Admin: Support Tickets Management
        $group->group('/admin/support-tickets', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [SupportTicketController::class, 'adminIndex'])->setName('admin.tickets.index')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read')); // Admin yetkisi gerekli
            $group->get('/{id}/detail', [SupportTicketController::class, 'adminDetail'])->setName('admin.tickets.detail')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'read'));
            $group->post('/{id}/close', [SupportTicketController::class, 'adminClose'])->setName('admin.tickets.close')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
            $group->post('/{id}/reply', [SupportTicketController::class, 'adminReply'])->setName('admin.tickets.reply')
                ->add(new PermissionMiddleware($em, $twig, 'user', 'update'));
        });


        // Admin: Email Orders Management
        $group->group('/admin/email-orders', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\EmailOrderController::class, 'index'])->setName('admin.email-orders.index')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'read'));
            $group->get('/{id}/details', [\App\Controllers\Admin\EmailOrderController::class, 'getDetails'])->setName('admin.email-orders.details')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'read'));
            $group->post('/{id}/approve', [\App\Controllers\Admin\EmailOrderController::class, 'approve'])->setName('admin.email-orders.approve')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'write'));
            $group->post('/{id}/update-template', [\App\Controllers\Admin\EmailOrderController::class, 'updateTemplate'])->setName('admin.email-orders.update-template')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'write'));
            $group->post('/{id}/cancel', [\App\Controllers\Admin\EmailOrderController::class, 'cancel'])->setName('admin.email-orders.cancel')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'write'));
            $group->post('/{id}/restart', [\App\Controllers\Admin\EmailOrderController::class, 'restartCampaign'])->setName('admin.email-orders.restart')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'write'));
            $group->post('/{id}/delete', [\App\Controllers\Admin\EmailOrderController::class, 'delete'])->setName('admin.email-orders.delete')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'write'));
            $group->post('/bulk-delete', [\App\Controllers\Admin\EmailOrderController::class, 'bulkDelete'])->setName('admin.email-orders.bulk-delete')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_orders', 'write'));
        });

        // Admin: Email Templates Management
        $group->group('/admin/email-templates', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\EmailTemplateController::class, 'index'])->setName('admin.email-templates.index')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'read'));
            $group->get('/create', [\App\Controllers\Admin\EmailTemplateController::class, 'create'])->setName('admin.email-templates.create')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'write'));
            $group->post('', [\App\Controllers\Admin\EmailTemplateController::class, 'store'])->setName('admin.email-templates.store')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'write'));
            $group->get('/{id}/edit-data', [\App\Controllers\Admin\EmailTemplateController::class, 'editData'])->setName('admin.email-templates.edit-data')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'read'));
            $group->get('/{id}/preview', [\App\Controllers\Admin\EmailTemplateController::class, 'preview'])->setName('admin.email-templates.preview')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'read'));
            $group->post('/{id}/approve', [\App\Controllers\Admin\EmailTemplateController::class, 'approve'])->setName('admin.email-templates.approve')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'write'));
            $group->post('/{id}/reject', [\App\Controllers\Admin\EmailTemplateController::class, 'reject'])->setName('admin.email-templates.reject')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'write'));
            $group->post('/{id}/update', [\App\Controllers\Admin\EmailTemplateController::class, 'update'])->setName('admin.email-templates.update')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'write'));
        });

        $group->group('/api/email-templates', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\EmailTemplateController::class, 'apiList'])->setName('admin.api.email-templates.list')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'read'));
            $group->get('/users', [\App\Controllers\Admin\EmailTemplateController::class, 'apiUsers'])->setName('admin.api.email-templates.users')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'read'));
            $group->get('/{id}', [\App\Controllers\Admin\EmailTemplateController::class, 'apiShow'])->setName('admin.api.email-templates.show')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'read'));
            $group->post('/{id}/send-test', [\App\Controllers\Admin\EmailTemplateController::class, 'sendTest'])->setName('admin.api.email-templates.send-test')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_templates', 'write'));
        });

        // Admin: Email Phonebooks Management
        $group->group('/admin/email-phonebooks', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\EmailPhonebookController::class, 'index'])->setName('admin.email-phonebooks.index')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_phonebooks', 'read'));
            $group->get('/{id}/download', [\App\Controllers\Admin\EmailPhonebookController::class, 'download'])->setName('admin.email-phonebooks.download')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_phonebooks', 'read'));
        });

        // Admin: Email Transactions Management
        $group->group('/admin/email-transactions', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\EmailTransactionController::class, 'index'])->setName('admin.email-transactions.index')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_transactions', 'read'));
        });

        // Admin: Email Blacklists Management
        $group->group('/admin/email-blacklists', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\EmailBlacklistController::class, 'index'])->setName('admin.email-blacklists.index')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_blacklists', 'read'));
            $group->get('/user/{userId}/emails', [\App\Controllers\Admin\EmailBlacklistController::class, 'getEmails'])->setName('admin.email-blacklists.emails')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_blacklists', 'read'));
            $group->post('/record/{id}/update', [\App\Controllers\Admin\EmailBlacklistController::class, 'update'])->setName('admin.email-blacklists.update')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_blacklists', 'update'));
            $group->post('/record/{id}/delete', [\App\Controllers\Admin\EmailBlacklistController::class, 'delete'])->setName('admin.email-blacklists.delete')
                ->add(new PermissionMiddleware($em, $twig, 'admin_email_blacklists', 'delete'));
        });

        // Admin: Email SMTP Management
        $group->group('/admin/email-smtp', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailSmtpController::class, 'index'])->setName('email-smtp.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'read'));
            $group->post('', [\App\Controllers\EmailSmtpController::class, 'store'])->setName('email-smtp.store')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'create'));
            $group->get('/{id}/edit', [\App\Controllers\EmailSmtpController::class, 'edit'])->setName('email-smtp.edit')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->post('/{id}/update', [\App\Controllers\EmailSmtpController::class, 'update'])->setName('email-smtp.update')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->post('/{id}/delete', [\App\Controllers\EmailSmtpController::class, 'delete'])->setName('email-smtp.delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'delete'));
            $group->post('/{id}/toggle', [\App\Controllers\EmailSmtpController::class, 'toggleStatus'])->setName('email-smtp.toggle')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->post('/bulk-status', [\App\Controllers\EmailSmtpController::class, 'bulkSetStatus'])->setName('email-smtp.bulk-status')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->post('/{id}/test', [\App\Controllers\EmailSmtpController::class, 'test'])->setName('email-smtp.test')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'read'));
            $group->post('/{id}/reset-limit', [\App\Controllers\EmailSmtpController::class, 'resetSingleLimits'])->setName('email-smtp.reset-single')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->get('/{id}/domain-check', [\App\Controllers\EmailSmtpController::class, 'domainCheck'])->setName('email-smtp.domain-check')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'read'));
            $group->post('/test-connection', [\App\Controllers\EmailSmtpController::class, 'testConnection'])->setName('email-smtp.test-connection')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'read'));
            $group->post('/reset-limits', [\App\Controllers\EmailSmtpController::class, 'resetLimits'])->setName('email-smtp.reset')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->post('/delivery-reports/sync', [\App\Controllers\EmailSmtpController::class, 'syncDeliveryReports'])->setName('email-smtp.delivery-reports.sync')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->post('/bulk-import', [\App\Controllers\EmailSmtpController::class, 'bulkImport'])->setName('email-smtp.bulk-import')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'create'));
            $group->post('/bulk-delete', [\App\Controllers\EmailSmtpController::class, 'bulkDelete'])->setName('email-smtp.bulk-delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'delete'));
        });

        $group->group('/admin/email-sending-settings', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailSendingSettingsController::class, 'index'])->setName('email-sending-settings.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'read'));
            $group->post('/save', [\App\Controllers\EmailSendingSettingsController::class, 'save'])->setName('email-sending-settings.save')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
            $group->post('/apply-preset', [\App\Controllers\EmailSendingSettingsController::class, 'applyPreset'])->setName('email-sending-settings.apply-preset')
                ->add(new PermissionMiddleware($em, $twig, 'email_smtp', 'update'));
        });

        // Email Dashboard
        $group->get('/email-dashboard', [\App\Controllers\EmailDashboardController::class, 'index'])->setName('email.dashboard');

        // Email Phonebooks
        $group->group('/email-phonebooks', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailPhoneBookController::class, 'index'])->setName('email.phonebooks.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_phonebook', 'read'));
            $group->post('', [\App\Controllers\EmailPhoneBookController::class, 'store'])->setName('email.phonebooks.store')
                ->add(new PermissionMiddleware($em, $twig, 'email_phonebook', 'create'));
            $group->get('/{id}/download', [\App\Controllers\EmailPhoneBookController::class, 'download'])->setName('email.phonebooks.download')
                ->add(new PermissionMiddleware($em, $twig, 'email_phonebook', 'read'));
            $group->post('/{id}/delete', [\App\Controllers\EmailPhoneBookController::class, 'delete'])->setName('email.phonebooks.delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_phonebook', 'delete'));
            $group->get('/{id}/detail', [\App\Controllers\EmailPhoneBookController::class, 'detail'])->setName('email.phonebooks.detail')
                ->add(new PermissionMiddleware($em, $twig, 'email_phonebook', 'read'));
            $group->get('/{id}', [\App\Controllers\EmailPhoneBookController::class, 'redirectToDetail'])->setName('email.phonebooks.show')
                ->add(new PermissionMiddleware($em, $twig, 'email_phonebook', 'read'));
        });

        // Email Orders
        $group->group('/email-orders', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailOrderController::class, 'index'])->setName('email.orders.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_order', 'read'));
            $group->post('', [\App\Controllers\EmailOrderController::class, 'store'])->setName('email.orders.store')
                ->add(new PermissionMiddleware($em, $twig, 'email_order', 'create'));
            $group->get('/{id}/details', [\App\Controllers\EmailOrderController::class, 'getDetails'])->setName('email.orders.details')
                ->add(new PermissionMiddleware($em, $twig, 'email_order', 'read'));
            $group->get('/{id}', [\App\Controllers\EmailOrderController::class, 'redirectToList'])->setName('email.orders.show')
                ->add(new PermissionMiddleware($em, $twig, 'email_order', 'read'));
        });

        // Email Blacklist
        $group->group('/email-blacklist', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailBlacklistController::class, 'index'])->setName('email.blacklist.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_blacklist', 'read'));
            $group->post('', [\App\Controllers\EmailBlacklistController::class, 'store'])->setName('email.blacklist.store')
                ->add(new PermissionMiddleware($em, $twig, 'email_blacklist', 'create'));
            $group->post('/{id}/delete', [\App\Controllers\EmailBlacklistController::class, 'delete'])->setName('email.blacklist.delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_blacklist', 'delete'));
            $group->post('/bulk-delete', [\App\Controllers\EmailBlacklistController::class, 'bulkDelete'])->setName('email.blacklist.bulk-delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_blacklist', 'delete'));
            $group->post('/delete-all', [\App\Controllers\EmailBlacklistController::class, 'deleteAll'])->setName('email.blacklist.delete-all')
                ->add(new PermissionMiddleware($em, $twig, 'email_blacklist', 'delete'));
        });

        // Email Transactions
        $group->group('/email-transactions', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailTransactionController::class, 'index'])->setName('email.transactions.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_transactions', 'read'));
            $group->get('/export', [\App\Controllers\EmailTransactionController::class, 'export'])->setName('email.transactions.export')
                ->add(new PermissionMiddleware($em, $twig, 'email_transactions', 'read'));
        });

        // GEÇİCİ: Bireysel Mail web sayfası (/transactional-email) kapatıldı — tekrar açılırken aşağıdaki group'u geri ekleyin.
        // API uçları (/api/transactional-email/send, /history) açık kalır.
        /*
        $group->group('/transactional-email', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\TransactionalEmailController::class, 'index'])->setName('transactional-email.index')
                ->add(new PermissionMiddleware($em, $twig, 'transactional_email', 'read'));
            $group->post('/send', [\App\Controllers\TransactionalEmailController::class, 'send'])->setName('transactional-email.send')
                ->add(new PermissionMiddleware($em, $twig, 'transactional_email', 'create'));
            $group->get('/{id}/details', [\App\Controllers\TransactionalEmailController::class, 'details'])->setName('transactional-email.details')
                ->add(new PermissionMiddleware($em, $twig, 'transactional_email', 'read'));
        });
        */

        // URL Shortener
        $group->group('/url-shortener', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            // Static routes önce (önemli!)
            $group->get('', [\App\Controllers\UrlShortenerController::class, 'index'])->setName('url-shortener.index')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'read'));
            $group->get('/update-clicks', [\App\Controllers\UrlShortenerController::class, 'updateClickCounts'])->setName('url-shortener.update-clicks')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'read'));
            $group->get('/more-url/refresh', [\App\Controllers\UrlShortenerController::class, 'refreshMoreUrlDomains'])->setName('url-shortener.more-url.refresh')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'read'));
            $group->get('/more-url/{id}', [\App\Controllers\UrlShortenerController::class, 'moreUrlDetail'])->setName('url-shortener.more-url.detail')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'read'));

            // Dynamic routes sonra
            $group->get('/{id}/details', [\App\Controllers\UrlShortenerController::class, 'getDetails'])->setName('url-shortener.details')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'read'));

            // POST routes
            $group->post('', [\App\Controllers\UrlShortenerController::class, 'store'])->setName('url-shortener.store')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'create'));
            $group->post('/{id}/update', [\App\Controllers\UrlShortenerController::class, 'update'])->setName('url-shortener.update')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'create'));
            $group->post('/{id}/delete', [\App\Controllers\UrlShortenerController::class, 'delete'])->setName('url-shortener.delete')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'delete'));
            $group->post('/more-url/create', [\App\Controllers\UrlShortenerController::class, 'createMoreUrl'])->setName('url-shortener.more-url.create')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'create'));
            $group->post('/more-url/{id}/delete', [\App\Controllers\UrlShortenerController::class, 'deleteMoreUrl'])->setName('url-shortener.more-url.delete')
                ->add(new PermissionMiddleware($em, $twig, 'url_shortener', 'delete'));
        });

        // Email Templates
        $group->group('/email-templates', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailTemplateController::class, 'index'])->setName('email.templates.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'read'));
            $group->get('/create', [\App\Controllers\EmailTemplateController::class, 'create'])->setName('email.templates.create')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'create'));
            $group->post('', [\App\Controllers\EmailTemplateController::class, 'store'])->setName('email.templates.store')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'create'));
            $group->get('/{id}/edit', [\App\Controllers\EmailTemplateController::class, 'edit'])->setName('email.templates.edit')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'update'));
            $group->get('/{id}/edit-data', [\App\Controllers\EmailTemplateController::class, 'editData'])->setName('email.templates.edit-data')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'read'));
            $group->post('/{id}/update', [\App\Controllers\EmailTemplateController::class, 'update'])->setName('email.templates.update')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'update'));
            $group->post('/{id}/delete', [\App\Controllers\EmailTemplateController::class, 'delete'])->setName('email.templates.delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'delete'));
            $group->get('/{id}/preview', [\App\Controllers\EmailTemplateController::class, 'preview'])->setName('email.templates.preview')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'read'));
            $group->post('/{id}/send-test', [\App\Controllers\EmailTemplateController::class, 'sendTest'])->setName('email.templates.send-test')
                ->add(new PermissionMiddleware($em, $twig, 'email_template', 'read'));
        });

        // Admin: Email Data Pool (Mail Havuzu)
        $group->group('/admin/email-data-pool', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\EmailDataPoolController::class, 'index'])->setName('admin.email-data-pool.index')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->post('', [\App\Controllers\EmailDataPoolController::class, 'store'])->setName('admin.email-data-pool.store')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'create'));
            $group->post('/remove', [\App\Controllers\EmailDataPoolController::class, 'remove'])->setName('admin.email-data-pool.remove')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'delete'));
            $group->post('/lists', [\App\Controllers\EmailDataPoolController::class, 'storeList'])->setName('admin.email-data-pool.lists.store')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'create'));
            $group->post('/lists/{id}/update', [\App\Controllers\EmailDataPoolController::class, 'updateList'])->setName('admin.email-data-pool.lists.update')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'update'));
            $group->post('/lists/{id}/delete', [\App\Controllers\EmailDataPoolController::class, 'deleteList'])->setName('admin.email-data-pool.lists.delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'delete'));
            $group->post('/{id}/delete', [\App\Controllers\EmailDataPoolController::class, 'delete'])->setName('admin.email-data-pool.delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'delete'));
            $group->post('/bulk-delete', [\App\Controllers\EmailDataPoolController::class, 'bulkDelete'])->setName('admin.email-data-pool.bulk-delete')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'delete'));
            $group->post('/bulk-activate', [\App\Controllers\EmailDataPoolController::class, 'bulkActivate'])->setName('admin.email-data-pool.bulk-activate')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'update'));
            $group->post('/bulk-deactivate', [\App\Controllers\EmailDataPoolController::class, 'bulkDeactivate'])->setName('admin.email-data-pool.bulk-deactivate')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'update'));
            $group->post('/delete-all', [\App\Controllers\EmailDataPoolController::class, 'deleteAll'])->setName('admin.email-data-pool.delete-all')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'delete'));
            $group->get('/export-excel', [\App\Controllers\EmailDataPoolController::class, 'exportExcel'])->setName('admin.email-data-pool.export-excel')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->get('/export-csv', [\App\Controllers\EmailDataPoolController::class, 'exportCsv'])->setName('admin.email-data-pool.export-csv')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->get('/export-txt', [\App\Controllers\EmailDataPoolController::class, 'exportTxt'])->setName('admin.email-data-pool.export-txt')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->get('/{listId}/cleaner/analyze', [\App\Controllers\EmailDataPoolController::class, 'cleanerAnalyze'])->setName('admin.email-data-pool.cleaner.analyze')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->get('/{listId}/cleaner/export-non-gmail', [\App\Controllers\EmailDataPoolController::class, 'cleanerExportNonGmail'])->setName('admin.email-data-pool.cleaner.export-non-gmail')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->post('/{listId}/cleaner/delete-non-gmail', [\App\Controllers\EmailDataPoolController::class, 'cleanerDeleteNonGmail'])->setName('admin.email-data-pool.cleaner.delete-non-gmail')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'delete'));
            $group->post('/{listId}/cleaner/fix-gmail-typos', [\App\Controllers\EmailDataPoolController::class, 'cleanerFixGmailTypos'])->setName('admin.email-data-pool.cleaner.fix-gmail-typos')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'update'));
            $group->post('/{listId}/cleaner/remove-duplicates', [\App\Controllers\EmailDataPoolController::class, 'cleanerRemoveDuplicates'])->setName('admin.email-data-pool.cleaner.remove-duplicates')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'delete'));
            $group->get('/{listId}/stats', [\App\Controllers\EmailDataPoolController::class, 'stats'])->setName('admin.email-data-pool.stats')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->post('/tools/preview', [\App\Controllers\EmailDataPoolController::class, 'toolsPreview'])->setName('admin.email-data-pool.tools.preview')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
            $group->post('/tools/fill-to-target', [\App\Controllers\EmailDataPoolController::class, 'toolsFillToTarget'])->setName('admin.email-data-pool.tools.fill-to-target')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'update'));
            $group->post('/tools/move-overflow', [\App\Controllers\EmailDataPoolController::class, 'toolsMoveOverflow'])->setName('admin.email-data-pool.tools.move-overflow')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'update'));
            $group->post('/tools/split-list', [\App\Controllers\EmailDataPoolController::class, 'toolsSplitList'])->setName('admin.email-data-pool.tools.split-list')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'update'));
            $group->get('/tools/leftovers/{token}', [\App\Controllers\EmailDataPoolController::class, 'downloadLeftoverFile'])->setName('admin.email-data-pool.tools.leftovers.download')
                ->add(new PermissionMiddleware($em, $twig, 'email_data_pool', 'read'));
        });

        // Settings
        $group->group('/settings', function (RouteCollectorProxy $group) {
            $group->get('/profile', [SettingsController::class, 'profile'])->setName('settings.profile');
            $group->post('/profile', [SettingsController::class, 'updateProfile'])->setName('settings.profile.update.post');
            $group->put('/profile', [SettingsController::class, 'updateProfile'])->setName('settings.profile.update');
            $group->get('/security/2fa/enable', [SettingsController::class, 'enable2FA'])->setName('settings.2fa.enable.get');
            $group->post('/security/2fa/enable', [SettingsController::class, 'enable2FA'])->setName('settings.2fa.enable');
            $group->post('/security/2fa/confirm', [SettingsController::class, 'confirm2FA'])->setName('settings.2fa.confirm');
            $group->post('/security/2fa/disable', [SettingsController::class, 'disable2FA'])->setName('settings.2fa.disable');
            $group->post('/security/password', [SettingsController::class, 'updatePassword'])->setName('settings.password.update.post');
            $group->put('/security/password', [SettingsController::class, 'updatePassword'])->setName('settings.password.update');
            $group->post('/security/api-key/generate', [SettingsController::class, 'generateApiKey'])->setName('settings.api-key.generate');
            $group->post('/security/api-key/revoke', [SettingsController::class, 'revokeApiKey'])->setName('settings.api-key.revoke');
        });

        // API: Balance History (Auth required)
        $group->get('/api/balance/history', [SettingsController::class, 'getBalanceHistory'])->setName('api.balance.history')
            ->add(new AuthMiddleware($container->get(EntityManager::class)));

        // Admin: Domain Settings
        $group->group('/admin/domain-settings', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\DomainSettingsController::class, 'index'])->setName('admin.domain-settings.index')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'read'));
            $group->post('', [\App\Controllers\Admin\DomainSettingsController::class, 'store'])->setName('admin.domain-settings.store')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'create'));
            $group->get('/{domain}/config', [\App\Controllers\Admin\DomainSettingsController::class, 'getConfig'])->setName('admin.domain-settings.get-config')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'read'));
            $group->post('/{domain}', [\App\Controllers\Admin\DomainSettingsController::class, 'update'])->setName('admin.domain-settings.update')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'update'));
            $group->post('/{domain}/delete', [\App\Controllers\Admin\DomainSettingsController::class, 'destroy'])->setName('admin.domain-settings.delete')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'delete'));
        });

        // Admin: URL Shortener Settings
        $group->group('/admin/url-shortener-settings', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\UrlShortenerSettingsController::class, 'index'])->setName('admin.url-shortener-settings.index')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'read'));
            $group->post('/{id}/update', [\App\Controllers\Admin\UrlShortenerSettingsController::class, 'update'])->setName('admin.url-shortener-settings.update')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'update'));
            $group->post('/bulk-toggle', [\App\Controllers\Admin\UrlShortenerSettingsController::class, 'bulkToggle'])->setName('admin.url-shortener-settings.bulk-toggle')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'update'));
            $group->post('/bulk-update', [\App\Controllers\Admin\UrlShortenerSettingsController::class, 'bulkUpdate'])->setName('admin.url-shortener-settings.bulk-update')
                ->add(new PermissionMiddleware($em, $twig, 'settings', 'update'));
        });

        // Admin: System Monitor
        $group->group('/admin/system-monitor', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'index'])->setName('admin.system-monitor.index')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/stats', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'stats'])->setName('admin.system-monitor.stats')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/metrics', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'metrics'])->setName('admin.system-monitor.metrics')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/system-check', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'systemCheck'])->setName('admin.system-monitor.system-check')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/maintenance-status', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'maintenanceStatus'])->setName('admin.system-monitor.maintenance-status')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->post('/maintenance/toggle', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'toggleMaintenance'])->setName('admin.system-monitor.maintenance-toggle')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'update'));
            $group->get('/logs', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'logs'])->setName('admin.system-monitor.logs')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/logs/{name}/tail', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'logTail'])->setName('admin.system-monitor.log-tail')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/logs/{name}/download', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'downloadLog'])->setName('admin.system-monitor.log-download')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->post('/logs/{name}/clear', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'clearLog'])->setName('admin.system-monitor.log-clear')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'delete'));
            $group->post('/logs/clear-all', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'clearAllLogsAction'])->setName('admin.system-monitor.logs-clear-all')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'delete'));
            $group->post('/clear-logs', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'clearLogs'])->setName('admin.system-monitor.clear-logs')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'delete'));
            $group->get('/worker-log/{worker}', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'viewWorkerLog'])->setName('admin.system-monitor.worker-log')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/download-worker-log/{worker}', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'downloadWorkerLog'])->setName('admin.system-monitor.download-worker-log')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->post('/backup/database', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'createDatabaseBackup'])->setName('admin.system-monitor.backup-database')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'create'));
            $group->post('/backup/files', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'createFileBackup'])->setName('admin.system-monitor.backup-files')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'create'));
            $group->get('/backups', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'listBackups'])->setName('admin.system-monitor.list-backups')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/download-backup', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'downloadBackup'])->setName('admin.system-monitor.download-backup')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->post('/restore-database', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'restoreDatabaseBackup'])->setName('admin.system-monitor.restore-database')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'update'));
            $group->post('/delete-backup', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'deleteBackup'])->setName('admin.system-monitor.delete-backup')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'delete'));
            $group->get('/files', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'listFiles'])->setName('admin.system-monitor.list-files')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/file', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'readFile'])->setName('admin.system-monitor.read-file')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/file/download', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'downloadFile'])->setName('admin.system-monitor.download-file')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->post('/file/save', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'saveFile'])->setName('admin.system-monitor.save-file')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'update'));
            $group->post('/file/create', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'createFile'])->setName('admin.system-monitor.create-file')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'create'));
            $group->post('/file/upload', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'uploadFile'])->setName('admin.system-monitor.upload-file')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'create'));
            $group->post('/file/delete', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'deleteFile'])->setName('admin.system-monitor.delete-file')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'delete'));
            $group->post('/file/rename', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'renameFile'])->setName('admin.system-monitor.rename-file')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'update'));
        });

        $group->get('/admin/server-management', [\App\Controllers\Admin\SystemMonitorManagerController::class, 'index'])->setName('admin.server-management.index')
            ->add(new PermissionMiddleware($container->get(EntityManager::class), $container->get(Environment::class), 'system_monitor', 'read'));

        $group->group('/admin/worker-terminal', function (RouteCollectorProxy $group) use ($container) {
            $em = $container->get(EntityManager::class);
            $twig = $container->get(Environment::class);

            $group->get('', [\App\Controllers\Admin\WorkerTerminalController::class, 'index'])->setName('admin.worker-terminal.index')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/status', [\App\Controllers\Admin\WorkerTerminalController::class, 'status'])->setName('admin.worker-terminal.status')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/logs', [\App\Controllers\Admin\WorkerTerminalController::class, 'logs'])->setName('admin.worker-terminal.logs')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->get('/stream', [\App\Controllers\Admin\WorkerTerminalController::class, 'stream'])->setName('admin.worker-terminal.stream')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'read'));
            $group->post('/action', [\App\Controllers\Admin\WorkerTerminalController::class, 'action'])->setName('admin.worker-terminal.action')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'update'));
            $group->post('/command', [\App\Controllers\Admin\WorkerTerminalController::class, 'command'])->setName('admin.worker-terminal.command')
                ->add(new PermissionMiddleware($em, $twig, 'system_monitor', 'update'));
        });

    })->add(AuthMiddleware::class);
};
