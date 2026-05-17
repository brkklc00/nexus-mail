<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum TicketStatus: string
{
    case OPEN = 'open';
    case PENDING = 'pending';
    case CLOSED = 'closed';

    public function getLabel(): string
    {
        return match($this) {
            self::OPEN => 'Açık',
            self::PENDING => 'Beklemede',
            self::CLOSED => 'Kapalı',
        };
    }

    public function getBadgeClass(): string
    {
        return match($this) {
            self::OPEN => 'bg-success-subtle text-success',
            self::PENDING => 'bg-warning-subtle text-warning',
            self::CLOSED => 'bg-secondary-subtle text-secondary',
        };
    }
}

