<?php

namespace App\Enum;

/** What a seat is for a given portion of the trip. */
enum SeatState: string
{
    case Free = 'free';
    /** Held by someone on another part of the trip only (e.g. a passenger who gets off before this portion). */
    case Freed = 'freed';
    case Taken = 'taken';
    case NotOffered = 'not_offered';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'libre',
            self::Freed => 'libérée en route',
            self::Taken => 'occupée',
            self::NotOffered => 'non proposée',
        };
    }

    /** A passenger can pick this seat. */
    public function isOpen(): bool
    {
        return self::Free === $this || self::Freed === $this;
    }
}
