<?php

namespace App\DataFixtures;

use App\Entity\Booking;
use App\Entity\City;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\Review;
use App\Entity\Trip;
use App\Entity\TripStop;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\BookingStatus;
use App\Enum\LuggageSize;
use App\Enum\TripStatus;
use App\Service\DriverNotifier;
use App\Service\Geo\RouteEnricher;
use App\Service\Luggage\TrunkSpace;
use App\Service\Seat\SeatLayout;
use App\Service\TripChangeService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Realistic Moroccan demo data. Dates are relative to "now" so the demo always has upcoming trips (the first one
 * leaves within hours, the last in three weeks), two months of past trips with reviews both ways, and cancellations.
 * Everything is deterministic: no randomness, the same data on every load.
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
        'Agadir|Gare routière CTM' => [30.4199, -9.5806],
        'Agadir|Marina d\'Agadir' => [30.4194, -9.6310],
        'Essaouira|Bab Marrakech' => [31.5126, -9.7700],
        'Essaouira|Place Moulay Hassan' => [31.5120, -9.7692],
        'Marrakech|Gare ferroviaire' => [31.6301, -8.0089],
        'Marrakech|Bab Doukkala' => [31.6392, -8.0189],
        'Marrakech|Gare routière de Bab Doukkala' => [31.6392, -8.0189],
        'Marrakech|Place Jemaa el-Fna' => [31.6258, -7.9891],
        'Marrakech|Place du 16 Novembre' => [31.6339, -8.0127],
        'Casablanca|Casa Voyageurs' => [33.5899, -7.5911],
        'Casablanca|Gare routière Ouled Ziane' => [33.5667, -7.5547],
        'Casablanca|Casa Port' => [33.6018, -7.6176],
        'Rabat|Gare Rabat Ville' => [34.0150, -6.8340],
        'Rabat|Gare Rabat Agdal' => [33.9979, -6.8528],
        'Tanger|Gare Tanger Ville' => [35.7705, -5.7740],
        'Fès|Gare ONCF de Fès' => [34.0506, -4.9933],
        'Ouarzazate|Place Al Mouahidine' => [30.9203, -6.8929],
        'Tiznit|Bab Oulad Jarrar' => [29.7000, -9.7330],
        'Taroudant|Place Assarag' => [30.4727, -8.8755],
        'El Jadida|Place Mohammed V' => [33.2541, -8.5087],
        'Chichaoua|Place du marché' => [31.5400, -8.7640],
    ];

    /**
     * Corridors the scheduled trips reuse. Each stop: [city, minutes after departure, meeting point, km to next,
     * price to next]. Prices follow about 0.35 MAD per km, rounded to 5.
     */
    private const ROUTES = [
        'agadir-marrakech' => [['Agadir', 0, 'Gare routière, avenue des FAR', 255, 90], ['Marrakech', 195, 'Gare routière de Bab Doukkala', null, null]],
        'marrakech-agadir' => [['Marrakech', 0, 'Bab Doukkala', 255, 90], ['Agadir', 195, 'Marina d\'Agadir', null, null]],
        'agadir-essaouira-marrakech' => [['Agadir', 0, 'Marina d\'Agadir', 175, 60], ['Essaouira', 105, 'Place Moulay Hassan', 190, 65], ['Marrakech', 230, 'Place du 16 Novembre', null, null]],
        'agadir-marrakech-casa' => [['Agadir', 0, 'Gare routière CTM', 255, 90], ['Marrakech', 200, 'Gare ferroviaire', 240, 85], ['Casablanca', 440, 'Casa Voyageurs', null, null]],
        'marrakech-chichaoua-agadir' => [['Marrakech', 0, 'Bab Doukkala', 80, 30], ['Chichaoua', 70, 'Place du marché', 180, 65], ['Agadir', 205, 'Marina d\'Agadir', null, null]],
        'marrakech-casa-rabat' => [['Marrakech', 0, 'Gare ferroviaire', 240, 85], ['Casablanca', 165, 'Casa Voyageurs', 90, 30], ['Rabat', 240, 'Gare Rabat Ville', null, null]],
        'casa-rabat-tanger' => [['Casablanca', 0, 'Casa Voyageurs', 90, 30], ['Rabat', 75, 'Gare Rabat Ville', 250, 90], ['Tanger', 240, 'Gare Tanger Ville', null, null]],
        'tanger-rabat-casa' => [['Tanger', 0, 'Gare Tanger Ville', 250, 90], ['Rabat', 180, 'Gare Rabat Ville', 90, 30], ['Casablanca', 260, 'Casa Voyageurs', null, null]],
        'casa-rabat-fes' => [['Casablanca', 0, 'Casa Voyageurs', 90, 30], ['Rabat', 75, 'Gare Rabat Agdal', 200, 70], ['Fès', 225, 'Gare ONCF de Fès', null, null]],
        'rabat-casa-eljadida' => [['Rabat', 0, 'Gare Rabat Ville', 90, 30], ['Casablanca', 75, 'Gare routière Ouled Ziane', 100, 35], ['El Jadida', 165, 'Place Mohammed V', null, null]],
        'casa-eljadida' => [['Casablanca', 0, 'Gare routière Ouled Ziane', 100, 35], ['El Jadida', 85, 'Place Mohammed V', null, null]],
        'fes-rabat' => [['Fès', 0, 'Gare ONCF de Fès', 200, 70], ['Rabat', 150, 'Gare Rabat Agdal', null, null]],
        'marrakech-ouarzazate' => [['Marrakech', 0, 'Gare routière de Bab Doukkala', 200, 70], ['Ouarzazate', 240, 'Place Al Mouahidine', null, null]],
        'essaouira-marrakech' => [['Essaouira', 0, 'Bab Marrakech', 190, 65], ['Marrakech', 150, 'Bab Doukkala', null, null]],
        'marrakech-essaouira' => [['Marrakech', 0, 'Gare ferroviaire', 190, 65], ['Essaouira', 150, 'Place Moulay Hassan', null, null]],
        'agadir-tiznit' => [['Agadir', 0, 'Marina d\'Agadir', 90, 35], ['Tiznit', 80, 'Bab Oulad Jarrar', null, null]],
        'agadir-taroudant' => [['Agadir', 0, 'Gare routière, avenue des FAR', 80, 30], ['Taroudant', 70, 'Place Assarag', null, null]],
    ];

    /** New users: [key, first name, last name, member since]. Email is key@rihla.ma, phones follow 06612345xx. */
    private const MORE_USERS = [
        ['zineb', 'Zineb', 'Chraibi', '-11 months'],
        ['anas', 'Anas', 'Benjelloun', '-10 months'],
        ['khadija', 'Khadija', 'Lahlou', '-8 months'],
        ['reda', 'Reda', 'Sebti', '-8 months'],
        ['meryem', 'Meryem', 'Fassi', '-6 months'],
        ['soufiane', 'Soufiane', 'Cherkaoui', '-6 months'],
        ['hajar', 'Hajar', 'Naciri', '-5 months'],
        ['ayoub', 'Ayoub', 'Tahiri', '-4 months'],
        ['sanaa', 'Sanaa', 'Zniber', '-4 months'],
        ['amine', 'Amine', 'Bouazza', '-3 months'],
    ];

    /** New drivers' cars: owner key => [model, color, passenger seats, trunk litres]. */
    private const MORE_VEHICLES = [
        'zineb' => ['Renault Clio', 'blanche', 4, 391],
        'anas' => ['Toyota Corolla', 'grise', 4, 470],
        'khadija' => ['Kia Picanto', 'rouge', 4, 255],
        'reda' => ['Skoda Octavia', 'noire', 4, 600],
        'meryem' => ['Volkswagen Golf', 'bleue', 4, 380],
        'soufiane' => ['Dacia Sandero', 'verte', 4, 320],
    ];

    /**
     * Upcoming published trips, in no particular order: [driver, day, start, route, seats offered, bookings].
     * A day like "+3 hours" leaves relative to now (start null); otherwise the start is a clock time.
     * Booking: [passenger, from stop, to stop, seats, seat codes, bags, (status, hours before departure it was cancelled)].
     * Bags: c = cabin, m = medium, l = large.
     */
    private const UPCOMING = [
        // The soonest departures: one full on every leg, one full on its first leg only.
        ['zineb', '+3 hours', null, 'marrakech-agadir', 3, [
            ['mehdi', 0, 1, 1, ['A1'], 'c'], ['sanaa', 0, 1, 1, ['B1'], 'm'], ['amine', 0, 1, 1, ['B3'], 'c'],
        ]],
        ['anas', '+6 hours', null, 'casa-rabat-tanger', 3, [
            ['hajar', 0, 1, 1, ['A1'], 'm'], ['ayoub', 0, 1, 1, ['B1'], 'c'], ['youssef', 0, 1, 1, ['B3'], 'c'],
        ]],
        ['reda', '+20 hours', null, 'agadir-marrakech', 3, [['lina', 0, 1, 1, ['A1'], 'm']]],
        ['khadija', '+1 day', '07:30', 'fes-rabat', 3, [['youssef', 0, 1, 1, ['A1'], 'c']]],
        ['meryem', '+1 day', '15:00', 'agadir-taroudant', 3, [['omar', 0, 1, 1, ['A1'], 'm']]],
        ['soufiane', '+2 days', '09:00', 'casa-eljadida', 3, [['omar', 0, 1, 1, ['A1'], 'm'], ['rachid', 0, 1, 1, ['B1'], 'c']]],
        ['anas', '+3 days', '14:00', 'tanger-rabat-casa', 3, [['lina', 0, 2, 1, ['A1'], 'm'], ['omar', 1, 2, 1, ['B1'], 'c']]],
        ['zineb', '+3 days', '08:00', 'agadir-marrakech-casa', 3, [['hajar', 0, 2, 1, ['A1'], 'm'], ['ayoub', 1, 2, 1, ['B1'], 'c']]],
        ['reda', '+4 days', '09:00', 'marrakech-casa-rabat', 3, [['mehdi', 0, 1, 1, ['A1'], 'm'], ['amine', 1, 2, 1, ['A1'], 'c']]],
        ['meryem', '+4 days', '16:00', 'agadir-tiznit', 3, [['rachid', 0, 1, 1, ['A1'], 'c']]],
        ['khadija', '+5 days', '08:00', 'marrakech-chichaoua-agadir', 3, [['lina', 0, 1, 1, ['A1'], 'c'], ['hajar', 1, 2, 1, ['B1'], 'm']]],
        ['nadia', '+5 days', '10:00', 'marrakech-essaouira', 3, [['imane', 0, 1, 1, ['A1'], 'm'], ['salma', 0, 1, 1, ['B1'], 'm', BookingStatus::CancelledByPassenger, 48]]],
        ['hamid', '+6 days', '07:00', 'agadir-marrakech', 6, [['salma', 0, 1, 2, ['A1', 'B1'], 'mm']]],
        ['imane', '+6 days', '17:00', 'agadir-taroudant', 3, []],
        ['karim', '+7 days', '08:00', 'agadir-essaouira-marrakech', 3, [['youssef', 0, 1, 1, ['A1'], 'm'], ['sanaa', 1, 2, 1, ['A1'], 'c']]],
        ['soufiane', '+8 days', '08:30', 'rabat-casa-eljadida', 3, [['lina', 0, 2, 1, ['A1'], 'm']]],
        ['anas', '+9 days', '07:00', 'casa-rabat-fes', 3, [['mehdi', 0, 1, 1, ['A1'], 'm'], ['rachid', 1, 2, 1, ['B1'], 'c']]],
        ['zineb', '+10 days', '14:30', 'marrakech-agadir', 3, []],
        ['hamid', '+11 days', '06:30', 'marrakech-ouarzazate', 6, [['omar', 0, 1, 2, ['A1', 'B1'], 'lm']]],
        ['reda', '+12 days', '09:00', 'casa-rabat-tanger', 3, [['ayoub', 0, 2, 1, ['A1'], 'm']]],
        ['khadija', '+13 days', '07:30', 'fes-rabat', 3, []],
        ['meryem', '+15 days', '10:00', 'agadir-marrakech-casa', 3, [['amine', 0, 2, 1, ['A1'], 'm']]],
        ['karim', '+16 days', '07:30', 'agadir-marrakech', 3, [['salma', 0, 1, 1, ['A1'], 'm']]],
        ['nadia', '+18 days', '09:00', 'essaouira-marrakech', 3, []],
        ['imane', '+19 days', '17:00', 'agadir-tiznit', 3, [['sanaa', 0, 1, 1, ['A1'], 'c']]],
        ['soufiane', '+20 days', '08:00', 'casa-eljadida', 3, []],
        ['anas', '+21 days', '08:00', 'tanger-rabat-casa', 3, [['youssef', 0, 2, 1, ['A1'], 'm']]],
    ];

    /**
     * Completed trips of the last months: [driver, day, start, route, seats offered, bookings].
     * Booking: [passenger, from, to, seats, seat codes, bags, rating given to the driver, rating given to the passenger],
     * a rating being null when nobody wrote a review; or [..., status, hours before departure] for a cancelled one.
     */
    private const COMPLETED = [
        // Karim, over four months of earnings.
        ['karim', '-22 days', '08:30', 'agadir-essaouira-marrakech', 3, [['hajar', 0, 2, 1, ['A1'], 'm', 5, 5], ['omar', 0, 1, 1, ['B1'], 'c', 4, null], ['lina', 1, 2, 1, ['B1'], 'c', 5, null]]],
        ['karim', '-40 days', '09:00', 'agadir-marrakech', 3, [['youssef', 0, 1, 1, ['A1'], 'l', 5, 5], ['sanaa', 0, 1, 1, ['B1'], 'm', 5, null], ['mehdi', 0, 1, 1, ['B3'], 'c', 4, 4]]],
        ['karim', '-55 days', '14:00', 'marrakech-agadir', 3, [['salma', 0, 1, 1, ['A1'], 'm', 5, 5], ['amine', 0, 1, 2, ['B1', 'B3'], 'mm', null, null]]],
        ['karim', '-70 days', '07:00', 'agadir-marrakech-casa', 3, [['rachid', 0, 2, 1, ['A1'], 'm', 5, 5], ['nadia', 1, 2, 1, ['B1'], 'c', 5, null], ['ayoub', 0, 1, 1, ['B3'], 'l', 3, 5]]],
        ['karim', '-100 days', '08:00', 'agadir-essaouira-marrakech', 3, [['zineb', 0, 2, 1, ['A1'], 'm', 5, 5], ['hajar', 0, 1, 1, ['B1'], 'c', 4, null]]],
        // Nadia, Essaouira ↔ Marrakech.
        ['nadia', '-3 days', '08:00', 'essaouira-marrakech', 3, [['salma', 0, 1, 1, ['A1'], 'm', 5, 5], ['reda', 0, 1, 1, ['B1'], 'c', 4, null]]],
        ['nadia', '-9 days', '07:30', 'marrakech-essaouira', 3, [['imane', 0, 1, 1, ['A1'], 'm', 5, 5]]],
        ['nadia', '-33 days', '10:00', 'essaouira-marrakech', 3, [['lina', 0, 1, 1, ['A1'], 'c', 5, 4], ['hajar', 0, 1, 1, ['B1'], 'm', 5, null]]],
        ['nadia', '-58 days', '09:00', 'marrakech-essaouira', 3, [['youssef', 0, 1, 1, ['A1'], 'l', 4, 5]]],
        // Hamid and his Lodgy, the family car.
        ['hamid', '-5 days', '06:30', 'marrakech-ouarzazate', 6, [['omar', 0, 1, 2, ['A1', 'B1'], 'lm', 5, 5], ['sanaa', 0, 1, 1, ['B3'], 'm', 5, null], ['amine', 0, 1, 1, ['B2'], 'c', BookingStatus::CancelledByPassenger, 6]]],
        ['hamid', '-19 days', '07:00', 'agadir-marrakech', 6, [['mehdi', 0, 1, 3, ['A1', 'B1', 'B3'], 'lmm', 5, null], ['zineb', 0, 1, 1, ['B2'], 'm', 5, 5], ['ayoub', 0, 1, 1, ['C1'], 'c', BookingStatus::CancelledByPassenger, 30]]],
        ['hamid', '-47 days', '06:00', 'marrakech-ouarzazate', 6, [['anas', 0, 1, 1, ['A1'], 'm', 5, 5], ['hajar', 0, 1, 2, ['B1', 'B3'], 'mm', 4, null]]],
        ['hamid', '-63 days', '08:00', 'agadir-marrakech', 6, [['salma', 0, 1, 1, ['A1'], 'c', 5, 5], ['rachid', 0, 1, 1, ['B1'], 'l', 5, null]]],
        // Imane, around Agadir.
        ['imane', '-2 days', '17:00', 'agadir-tiznit', 3, [['lina', 0, 1, 1, ['A1'], 'm', 5, 5], ['ayoub', 0, 1, 1, ['B1'], 'c', null, null]]],
        ['imane', '-14 days', '12:00', 'agadir-taroudant', 3, [['youssef', 0, 1, 1, ['A1'], 'm', 4, 5]]],
        ['imane', '-27 days', '17:30', 'agadir-tiznit', 3, [['nadia', 0, 1, 1, ['A1'], 'c', 5, null], ['sanaa', 0, 1, 1, ['B1'], 'm', 5, 5]]],
        ['imane', '-52 days', '08:30', 'agadir-taroudant', 3, [['meryem', 0, 1, 1, ['A1'], 'm', 5, 5]]],
        // The newcomers, on every corridor.
        ['zineb', '-4 days', '14:00', 'casa-eljadida', 3, [['rachid', 0, 1, 1, ['A1'], 'm', 5, 5], ['amine', 0, 1, 1, ['B1'], 'c', 4, null]]],
        ['zineb', '-17 days', '09:30', 'casa-rabat-tanger', 3, [['mehdi', 0, 2, 1, ['A1'], 'm', 5, 5], ['hajar', 0, 1, 1, ['B1'], 'c', 5, null], ['ayoub', 1, 2, 1, ['B1'], 'm', 4, null]]],
        ['anas', '-7 days', '08:00', 'casa-rabat-tanger', 3, [['salma', 0, 1, 1, ['A1'], 'm', 5, 5], ['youssef', 1, 2, 1, ['A1'], 'l', 4, 4]]],
        ['anas', '-30 days', '15:00', 'tanger-rabat-casa', 3, [['omar', 0, 2, 1, ['A1'], 'm', 4, 5], ['lina', 1, 2, 1, ['B1'], 'c', 5, null]]],
        ['anas', '-44 days', '09:00', 'casa-rabat-fes', 3, [['rachid', 0, 1, 1, ['A1'], 'm', 5, null], ['sanaa', 1, 2, 1, ['A1'], 'm', 5, 5]]],
        ['khadija', '-12 days', '10:00', 'fes-rabat', 3, [['meryem', 0, 1, 1, ['A1'], 'm', 5, 5], ['omar', 0, 1, 1, ['B1'], 'c', 4, null]]],
        ['khadija', '-36 days', '13:00', 'marrakech-chichaoua-agadir', 3, [['imane', 0, 2, 1, ['A1'], 'm', 5, 5], ['hajar', 0, 1, 1, ['B1'], 'c', 5, null]]],
        ['khadija', '-61 days', '09:00', 'fes-rabat', 3, [['youssef', 0, 1, 1, ['A1'], 'c', 3, 5]]],
        ['reda', '-6 days', '07:00', 'marrakech-casa-rabat', 3, [['sanaa', 0, 1, 1, ['A1'], 'm', 5, 5], ['amine', 1, 2, 1, ['A1'], 'c', 5, null]]],
        ['reda', '-21 days', '15:00', 'casa-rabat-tanger', 3, [['mehdi', 0, 2, 1, ['A1'], 'l', 4, 4], ['nadia', 0, 1, 1, ['B1'], 'c', 5, null]]],
        ['reda', '-50 days', '08:00', 'rabat-casa-eljadida', 3, [['lina', 0, 1, 1, ['A1'], 'c', 3, null], ['hajar', 1, 2, 1, ['A1'], 'm', 5, 5]]],
        ['meryem', '-8 days', '11:00', 'agadir-marrakech', 3, [['anas', 0, 1, 1, ['A1'], 'm', 5, 5], ['amine', 0, 1, 1, ['B1'], 'c', 5, null]]],
        ['meryem', '-26 days', '07:30', 'marrakech-agadir', 3, [['karim', 0, 1, 1, ['A1'], 'm', 5, 5]]],
        ['meryem', '-56 days', '16:00', 'agadir-tiznit', 3, [['ayoub', 0, 1, 1, ['A1'], 'm', 4, null], ['salma', 0, 1, 1, ['B1'], 'c', 5, null]]],
        ['soufiane', '-11 days', '09:00', 'casa-eljadida', 3, [['youssef', 0, 1, 1, ['A1'], 'm', 4, 5], ['hajar', 0, 1, 1, ['B1'], 'c', 5, null]]],
        ['soufiane', '-38 days', '14:00', 'rabat-casa-eljadida', 3, [['mehdi', 0, 2, 1, ['A1'], 'm', 5, 5], ['lina', 1, 2, 1, ['B1'], 'c', null, null]]],
        ['soufiane', '-64 days', '08:00', 'casa-rabat-fes', 3, [['omar', 0, 2, 1, ['A1'], 'm', 5, null], ['ayoub', 0, 1, 1, ['B1'], 'c', 5, 5]]],
    ];

    /** Trips their driver cancelled: every booking is cancelled by the driver and refunded in full. */
    private const CANCELLED = [
        ['reda', '-20 days', '08:00', 'casa-rabat-tanger', 3, [['lina', 0, 1, 1, ['A1'], 'm'], ['amine', 0, 2, 1, ['B1'], 'c']]],
        ['meryem', '-30 days', '07:00', 'agadir-marrakech', 3, [['ayoub', 0, 1, 1, ['A1'], 'm'], ['sanaa', 0, 1, 1, ['B1'], 'c']]],
        ['khadija', '-3 days', '09:00', 'fes-rabat', 3, [['omar', 0, 1, 1, ['A1'], 'c']]],
    ];

    /**
     * Review sentences, an opening and an ending combined (the pool sizes are coprime, so no two reviews of a kind
     * are alike): "p" = passenger about the driver, "d" = driver about the passenger, then the rating.
     */
    private const REVIEW_OPENINGS = [
        'p5' => ['Trajet très agréable,', 'Ponctualité parfaite,', 'Voiture propre et confortable,', 'Conduite souple et prudente,', 'Super expérience,', 'Départ à l\'heure pile,', 'Covoiturage sans souci,', 'Rien à redire,', 'Je recommande sans hésiter,', 'Voyage tranquille,', 'Accueil chaleureux au point de rendez-vous,'],
        'p4' => ['Bon trajet dans l\'ensemble,', 'Voiture confortable,', 'Conduite correcte,', 'Covoiturage agréable,', 'Trajet sans problème,', 'Bonne ambiance,', 'Rendez-vous bien organisé,'],
        'd5' => ['Ponctualité exemplaire,', 'Très agréable en voyage,', 'Aucun problème,', 'Bagages bien préparés,', 'Respectueux de la voiture,', 'Communication claire avant le départ,', 'Rendez-vous respecté à la minute près,', 'Super compagnie pour la route,', 'Échange sympa pendant tout le trajet,'],
    ];

    private const REVIEW_ENDINGS = [
        'p5' => ['merci pour le voyage !', 'on a bien discuté en route.', 'la musique était parfaite.', 'je referai le trajet avec plaisir.', 'bonne communication avant le départ.', 'le coffre a bien accueilli mes valises.', 'arrivée même plus tôt que prévu.', 'pause thé à mi-chemin, très bien.'],
        'p4' => ['juste un petit retard au départ.', 'un peu de musique en moins et ce serait parfait.', 'la clim aurait pu être plus fraîche.', 'une pause un peu longue mais rien de grave.', 'je reprendrai volontiers.'],
        'd5' => ['à recommander.', 'merci et bonne continuation !', 'bienvenue à bord quand vous voulez.', 'voyage très convivial.', 'rien à signaler, parfait.', 'bon voyage pour la suite !', 'c\'est avec plaisir que je reprendrai à bord.'],
    ];

    /** Reviews that stand alone (a lukewarm rating, or too specific to combine). */
    private const REVIEW_SENTENCES = [
        'p3' => ['Départ avec 25 minutes de retard et peu de nouvelles, dommage.', 'Trajet correct mais la voiture n\'était pas très propre.', 'Conduite un peu rapide à mon goût, mais arrivée à l\'heure.'],
        'd4' => ['Un peu en retard au point de rendez-vous, mais prévenu à l\'avance.', 'Sympa, bagages un peu volumineux pour le coffre.', 'Bon passager, a juste oublié de confirmer l\'heure.', 'Voyage agréable, quelques minutes d\'attente au départ.', 'Correct et poli, trajet sans histoire.', 'Un peu bavard mais très aimable.'],
    ];

    /** @var array<string, City> */
    private array $cities = [];

    /** @var array<string, User> by key (first name, lowercase) */
    private array $users = [];

    /** @var array<string, Vehicle> by owner key */
    private array $vehicles = [];

    /** @var \ArrayObject<string, \App\Model\RouteLeg> legs already asked to OSRM during this load */
    private \ArrayObject $legCache;

    /** Set once OSRM fails: the rest of the load goes without road data instead of waiting for it again. */
    private bool $osrmDown = false;

    /** @var array<string, int> reviews written so far per kind, to pick the next sentence */
    private array $reviewCount = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly RouteEnricher $routeEnricher,
        private readonly TripChangeService $tripChanges,
        private readonly DriverNotifier $driverNotices,
    )
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->legCache = new \ArrayObject();
        $this->osrmDown = false;
        $this->reviewCount = [];

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

        $this->users = [
            'karim' => $karim, 'salma' => $salma, 'youssef' => $youssef, 'mehdi' => $mehdi, 'nadia' => $nadia, 'hamid' => $hamid,
            'imane' => $imane, 'lina' => $lina, 'omar' => $omar, 'rachid' => $rachid,
        ];
        $this->vehicles = ['karim' => $logan, 'nadia' => $clio, 'hamid' => $lodgy, 'imane' => $i20];
        $index = 11;
        foreach (self::MORE_USERS as [$key, $first, $last, $since]) {
            $this->users[$key] = $this->user($manager, $key . '@rihla.ma', $first, $last, sprintf('06612345%02d', $index++), [], $since);
        }
        foreach (self::MORE_VEHICLES as $key => [$model, $color, $seats, $trunk]) {
            $this->vehicles[$key] = $this->vehicle($manager, $this->users[$key], $model, $color, $seats, $trunk);
        }

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
        $karimReview = $this->review($manager, $b2, $salma, $karim, 5, 'Ponctuel, prudent, et il connaît toutes les bonnes pauses.');

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
        $this->book($manager, $cancelled, $salma, 0, 1, 1, BookingStatus::CancelledByDriver, ['A1']);

        // Upcoming: trips from a few hours away to three weeks out, some full or partly full.
        $upcoming = [];
        foreach (self::UPCOMING as $i => $row) {
            $upcoming[$i] = $this->scheduled($manager, $row, TripStatus::Published);
        }
        // Past and completed: reviews in both directions.
        foreach (self::COMPLETED as $row) {
            $this->scheduled($manager, $row, TripStatus::Completed);
        }
        // Cancelled by their driver.
        foreach (self::CANCELLED as $row) {
            $this->scheduled($manager, $row, TripStatus::Cancelled);
        }

        $manager->flush();

        // ---- Changes after booking, and notices ----------------------------------------------------
        // The services need persisted trips, so this comes after the flush. They use the real clock here and send
        // their e-mails (to Mailpit in Docker); a failing mailer is caught by UserNotifier and never breaks the load.

        // Hamid's trip in six days (UPCOMING[12]) is pushed back 45 minutes: Salma, confirmed on it since before the
        // delay (tripDelayAtBooking = 0), sees « Horaire modifié par le conducteur » and may cancel free of charge.
        $this->tripChanges->delay($upcoming[12], 45);

        // Anas' Tanger → Rabat → Casablanca (UPCOMING[6]) loses its stop Rabat: Omar, who boarded there, is cancelled
        // by the driver and refunded; Lina rides through and the trip stays published.
        $this->tripChanges->removeStop($upcoming[6], $upcoming[6]->getStops()->get(1));

        // Driver notices for Karim, written like the app does (same texts) but without e-mails. Unread and recent...
        $flagshipBookings = $flagship->getBookings();
        $this->notice($manager, $karim, Notification::KIND_NEW_BOOKING, $this->driverNotices->newBookingText($flagshipBookings[3]), '-1 day', $flagshipBookings[3]);
        $this->notice($manager, $karim, Notification::KIND_NEW_BOOKING, $this->driverNotices->newBookingText($flagshipBookings[4]), '-3 hours', $flagshipBookings[4]);
        $this->notice($manager, $karim, Notification::KIND_REVIEW, $this->driverNotices->reviewText($karimReview), '-2 days');
        // ... and older ones he has already read, so the page shows both states.
        $this->notice($manager, $karim, Notification::KIND_NEW_BOOKING, $this->driverNotices->newBookingText($b1), '-12 days', $b1, true);
        $this->notice($manager, $karim, Notification::KIND_REVIEW, $this->driverNotices->reviewText($karimReview), '-9 days', null, true);

        $manager->flush();
    }

    /**
     * An in-app driver notice with texts from DriverNotifier, no e-mail. Never dated in the future.
     *
     * @param array{string, string} $text [subject, body]
     */
    private function notice(ObjectManager $manager, User $user, string $kind, array $text, string $ago, ?Booking $booking = null, bool $read = false): void
    {
        $at = new \DateTimeImmutable($ago);
        $notice = new Notification($user, $booking, $kind, $text[0], $text[1], $at, $booking?->getTrip());
        if ($read) {
            $notice->markRead($at->modify('+2 hours'));
        }
        $manager->persist($notice);
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
        $date = str_contains($day, 'hours') ? $this->soon($day) : new \DateTimeImmutable($day);
        // Published a few days ahead, but never in the future (a trip leaving in three weeks was published last week).
        $trip->setCreatedAt(min($date->modify('-5 days'), new \DateTimeImmutable('-1 day')));

        foreach ($stops as [$city, $time, $meetingPoint, $km, $price]) {
            // "+H:MM" is an offset from the start itself (for a trip leaving "+3 hours" from now), "HH:MM" a clock time on $day.
            $departure = str_starts_with($time, '+') ? $this->offsetFrom($date, $time) : $this->clockTime($date, $time);
            $exact = self::MEETING_POINTS[$city . '|' . $meetingPoint] ?? [null, null];
            $trip->addStop((new TripStop())
                ->setCity($this->cities[$city])
                ->setMeetingLatitude($exact[0])
                ->setMeetingLongitude($exact[1])
                ->setDepartureAt($departure)
                ->setMeetingPoint($meetingPoint)
                ->setLegDistanceKmToNext($km)
                ->setLegPriceToNext($price));
        }
        // Real road distance, time and line when the network is up; degrades to the km given above offline.
        // Asked once per pair of points, and not at all once OSRM has failed.
        if (!$this->osrmDown && !$this->routeEnricher->enrich($trip, $this->legCache)) {
            $this->osrmDown = true;
        }
        $manager->persist($trip);

        return $trip;
    }

    /**
     * The start of a trip leaving "+N hours" from now, at a believable time: rounded up to the quarter hour, and moved
     * to the next morning (from 06:30, in the same order) when it would leave between 22:00 and 06:00. Always within
     * 24 hours of loading, so the demo has something leaving soon whatever time it is installed.
     */
    private function soon(string $day): \DateTimeImmutable
    {
        $hours = (int) filter_var($day, FILTER_SANITIZE_NUMBER_INT);
        $start = new \DateTimeImmutable($day);
        $start = $start->setTime((int) $start->format('G'), (int) (ceil((int) $start->format('i') / 15) * 15) % 60)
            ->modify((int) $start->format('i') > 45 ? '+1 hour' : '+0 hour');
        $hour = (int) $start->format('G');
        if ($hour >= 22 || $hour < 6) {
            $morning = $hour >= 22 ? $start->modify('+1 day') : $start;
            $start = $morning->setTime(6, 30)->modify(sprintf('+%d minutes', max(0, $hours - 3) * 15));
        }

        return $start;
    }

    private function clockTime(\DateTimeImmutable $day, string $time): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $day->setTime($h, $m);
    }

    /** The start of a trip leaving "+3 hours" from now, to the minute, plus a "+H:MM" offset. */
    private function offsetFrom(\DateTimeImmutable $start, string $offset): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', ltrim($offset, '+')));

        return $start->setTime((int) $start->format('G'), (int) $start->format('i'))->modify(sprintf('+%d minutes', $h * 60 + $m));
    }

    /**
     * A trip along one of the ROUTES, with its bookings and reviews, from a UPCOMING / COMPLETED / CANCELLED row.
     *
     * @param array{0: string, 1: string, 2: ?string, 3: string, 4: int, 5: list<array<int, mixed>>} $row
     */
    private function scheduled(ObjectManager $manager, array $row, TripStatus $status): Trip
    {
        [$driverKey, $day, $start, $route, $seats, $bookings] = $row;
        $stops = [];
        foreach (self::ROUTES[$route] as [$city, $minutes, $meetingPoint, $km, $price]) {
            $time = null === $start
                ? sprintf('+%d:%02d', intdiv($minutes, 60), $minutes % 60)
                : (new \DateTimeImmutable($start))->modify(sprintf('+%d minutes', $minutes))->format('H:i');
            $stops[] = [$city, $time, $meetingPoint, $km, $price];
        }
        $trip = $this->trip($manager, $this->users[$driverKey], $this->vehicles[$driverKey], $seats, $day, $stops, $status);

        foreach ($bookings as $spec) {
            [$passengerKey, $from, $to, $count, $codes, $bags] = $spec;
            $passenger = $this->users[$passengerKey];
            $override = $spec[6] ?? null;
            if ($override instanceof BookingStatus) {
                $this->book($manager, $trip, $passenger, $from, $to, $count, $override, $codes, $this->bags($bags), $spec[7] ?? 24);
                continue;
            }
            $booking = $this->book($manager, $trip, $passenger, $from, $to, $count, match ($status) {
                TripStatus::Completed => BookingStatus::Completed,
                TripStatus::Cancelled => BookingStatus::CancelledByDriver,
                default => BookingStatus::Confirmed,
            }, $codes, $this->bags($bags));
            if (null !== ($spec[6] ?? null)) {
                $this->review($manager, $booking, $passenger, $trip->getDriver(), $spec[6], $this->comment(5 === $spec[6] ? 'p5' : (4 === $spec[6] ? 'p4' : 'p3')));
            }
            if (null !== ($spec[7] ?? null)) {
                $this->review($manager, $booking, $trip->getDriver(), $passenger, $spec[7], $this->comment(5 === $spec[7] ? 'd5' : 'd4'));
            }
        }

        return $trip;
    }

    /** "mm" => two medium bags; c = cabin, m = medium, l = large. */
    private function bags(string $letters): array
    {
        $names = ['c' => 'cabin', 'm' => 'medium', 'l' => 'large'];
        $bags = [];
        foreach (str_split($letters) as $letter) {
            $bags[$names[$letter]] = ($bags[$names[$letter]] ?? 0) + 1;
        }

        return $bags;
    }

    /** The next sentence of a kind ("p5", "d4"...): the openings and endings are combined, never repeated. */
    private function comment(string $kind): string
    {
        $i = $this->reviewCount[$kind] ?? 0;
        $this->reviewCount[$kind] = $i + 1;
        if (isset(self::REVIEW_SENTENCES[$kind])) {
            return self::REVIEW_SENTENCES[$kind][$i % \count(self::REVIEW_SENTENCES[$kind])];
        }

        return self::REVIEW_OPENINGS[$kind][$i % \count(self::REVIEW_OPENINGS[$kind])] . ' ' . self::REVIEW_ENDINGS[$kind][$i % \count(self::REVIEW_ENDINGS[$kind])];
    }

    private function book(ObjectManager $manager, Trip $trip, User $passenger, int $from, int $to, int $seats = 1, BookingStatus $status = BookingStatus::Confirmed, array $seatCodes = [], array $bags = [], int $cancelledHoursBefore = 24): Booking
    {
        $stops = $trip->getStops()->getValues();
        if ($status->holdsSeats()) {
            $this->assertFits($trip, $from, $to, $seats, $seatCodes, $bags);
        }
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
            ->setCreatedAt(min($trip->getCreatedAt()->modify('+1 day'), new \DateTimeImmutable()));
        if ($status->isCancelled()) {
            // Cancelled so many hours before departure, but never in the future.
            $booking->setCancelledAt(min($stops[$from]->getDepartureAt()->modify(sprintf('-%d hours', $cancelledHoursBefore)), new \DateTimeImmutable('-1 hour')));
        }
        $trip->getBookings()->add($booking);
        $manager->persist($booking);

        $payment = (new Payment())->setAmount($booking->getTotalPrice())->setPaidAt($booking->getCreatedAt());
        $booking->setPayment($payment);
        $manager->persist($payment);

        // Refund: all of it when the driver cancels, or the passenger more than 24 hours ahead; half of it later.
        if (BookingStatus::CancelledByDriver === $status) {
            $payment->refund($booking->getTotalPrice());
        } elseif (BookingStatus::CancelledByPassenger === $status) {
            $early = $stops[$from]->getDepartureAt() > $booking->getCancelledAt()->modify('+24 hours');
            $payment->refund($early ? $booking->getTotalPrice() : intdiv($booking->getTotalPrice(), 2));
        }

        return $booking;
    }

    /** Guards the demo data against itself: a seat, a place or some trunk volume sold twice would show up on the pages. */
    private function assertFits(Trip $trip, int $from, int $to, int $seats, array $seatCodes, array $bags): void
    {
        $offered = (new SeatLayout())->offeredCodes($trip);
        if ($seats !== \count($seatCodes) || array_diff($seatCodes, $offered)) {
            throw new \LogicException(sprintf('Fixture: seats %s are not among the %d offered (%s).', implode(',', $seatCodes), $trip->getSeatsOffered(), implode(',', $offered)));
        }
        foreach ($trip->getBookings() as $other) {
            $overlap = $other->getStatus()->holdsSeats() && $other->getFromStop()->getPosition() < $to && $other->getToStop()->getPosition() > $from;
            if ($overlap && array_intersect($seatCodes, $other->getSeatCodes())) {
                throw new \LogicException('Fixture: a seat is booked twice on the same leg.');
            }
        }
        $litres = LuggageSize::litresOf($bags);
        if (array_sum($bags) > TrunkSpace::MAX_BAGS_PER_PLACE * $seats || $litres > (new TrunkSpace())->freeLitres($trip, $from, $to)) {
            throw new \LogicException('Fixture: the bags do not fit in the trunk.');
        }
    }

    private function review(ObjectManager $manager, Booking $booking, User $author, User $target, int $rating, string $comment): Review
    {
        $review = (new Review())
            ->setBooking($booking)
            ->setAuthor($author)
            ->setTarget($target)
            ->setRating($rating)
            ->setComment($comment)
            ->setCreatedAt($booking->getToStop()->getDepartureAt()->modify('+1 day'));
        $manager->persist($review);

        return $review;
    }
}
