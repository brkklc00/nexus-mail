<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case SCHEDULED = 'scheduled';
    case PROCESSING = 'processing';
    case PARTIAL = 'partial';
    case SENT = 'sent';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Beklemede',
            self::SCHEDULED => 'Planlandı',
            self::PROCESSING => 'İşleniyor',
            self::PARTIAL => 'Kısmi',
            self::SENT => 'Gönderildi',
            self::FAILED => 'Başarısız',
            self::CANCELLED => 'İptal',
        };
    }

    public function getBadgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-warning-subtle text-warning',
            self::SCHEDULED => 'bg-info-subtle text-info',
            self::PROCESSING => 'bg-primary-subtle text-primary',
            self::PARTIAL => 'bg-warning-subtle text-warning',
            self::SENT => 'bg-success-subtle text-success',
            self::FAILED => 'bg-danger-subtle text-danger',
            self::CANCELLED => 'bg-secondary-subtle text-secondary',
        };
    }
}

