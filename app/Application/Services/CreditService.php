<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\Credit;
use App\Domain\Entities\Transaction;
use App\Domain\Entities\User;
use App\Domain\Enum\TransactionType;
use Doctrine\ORM\EntityManager;
use RuntimeException;

class CreditService
{
    private EntityManager $em;
    private AuditLoggerService $auditLogger;

    public function __construct(EntityManager $em, AuditLoggerService $auditLogger)
    {
        $this->em = $em;
        $this->auditLogger = $auditLogger;
    }

    public function getOrCreateCredit(int $userId): Credit
    {
        $repo = $this->em->getRepository(Credit::class);
        // User üzerinden credit'i bul
        $user = $this->em->find(\App\Domain\Entities\User::class, $userId);
        
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        
        $credit = $user->getCredit();

        if (!$credit) {
            $credit = new Credit();
            $credit->setUser($user);
            $credit->setBalance(0);
            $this->em->persist($credit);
            $this->em->flush();
        }

        return $credit;
    }

    public function getBalance(int $userId): int
    {
        $credit = $this->getOrCreateCredit($userId);
        return $credit->getBalance();
    }

    public function hasEnough(int $userId, int $amount): bool
    {
        $credit = $this->getOrCreateCredit($userId);
        return $credit->hasEnough($amount);
    }

    public function add(int $userId, int $amount, float $unitPrice, ?array $meta = null): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Eklenecek kredi miktarı pozitif olmalıdır');
        }

        $this->em->beginTransaction();

        try {
            $credit = $this->getOrCreateCredit($userId);
            $oldBalance = $credit->getBalance();
            $credit->add($amount);
            $this->em->persist($credit);

            // Transaction kaydı oluştur
            $transaction = new Transaction();
            $transaction->setUser($this->em->getReference(User::class, $userId));
            $transaction->setType(TransactionType::CREDIT_ADDED);
            $transaction->setCredits($amount);
            $transaction->setUnitPrice(number_format($unitPrice, 4, '.', ''));
            $transaction->setTotalPrice(number_format($amount * $unitPrice, 2, '.', ''));
            $transaction->setCurrency('TRY');
            $transaction->setMeta($meta);
            $this->em->persist($transaction);

            $this->em->flush();
            $this->em->commit();

            // Audit log
            $this->auditLogger->log(
                $userId,
                'credit.added',
                'Credit',
                $credit->getId(),
                ['balance' => $oldBalance],
                ['balance' => $credit->getBalance(), 'amount' => $amount]
            );
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    public function deduct(int $userId, int $amount, float $unitPrice, ?array $meta = null): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Düşülecek kredi miktarı pozitif olmalıdır');
        }

        $this->em->beginTransaction();

        try {
            $credit = $this->getOrCreateCredit($userId);
            
            if (!$credit->hasEnough($amount)) {
                throw new RuntimeException('Yetersiz kredi bakiyesi');
            }

            $oldBalance = $credit->getBalance();
            $credit->deduct($amount);
            $this->em->persist($credit);

            // Transaction kaydı oluştur
            $transaction = new Transaction();
            $transaction->setUser($this->em->getReference(User::class, $userId));
            $transaction->setType(TransactionType::ORDER_PAYMENT);
            $transaction->setCredits(-$amount);
            $transaction->setUnitPrice(number_format($unitPrice, 4, '.', ''));
            $transaction->setTotalPrice(number_format($amount * $unitPrice, 2, '.', ''));
            $transaction->setCurrency('TRY');
            $transaction->setMeta($meta);
            $this->em->persist($transaction);

            $this->em->flush();
            $this->em->commit();

            // Audit log
            $this->auditLogger->log(
                $userId,
                'credit.deducted',
                'Credit',
                $credit->getId(),
                ['balance' => $oldBalance],
                ['balance' => $credit->getBalance(), 'amount' => $amount]
            );
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    public function refund(int $userId, int $amount, float $unitPrice, ?array $meta = null): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('İade edilecek kredi miktarı pozitif olmalıdır');
        }

        $this->em->beginTransaction();

        try {
            $credit = $this->getOrCreateCredit($userId);
            $oldBalance = $credit->getBalance();
            $credit->add($amount);
            $this->em->persist($credit);

            // Transaction kaydı oluştur
            $transaction = new Transaction();
            $transaction->setUser($this->em->getReference(User::class, $userId));
            $transaction->setType(TransactionType::REFUND);
            $transaction->setCredits($amount);
            $transaction->setUnitPrice(number_format($unitPrice, 4, '.', ''));
            $transaction->setTotalPrice(number_format($amount * $unitPrice, 2, '.', ''));
            $transaction->setCurrency('TRY');
            $transaction->setMeta($meta);
            $this->em->persist($transaction);

            $this->em->flush();
            $this->em->commit();

            // Audit log
            $this->auditLogger->log(
                $userId,
                'credit.refunded',
                'Credit',
                $credit->getId(),
                ['balance' => $oldBalance],
                ['balance' => $credit->getBalance(), 'amount' => $amount]
            );
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    /**
     * Manuel kredi ekleme (admin tarafından)
     */
    public function addCredit(int $userId, int $amount, string $reason = 'admin_add', ?string $note = null): void
    {
        $meta = ['reason' => $reason];
        if ($note) {
            $meta['note'] = $note;
        }
        
        // unitPrice 0 olarak ekle (ücretsiz ekleme)
        $this->add($userId, $amount, 0.0, $meta);
    }

    /**
     * Manuel kredi çıkarma (admin tarafından)
     */
    public function deductCredit(int $userId, int $amount, string $reason = 'admin_deduct', ?string $note = null): void
    {
        $meta = ['reason' => $reason];
        if ($note) {
            $meta['note'] = $note;
        }
        
        // unitPrice 0 olarak çıkar (ücretsiz çıkarma)
        $this->deduct($userId, $amount, 0.0, $meta);
    }
}

