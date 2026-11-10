<?php

namespace App\Enum;

enum PaymentStatus: string
{
    case Paid = 'PAID';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payé',
            self::PartiallyRefunded => 'Remboursé en partie',
            self::Refunded => 'Remboursé',
        };
    }
}
