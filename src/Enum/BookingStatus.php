<?php

namespace App\Enum;

enum BookingStatus: string
{
    case Confirmed = 'CONFIRMED';
    case CancelledByPassenger = 'CANCELLED_BY_PASSENGER';
    case CancelledByDriver = 'CANCELLED_BY_DRIVER';
    case Completed = 'COMPLETED';

    /** Only confirmed bookings hold seats on their legs. */
    public function holdsSeats(): bool
    {
        return self::Confirmed === $this;
    }

    /** Confirmed or completed: the passenger is travelling or has travelled (display only). */
    public function occupiedSeat(): bool
    {
        return self::Confirmed === $this || self::Completed === $this;
    }

    public function isCancelled(): bool
    {
        return self::CancelledByPassenger === $this || self::CancelledByDriver === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmée',
            self::CancelledByPassenger => 'Annulée par le passager',
            self::CancelledByDriver => 'Annulée par le conducteur',
            self::Completed => 'Terminée',
        };
    }
}
