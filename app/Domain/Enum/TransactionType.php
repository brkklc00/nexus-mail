<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum TransactionType: string
{
    case ORDER_PAYMENT = 'order_payment';
    case CREDIT_ADDED = 'credit_added';
    case REFUND = 'refund';
    case ADJUSTMENT = 'adjustment';

    public function getLabel(): string
    {
        return match($this) {
            self::ORDER_PAYMENT => 'Sipariş Ödemesi',
            self::CREDIT_ADDED => 'Kredi Eklendi',
            self::REFUND => 'İade',
            self::ADJUSTMENT => 'Düzeltme',
        };
    }
}

