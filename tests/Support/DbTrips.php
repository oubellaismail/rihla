<?php

namespace App\Tests\Support;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Payment;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists trips and bookings for integration tests (test database only).
 *
 * Default trip: Agadir 08:00 → Essaouira 09:45 → Marrakech 12:00 → Casablanca 15:00
 * on 2026-11-12, leg prices 60 / 65 / 85 MAD.
 */
final class DbTrips
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function user(string $firstName): User
    {
        $user = (new User())
            ->setEmail(strtolower($firstName) . '.' . bin2hex(random_bytes(4)) . '@test.ma')
            ->setFirstName($firstName)
            ->setLastName('Test')
            ->setPassword('not-used-in-tests');
        $this->em->persist($user);

        return $user;
    }

    public function trip(int $seatsOffered = 3, ?User $driver = null): Trip
    {
        $driver ??= $this->user('Karim');
        $vehicle = (new Vehicle())->setOwner($driver)->setModel('Dacia Logan')->setColor('bleue')->setPassengerSeats(4);
        $this->em->persist($vehicle);
        $trip = (new Trip())->setDriver($driver)->setVehicle($vehicle)->setSeatsOffered($seatsOffered);

        foreach ([['Agadir', '08:00', 60], ['Essaouira', '09:45', 65], ['Marrakech', '12:00', 85], ['Casablanca', '15:00', null]] as [$name, $time, $price]) {
            $trip->addStop((new TripStop())
                ->setCity($this->city($name))
                ->setDepartureAt(new \DateTimeImmutable(TripBuilder::DAY . ' ' . $time))
                ->setMeetingPoint('Centre-ville')
                ->setLegPriceToNext($price));
        }
        $this->em->persist($trip);
        $this->em->flush();

        return $trip;
    }

    /** An existing booking created directly (bypassing the service), e.g. to fill a leg. */
    public function booking(Trip $trip, int $from, int $to, int $seats = 1, BookingStatus $status = BookingStatus::Confirmed, ?User $passenger = null): Booking
    {
        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger ?? $this->user('Passager'))
            ->setFromStop(self::stop($trip, $from))
            ->setToStop(self::stop($trip, $to))
            ->setSeats($seats)
            ->setTotalPrice(100 * $seats)
            ->setStatus($status);
        $booking->setPayment((new Payment())->setAmount($booking->getTotalPrice()));
        $trip->getBookings()->add($booking);
        $this->em->persist($booking);
        $this->em->persist($booking->getPayment());
        $this->em->flush();

        return $booking;
    }

    public static function stop(Trip $trip, int $position): TripStop
    {
        return $trip->getStops()->get($position) ?? throw new \OutOfRangeException("No stop at position $position");
    }

    private function city(string $name): City
    {
        $city = $this->em->getRepository(City::class)->findOneBy(['name' => $name]);
        if (!$city) {
            $city = (new City())->setName($name);
            $this->em->persist($city);
        }

        return $city;
    }
}
