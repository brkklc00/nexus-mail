<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum OrderNumberStatus: string
{
    case QUEUED = 'queued';
    case SENT = 'sent';
    case FAILED = 'failed';
    case SKIPPED_BLACKLIST = 'skipped_blacklist';
    case INVALID = 'invalid';

    public function getLabel(): string
    {
        return match($this) {
            self::QUEUED => 'Sırada',
            self::SENT => 'Gönderildi',
            self::FAILED => 'Başarısız',
            self::SKIPPED_BLACKLIST => 'Karaliste',
            self::INVALID => 'Geçersiz',
        };
    }
}

