<?php

namespace App\Tests\Support;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;

/**
 * Builds trips and bookings in memory (no database) for unit tests.
 *
 * Default trip: Agadir (08:00) → Essaouira (09:45) → Marrakech (12:00) → Casablanca (15:00)
 * on 2026-11-12, 3 seats offered, leg prices 60 / 65 / 85 MAD.
 */
final class TripBuilder
{
    public const DAY = '2026-11-12';

    public static function user(string $firstName = 'Salma'): User
    {
        return (new User())
            ->setEmail(strtolower($firstName) . '@test.ma')
            ->setFirstName($firstName)
            ->setLastName('Test');
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?int}> $stops [city, HH:MM, price to next]
     */
    public static function trip(int $seatsOffered = 3, ?array $stops = null, ?User $driver = null): Trip
    {
        $stops ??= [
            ['Agadir', '08:00', 60],
            ['Essaouira', '09:45', 65],
            ['Marrakech', '12:00', 85],
            ['Casablanca', '15:00', null],
        ];
        $driver ??= self::user('Karim');
        $vehicle = (new Vehicle())->setOwner($driver)->setModel('Dacia Logan')->setColor('bleue')->setPassengerSeats(4);
        $trip = (new Trip())->setDriver($driver)->setVehicle($vehicle)->setSeatsOffered($seatsOffered);

        foreach ($stops as [$city, $time, $price]) {
            $trip->addStop((new TripStop())
                ->setCity((new City())->setName($city))
                ->setDepartureAt(new \DateTimeImmutable(self::DAY . ' ' . $time))
                ->setMeetingPoint('Centre-ville')
                ->setLegPriceToNext($price));
        }

        return $trip;
    }

    public static function stop(Trip $trip, int $position): TripStop
    {
        return $trip->getStops()->get($position) ?? throw new \OutOfRangeException("No stop at position $position");
    }

    public static function booking(Trip $trip, int $from, int $to, int $seats = 1, BookingStatus $status = BookingStatus::Confirmed, ?User $passenger = null): Booking
    {
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger ?? self::user('Passager' . $from . $to))
            ->setFromStop(self::stop($trip, $from))
            ->setToStop(self::stop($trip, $to))
            ->setSeats($seats)
            ->setStatus($status);
        $trip->getBookings()->add($booking);

        return $booking;
    }
}
