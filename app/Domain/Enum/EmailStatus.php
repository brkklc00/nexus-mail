<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum EmailStatus: string
{
    case PENDING = 'pending';
    case LOCKED = 'locked';
    case SENDING = 'sending';
    case RETRY = 'retry';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case BOUNCED = 'bounced';
    case FAILED = 'failed';
    case SKIPPED_BLACKLIST = 'skipped_blacklist';
    case SUPPRESSED = 'suppressed';

    public function getLabel(): string
    {
        return match($this) {
            self::PENDING => 'Beklemede',
            self::LOCKED => 'Kilitli',
            self::SENDING => 'Gönderiliyor',
            self::RETRY => 'Yeniden denenecek',
            self::SENT => 'Gönderildi',
            self::DELIVERED => 'Teslim Edildi',
            self::BOUNCED => 'Geri Döndü',
            self::FAILED => 'Başarısız',
            self::SKIPPED_BLACKLIST => 'Atlandı - Karaliste',
            self::SUPPRESSED => 'Baskılandı',
        };
    }

    public function getBadgeClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-warning',
            self::LOCKED => 'bg-secondary',
            self::SENDING => 'bg-info',
            self::RETRY => 'bg-warning',
            self::SENT => 'bg-info',
            self::DELIVERED => 'bg-success',
            self::BOUNCED => 'bg-danger',
            self::FAILED => 'bg-danger',
            self::SKIPPED_BLACKLIST => 'bg-secondary',
            self::SUPPRESSED => 'bg-dark',
        };
    }
}

