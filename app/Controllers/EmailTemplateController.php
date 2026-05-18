<?php

declare(strict_types=1);

namespace App\Controllers;

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
     * Şablon listesi
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);

        // Liste için sadece gerekli alanları çek (body gibi büyük alanları bu sorguda taşıma)
        $templates = $this->em->createQueryBuilder()
            ->select('partial t.{id,name,subject,isGlobal,isApproved,createdAt}')
            ->from(EmailTemplate::class, 't')
            ->where('t.user = :user OR (t.isGlobal = :global AND t.isApproved = true)')
            ->setParameter('user', $user)
            ->setParameter('global', true)
            ->orderBy('t.isGlobal', 'DESC')
            ->addOrderBy('t.isApproved', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Kategorileri getir (yeni QueryBuilder instance)
        $categoryResults = $this->em->createQueryBuilder()
            ->select('DISTINCT t.category')
            ->from(EmailTemplate::class, 't')
            ->where('(t.user = :user OR t.isGlobal = :global)')
            ->andWhere('t.category IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('global', true)
            ->getQuery()
            ->getResult();

        $html = $this->twig->render('email-templates/index.twig', [
            'templates' => $templates,
            'categories' => array_column($categoryResults, 'category'),
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null
        ]);
        
        // Session mesajlarını temizle
        unset($_SESSION['success'], $_SESSION['error']);
        
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Şablon oluşturma formu
     */
    public function create(Request $request, Response $response): Response
    {
        $html = $this->twig->render('email-templates/create.twig');
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Şablon kaydetme
     */
    public function store(Request $request, Response $response): Response
    {
        $post = is_array($_POST ?? null) ? $_POST : [];
        $parsed = $request->getParsedBody();
        $parsed = is_array($parsed) ? $parsed : [];
        $data = array_merge($post, $parsed);

        $name = trim((string) ($data['name'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = (string) ($data['body'] ?? '');

        if (empty($name) || empty($subject) || empty($body)) {
            $_SESSION['error'] = 'Şablon adı, konu ve içerik zorunludur.';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }

        $user = $this->em->find(User::class, $_SESSION['user']['id']);

        try {
            $template = new EmailTemplate();
            $template->setUser($user);
            $template->setName($name);
            $template->setSubject($subject);
            $template->setBody($body);
            $template->setCategory(null);
            $template->setTags(null);
            $template->setIsGlobal(false);
            $template->setIsApproved(true); // Mail-only panelde doğrudan onaylı

            $this->em->persist($template);
            $this->em->flush();

            $_SESSION['success'] = 'Şablon oluşturuldu ve kullanıma hazır.';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);

        } catch (\Exception $e) {
            error_log('EmailTemplateController::store error: ' . $e->getMessage());
            $_SESSION['error'] = 'Şablon oluşturulurken bir hata oluştu.';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }
    }

    /**
     * Şablon düzenleme
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $template = $this->em->find(EmailTemplate::class, (int) $args['id']);

        if (!$template) {
            $_SESSION['error'] = 'Şablon bulunamadı';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }

        // Sadece kendi şablonları veya global şablonları düzenleyebilir
        if ($template->getUser() !== $user && !$template->isGlobal()) {
            $_SESSION['error'] = 'Bu şablonu düzenleme yetkiniz yok';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }

        $html = $this->twig->render('email-templates/edit.twig', [
            'template' => $template
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Şablon düzenleme datası (API - Modal için)
     */
    public function editData(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $template = $this->em->find(EmailTemplate::class, (int) $args['id']);

        if (!$template) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Şablon bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Sadece kendi şablonları veya admin global şablonları düzenleyebilir
        if ($template->getUser() !== $user && !($template->isGlobal() && $_SESSION['user']['role']['name'] === 'admin')) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Bu şablonu düzenleme yetkiniz yok'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'name' => $template->getName(),
            'subject' => $template->getSubject(),
            'body' => $template->getBody()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Şablon güncelleme
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $isAjax = strtolower((string) $request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
        $data = $request->getParsedBody();
        $data = is_array($data) ? $data : [];

        $name = trim((string) ($data['name'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));

        if ($name === '' || $subject === '' || $body === '') {
            if ($isAjax) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Şablon adı, konu ve içerik zorunludur.'
                ], 422);
            }
            $_SESSION['error'] = 'Şablon adı, konu ve içerik zorunludur.';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }

        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $template = $this->em->find(EmailTemplate::class, (int) $args['id']);

        if (!$template || ($template->getUser() !== $user && !($template->isGlobal() && $_SESSION['user']['role']['name'] === 'admin'))) {
            if ($isAjax) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Şablon bulunamadı veya erişim yok.'
                ], 404);
            }
            $_SESSION['error'] = 'Şablon bulunamadı veya erişim yok.';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }

        try {
            $template->setName($name);
            $template->setSubject($subject);
            $template->setBody($body);

            $this->em->flush();

            if ($isAjax) {
                return $this->json($response, [
                    'success' => true,
                    'message' => 'Şablon güncellendi.',
                    'item' => [
                        'id' => $template->getId(),
                        'name' => $template->getName(),
                        'subject' => $template->getSubject(),
                    ],
                ]);
            }

            $_SESSION['success'] = 'Şablon güncellendi.';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);

        } catch (\Exception $e) {
            error_log('EmailTemplateController::update error: ' . $e->getMessage());
            if ($isAjax) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Şablon güncellenirken bir hata oluştu.'
                ], 500);
            }
            $_SESSION['error'] = 'Şablon güncellenirken bir hata oluştu.';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }
    }

    /**
     * Şablon silme
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $template = $this->em->find(EmailTemplate::class, (int) $args['id']);

        if (!$template) {
            $_SESSION['error'] = 'Şablon bulunamadı';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }

        // Sadece kendi şablonlarını silebilir (global şablonlar sadece admin tarafından)
        if ($template->getUser() !== $user && $_SESSION['user']['role']['name'] !== 'admin') {
            $_SESSION['error'] = 'Bu şablonu silme yetkiniz yok';
            return $response->withHeader('Location', '/email-templates')->withStatus(302);
        }

        try {
            $this->em->remove($template);
            $this->em->flush();
            $_SESSION['success'] = 'Şablon silindi';
        } catch (\Exception $e) {
            error_log('EmailTemplateController::delete error: ' . $e->getMessage());
            $_SESSION['error'] = 'Şablon silinirken bir hata oluştu.';
        }

        return $response->withHeader('Location', '/email-templates')->withStatus(302);
    }

    /**
     * Şablon önizleme (API)
     */
    public function preview(Request $request, Response $response, array $args): Response
    {
        $template = $this->em->find(EmailTemplate::class, (int) $args['id']);

        if (!$template) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Şablon bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'id' => $template->getId(),
            'name' => $template->getName(),
            'subject' => $template->getSubject(),
            'body' => $template->getBody()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function sendTest(Request $request, Response $response, array $args): Response
    {
        try {
            $templateId = (int) ($args['id'] ?? 0);
            $payload = json_decode((string) $request->getBody(), true);
            if (!is_array($payload)) {
                $payload = $request->getParsedBody();
            }
            $payload = is_array($payload) ? $payload : [];

            $to = trim((string) ($payload['to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Geçerli bir alıcı email adresi girin.'
                ], 422);
            }

            $template = $this->em->find(EmailTemplate::class, $templateId);
            $currentUser = $this->em->find(User::class, (int) ($_SESSION['user']['id'] ?? 0));
            if (!$template || !$currentUser || !$this->canUserUseTemplate($template, $currentUser)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Şablon bulunamadı veya bu şablon için yetkiniz yok.'
                ], 404);
            }

            $variablesInput = $payload['variables'] ?? [];
            $variables = is_array($variablesInput) ? $variablesInput : [];

            $subject = $this->renderTemplateText($template->getSubject(), $variables);
            $body = $template->render($variables);

            $selector = new EmailSmtpSelector($this->em);
            $smtp = $selector->selectBestSmtp();
            if (!$smtp) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Aktif SMTP hesabı bulunamadı. SMTP ayarlarını kontrol edin.'
                ], 422);
            }

            $smtpService = new EmailSmtpService($this->em);
            $sendResult = $smtpService->sendEmail($smtp, $to, $subject, $body);
            if (!($sendResult['success'] ?? false)) {
                $message = (string) ($sendResult['message'] ?? 'Test mail gönderilemedi.');
                error_log('EmailTemplateController::sendTest smtp error: ' . $message);
                return $this->json($response, [
                    'success' => false,
                    'message' => $message
                ], 500);
            }

            return $this->json($response, [
                'success' => true,
                'message' => 'Test mail başarıyla gönderildi.'
            ]);
        } catch (\Throwable $e) {
            error_log('EmailTemplateController::sendTest error: ' . $e->getMessage());
            return $this->json($response, [
                'success' => false,
                'message' => 'Test mail gönderimi sırasında bir hata oluştu.'
            ], 500);
        }
    }

    private function canUserUseTemplate(EmailTemplate $template, User $user): bool
    {
        if ($template->isGlobal() && $template->isApproved()) {
            return true;
        }
        $owner = $template->getUser();
        return $owner && $owner->getId() === $user->getId();
    }

    private function renderTemplateText(string $text, array $variables): string
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

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

