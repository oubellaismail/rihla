<?php

namespace App\DataFixtures;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Payment;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Service\Geo\RouteEnricher;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Realistic Moroccan demo data. Dates are relative to "now" so the demo
 * always has upcoming trips, past trips with reviews, and a cancellation.
 *
 * Every demo account uses the password DEMO_PASSWORD (documented in INSTALL.md).
 */
class AppFixtures extends Fixture
{
    public const DEMO_PASSWORD = 'rihla2026';

    /** name => [latitude, longitude] */
    private const CITIES = [
        'Agadir' => [30.4278, -9.5981],
        'Essaouira' => [31.5085, -9.7595],
        'Chichaoua' => [31.5442, -8.7655],
        'Marrakech' => [31.6295, -7.9811],
        'Casablanca' => [33.5731, -7.5898],
        'Rabat' => [34.0209, -6.8416],
        'Tanger' => [35.7595, -5.8340],
        'Fès' => [34.0331, -5.0003],
        'Tiznit' => [29.6974, -9.7316],
        'Taroudant' => [30.4703, -8.8770],
        'Ouarzazate' => [30.9189, -6.8934],
        'El Jadida' => [33.2316, -8.5007],
    ];

    /** "city|meeting point" => [latitude, longitude] of the exact meeting place (real places). */
    private const MEETING_POINTS = [
        'Agadir|Gare routière, avenue des FAR' => [30.4202, -9.5982],
        'Agadir|Marina d\'Agadir' => [30.4194, -9.6310],
        'Essaouira|Bab Marrakech' => [31.5126, -9.7700],
        'Essaouira|Place Moulay Hassan' => [31.5120, -9.7692],
        'Marrakech|Gare ferroviaire' => [31.6301, -8.0089],
        'Marrakech|Bab Doukkala' => [31.6392, -8.0189],
        'Marrakech|Gare routière de Bab Doukkala' => [31.6392, -8.0189],
        'Casablanca|Casa Voyageurs' => [33.5899, -7.5911],
        'Ouarzazate|Place Al Mouahidine' => [30.9203, -6.8929],
        'Tiznit|Bab Oulad Jarrar' => [29.7000, -9.7330],
    ];

    /** @var array<string, City> */
    private array $cities = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RouteEnricher $routeEnricher,
    )
    {
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::CITIES as $name => [$lat, $lng]) {
            $city = (new City())->setName($name)->setLatitude($lat)->setLongitude($lng);
            $manager->persist($city);
            $this->cities[$name] = $city;
        }

        $admin = $this->user($manager, 'admin@rihla.ma', 'Admin', 'Rihla', null, ['ROLE_ADMIN']);
        $karim = $this->user($manager, 'karim@rihla.ma', 'Karim', 'Amrani', '0661234501', [], '-2 years');
        $salma = $this->user($manager, 'salma@rihla.ma', 'Salma', 'Bennani', '0661234502', [], '-14 months');
        $youssef = $this->user($manager, 'youssef@rihla.ma', 'Youssef', 'El Idrissi', '0661234503', [], '-1 year');
        $mehdi = $this->user($manager, 'mehdi@rihla.ma', 'Mehdi', 'Rami', '0661234504', [], '-9 months');
        $nadia = $this->user($manager, 'nadia@rihla.ma', 'Nadia', 'Kabbaj', '0661234505', [], '-18 months');
        $hamid = $this->user($manager, 'hamid@rihla.ma', 'Hamid', 'Ouali', '0661234506', [], '-3 years');
        $imane = $this->user($manager, 'imane@rihla.ma', 'Imane', 'Tazi', '0661234507', [], '-7 months');
        $lina = $this->user($manager, 'lina@rihla.ma', 'Lina', 'Alaoui', '0661234508', [], '-5 months');
        $omar = $this->user($manager, 'omar@rihla.ma', 'Omar', 'Berrada', '0661234509', [], '-4 months');
        $rachid = $this->user($manager, 'rachid@rihla.ma', 'Rachid', 'Kettani', '0661234510', [], '-2 months');

        $logan = $this->vehicle($manager, $karim, 'Dacia Logan', 'bleue', 4, 530);
        $clio = $this->vehicle($manager, $nadia, 'Peugeot 208', 'rouge', 4, 310);
        $lodgy = $this->vehicle($manager, $hamid, 'Dacia Lodgy', 'grise', 6, 827);
        $i20 = $this->vehicle($manager, $imane, 'Hyundai i20', 'blanche', 4, 311);

        // Upcoming: the flagship trip with stops, used everywhere in the mockup.
        $flagship = $this->trip($manager, $karim, $logan, 3, '+2 days', [
            ['Agadir', '08:00', 'Gare routière, avenue des FAR', 175, 60],
            ['Essaouira', '09:45', 'Bab Marrakech', 190, 65],
            ['Marrakech', '12:00', 'Gare ferroviaire', 240, 85],
            ['Casablanca', '15:00', 'Casa Voyageurs', null, null],
        ]);
        $this->book($manager, $flagship, $youssef, 0, 1, 1, BookingStatus::Confirmed, ['A1'], ['cabin' => 1]);
        $this->book($manager, $flagship, $mehdi, 0, 1, 1, BookingStatus::Confirmed, ['B3'], ['large' => 1]); // gets off at Essaouira: B3 is freed on the way
        $this->book($manager, $flagship, $rachid, 0, 3, 1, BookingStatus::Confirmed, ['B1'], ['large' => 1]);
        $this->book($manager, $flagship, $salma, 1, 2, 1, BookingStatus::Confirmed, ['B3'], ['medium' => 1]);
        $this->book($manager, $flagship, $lina, 2, 3, 1, BookingStatus::Confirmed, ['A1']);
        $this->book($manager, $flagship, $omar, 2, 3, 1, BookingStatus::Confirmed, ['B3'], ['medium' => 2]);

        $this->trip($manager, $nadia, $clio, 3, '+2 days', [
            ['Essaouira', '07:30', 'Place Moulay Hassan', 190, 70],
            ['Marrakech', '10:10', 'Bab Doukkala', null, null],
        ]);

        $ouarzazate = $this->trip($manager, $hamid, $lodgy, 6, '+3 days', [
            ['Marrakech', '09:00', 'Gare routière de Bab Doukkala', 200, 110],
            ['Ouarzazate', '13:00', 'Place Al Mouahidine', null, null],
        ]);
        $this->book($manager, $ouarzazate, $omar, 0, 1, 2, BookingStatus::Confirmed, ['B1', 'B3'], ['large' => 2, 'medium' => 2]);

        $this->trip($manager, $imane, $i20, 3, '+4 days', [
            ['Agadir', '17:00', 'Marina d\'Agadir', 90, 35],
            ['Tiznit', '18:30', 'Bab Oulad Jarrar', null, null],
        ]);

        // Past and completed: reviews in both directions.
        $past = $this->trip($manager, $karim, $logan, 3, '-10 days', [
            ['Agadir', '08:00', 'Gare routière, avenue des FAR', 250, 90],
            ['Marrakech', '11:10', 'Gare ferroviaire', null, null],
        ], TripStatus::Completed);
        $b1 = $this->book($manager, $past, $mehdi, 0, 1, 1, BookingStatus::Completed, ['A1'], ['medium' => 1]);
        $b2 = $this->book($manager, $past, $salma, 0, 1, 1, BookingStatus::Completed, ['B1'], ['cabin' => 2]);
        $this->review($manager, $b1, $mehdi, $karim, 5, 'Voiture propre, trajet tranquille jusqu\'à Marrakech.');
        $this->review($manager, $b1, $karim, $mehdi, 5, 'Passager ponctuel et agréable.');
        $this->review($manager, $b2, $salma, $karim, 5, 'Ponctuel, prudent, et il connaît toutes les bonnes pauses.');

        $pastTiznit = $this->trip($manager, $imane, $i20, 3, '-8 days', [
            ['Agadir', '17:00', 'Marina d\'Agadir', 90, 35],
            ['Tiznit', '18:30', 'Bab Oulad Jarrar', null, null],
        ], TripStatus::Completed);
        $b3 = $this->book($manager, $pastTiznit, $salma, 0, 1, 1, BookingStatus::Completed, ['A1'], ['large' => 1]);
        $this->review($manager, $b3, $imane, $salma, 5, 'Passagère à l\'heure, merci !');

        // Cancelled by the driver: fully refunded.
        $cancelled = $this->trip($manager, $hamid, $lodgy, 6, '-13 days', [
            ['Agadir', '07:00', 'Gare routière, avenue des FAR', 250, 90],
            ['Marrakech', '10:15', 'Bab Doukkala', null, null],
        ], TripStatus::Cancelled);
        $b4 = $this->book($manager, $cancelled, $salma, 0, 1, 1, BookingStatus::CancelledByDriver, ['A1']);
        $b4->getPayment()?->refund($b4->getTotalPrice());

        $manager->flush();
    }

    /** @param list<string> $roles */
    private function user(ObjectManager $manager, string $email, string $first, string $last, ?string $phone, array $roles = [], string $since = '-1 year'): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($first)
            ->setLastName($last)
            ->setPhone($phone)
            ->setRoles($roles)
            ->setCreatedAt(new \DateTimeImmutable($since));
        $user->setPassword($this->hasher->hashPassword($user, self::DEMO_PASSWORD));
        $manager->persist($user);

        return $user;
    }

    private function vehicle(ObjectManager $manager, User $owner, string $model, string $color, int $seats, int $trunkLitres): Vehicle
    {
        $vehicle = (new Vehicle())->setOwner($owner)->setModel($model)->setColor($color)->setPassengerSeats($seats)->setTrunkLitres($trunkLitres);
        $manager->persist($vehicle);

        return $vehicle;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: ?int, 4: ?int}> $stops [city, time, meeting point, km to next, price to next]
     */
    private function trip(ObjectManager $manager, User $driver, Vehicle $vehicle, int $seats, string $day, array $stops, TripStatus $status = TripStatus::Published): Trip
    {
        $trip = (new Trip())->setDriver($driver)->setVehicle($vehicle)->setSeatsOffered($seats)->setTrunkLitres($vehicle->getTrunkLitres())->setStatus($status);
        $date = new \DateTimeImmutable($day);
        $trip->setCreatedAt($date->modify('-5 days'));

        foreach ($stops as [$city, $time, $meetingPoint, $km, $price]) {
            [$h, $m] = array_map('intval', explode(':', $time));
            $exact = self::MEETING_POINTS[$city . '|' . $meetingPoint] ?? [null, null];
            $trip->addStop((new TripStop())
                ->setCity($this->cities[$city])
                ->setMeetingLatitude($exact[0])
                ->setMeetingLongitude($exact[1])
                ->setDepartureAt($date->setTime($h, $m))
                ->setMeetingPoint($meetingPoint)
                ->setLegDistanceKmToNext($km)
                ->setLegPriceToNext($price));
        }
        // Real road distance, time and line when the network is up; degrades to the km given above offline.
        $this->routeEnricher->enrich($trip);
        $manager->persist($trip);

        return $trip;
    }

    private function book(ObjectManager $manager, Trip $trip, User $passenger, int $from, int $to, int $seats = 1, BookingStatus $status = BookingStatus::Confirmed, array $seatCodes = [], array $bags = []): Booking
    {
        $stops = $trip->getStops()->getValues();
        $unitPrice = 0;
        for ($i = $from; $i < $to; ++$i) {
            $unitPrice += (int) $stops[$i]->getLegPriceToNext();
        }

        $booking = (new Booking())
            ->setTrip($trip)
            ->setPassenger($passenger)
            ->setFromStop($stops[$from])
            ->setToStop($stops[$to])
            ->setSeats($seats)
            ->setSeatCodes($seatCodes)
            ->setBags($bags)
            ->setTotalPrice($unitPrice * $seats)
            ->setStatus($status)
            ->setCreatedAt($trip->getCreatedAt()->modify('+1 day'));
        if ($status->isCancelled()) {
            $booking->setCancelledAt($stops[$from]->getDepartureAt()->modify('-1 day'));
        }
        $trip->getBookings()->add($booking);
        $manager->persist($booking);

        $payment = (new Payment())->setAmount($booking->getTotalPrice())->setPaidAt($booking->getCreatedAt());
        $booking->setPayment($payment);
        $manager->persist($payment);

        return $booking;
    }

    private function review(ObjectManager $manager, Booking $booking, User $author, User $target, int $rating, string $comment): void
    {
        $review = (new Review())
            ->setBooking($booking)
            ->setAuthor($author)
            ->setTarget($target)
            ->setRating($rating)
            ->setComment($comment)
            ->setCreatedAt($booking->getToStop()->getDepartureAt()->modify('+1 day'));
        $manager->persist($review);
    }
}
