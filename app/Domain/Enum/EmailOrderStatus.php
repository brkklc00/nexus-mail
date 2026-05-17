<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum EmailOrderStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}

