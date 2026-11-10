<?php

namespace App\Enum;

enum TripStatus: string
{
    case Published = 'PUBLISHED';
    case Cancelled = 'CANCELLED';
    case Completed = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Publié',
            self::Cancelled => 'Annulé',
            self::Completed => 'Terminé',
        };
    }
}
