<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum EmailOrderStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED_FOR_DISPATCH = 'approved_for_dispatch';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}

