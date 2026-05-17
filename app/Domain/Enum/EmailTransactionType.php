<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum EmailTransactionType: string
{
    case CREDIT = 'credit';   // Bakiye ekleme
    case DEBIT = 'debit';     // Bakiye çıkarma (sipariş)
    case REFUND = 'refund';   // İade

    /** Eski / harici kayıtlar (DB’de büyük harf) */
    case CREDIT_LEGACY = 'CREDIT';
    case DEBIT_LEGACY = 'DEBIT';
    case REFUND_LEGACY = 'REFUND';

    public function canonical(): self
    {
        return match ($this) {
            self::CREDIT_LEGACY => self::CREDIT,
            self::DEBIT_LEGACY => self::DEBIT,
            self::REFUND_LEGACY => self::REFUND,
            default => $this,
        };
    }
}

