<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\TransactionalEmail;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;

class TransactionalEmailService
{
    public function __construct(
        private EntityManagerInterface $em,
        private EmailSmtpSelector $smtpSelector,
        private EmailSmtpService $smtpService
    ) {
    }

    /**
     * İşlemsel email gönder
     */
    public function sendTransactionalEmail(
        User $user,
        string $toEmail,
        string $subject,
        string $body,
        ?string $toName = null,
        ?string $fromEmail = null,
        ?string $fromName = null
    ): array {
        try {
            // Bakiye kontrolü
            if ($user->getEmailTransactionalBalance() <= 0) {
                return [
                    'success' => false,
                    'message' => 'Yetersiz işlemsel email bakiyesi'
                ];
            }

            // Email validation
            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Geçersiz email adresi'
                ];
            }

            // TransactionalEmail kaydı oluştur
            $transEmail = new TransactionalEmail();
            $transEmail->setUser($user);
            $transEmail->setToEmail($toEmail);
            $transEmail->setToName($toName);
            $transEmail->setSubject($subject);
            $transEmail->setBody($body);
            $transEmail->setFromEmail($fromEmail);
            $transEmail->setFromName($fromName);
            $transEmail->setStatus('pending');

            $this->em->persist($transEmail);
            $this->em->flush();

            // SMTP seç ve gönder
            $smtp = $this->smtpSelector->selectBestSmtp();
            
            if (!$smtp) {
                $transEmail->setStatus('failed');
                $transEmail->setError('Aktif SMTP hesabı bulunamadı');
                $this->em->flush();
                
                return [
                    'success' => false,
                    'message' => 'Aktif SMTP hesabı bulunamadı'
                ];
            }

            // Email gönder
            try {
                $result = $this->smtpService->sendEmail(
                    $smtp,
                    $toEmail,
                    $subject,
                    $body
                );
                
                if ($result['success']) {
                    // Başarılı
                    $transEmail->setStatus('sent');
                    $transEmail->setMessageId($result['message_id'] ?? null);
                    $transEmail->setSmtpAccount($smtp);
                    
                    // Bakiyeden düş
                    $user->setEmailTransactionalBalance($user->getEmailTransactionalBalance() - 1);
                    
                    $this->em->persist($user);
                    $this->em->flush();
                    
                    return [
                        'success' => true,
                        'message' => 'Email başarıyla gönderildi',
                        'email_id' => $transEmail->getId(),
                        'remaining_balance' => $user->getEmailTransactionalBalance()
                    ];
                } else {
                    // Başarısız
                    $transEmail->setStatus('failed');
                    $transEmail->setError($result['message'] ?? 'Bilinmeyen hata');
                    $this->em->flush();
                    
                    return [
                        'success' => false,
                        'message' => 'Email gönderilemedi: ' . ($result['message'] ?? 'Bilinmeyen hata')
                    ];
                }
            } catch (\Exception $e) {
                $transEmail->setStatus('failed');
                $transEmail->setError($e->getMessage());
                $this->em->flush();
                
                return [
                    'success' => false,
                    'message' => 'Email gönderme hatası: ' . $e->getMessage()
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Kullanıcının email geçmişi
     */
    public function getHistory(User $user, int $limit = 50, int $offset = 0): array
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(TransactionalEmail::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * İstatistikler
     */
    public function getStats(User $user, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->from(TransactionalEmail::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user);

        if ($dateFrom) {
            $qb->andWhere('t.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($dateFrom . ' 00:00:00'));
        }

        if ($dateTo) {
            $qb->andWhere('t.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        // Total
        $qbTotal = clone $qb;
        $total = (int) $qbTotal->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        // Sent
        $qbSent = clone $qb;
        $sent = (int) $qbSent->select('COUNT(t.id)')
            ->andWhere('t.status = :status')
            ->setParameter('status', 'sent')
            ->getQuery()
            ->getSingleScalarResult();

        // Failed
        $qbFailed = clone $qb;
        $failed = (int) $qbFailed->select('COUNT(t.id)')
            ->andWhere('t.status = :status')
            ->setParameter('status', 'failed')
            ->getQuery()
            ->getSingleScalarResult();

        // Pending
        $qbPending = clone $qb;
        $pending = (int) $qbPending->select('COUNT(t.id)')
            ->andWhere('t.status = :status')
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
            'success_rate' => $total > 0 ? round(($sent / $total) * 100, 1) : 0
        ];
    }
}

