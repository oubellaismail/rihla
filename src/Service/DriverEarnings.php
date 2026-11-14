<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Trip;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Enum\TripStatus;
use App\Model\EarningsReport;
use App\Repository\TripRepository;
use Symfony\Component\Clock\ClockInterface;

/** Builds the « Mes gains » figures of a driver from his trips, bookings and payments. Read only. */
final class DriverEarnings
{
    private const MONTHS = 6;

    public function __construct(
        private readonly TripRepository $trips,
        private readonly ClockInterface $clock,
    ) {
    }

    public function forDriver(User $driver): EarningsReport
    {
        $now = $this->clock->now();
        $firstMonth = $now->modify('first day of this month')->setTime(0, 0)->modify(sprintf('-%d months', self::MONTHS - 1));

        $months = [];
        for ($i = 0; $i < self::MONTHS; ++$i) {
            $month = $firstMonth->modify(sprintf('+%d months', $i));
            $months[$month->format('Y-m')] = ['month' => $month, 'earned' => 0, 'upcoming' => 0];
        }

        $earned = $upcoming = $upcomingBookings = $seatsSold = $tripsWithSales = $resold = 0;
        $seatLegsTaken = $seatLegsOffered = 0;
        $rows = [];

        foreach ($this->trips->findForDriverWithBookings($driver) as $trip) {
            $departure = $trip->getFirstStop()->getDepartureAt();
            $left = $departure <= $now;
            $legCount = max(0, $trip->getStops()->count() - 1);
            $legs = array_fill(0, $legCount, 0);
            $tripSeats = $tripEarned = $tripUpcoming = $tripUpcomingBookings = 0;

            foreach ($trip->getBookings() as $booking) {
                $status = $booking->getStatus();
                if ($status->occupiedSeat()) {
                    $tripSeats += $booking->getSeats();
                    for ($leg = $booking->getFromStop()->getPosition(); $leg < $booking->getToStop()->getPosition(); ++$leg) {
                        if (isset($legs[$leg])) {
                            $legs[$leg] += $booking->getSeats();
                        }
                    }
                }
                if (BookingStatus::Completed === $status || $status->isCancelled()) {
                    $tripEarned += $this->kept($booking);
                } elseif (BookingStatus::Confirmed === $status && TripStatus::Published === $trip->getStatus() && !$left) {
                    $tripUpcoming += $booking->getTotalPrice();
                    ++$tripUpcomingBookings;
                }
            }

            $earned += $tripEarned;
            $upcoming += $tripUpcoming;
            $upcomingBookings += $tripUpcomingBookings;
            $seatsSold += $tripSeats;
            if ($tripSeats > 0) {
                ++$tripsWithSales;
            }
            $resold += max(0, $tripSeats - ($legs ? max($legs) : 0));
            if (TripStatus::Cancelled !== $trip->getStatus()) {
                $seatLegsTaken += array_sum($legs);
                $seatLegsOffered += $trip->getSeatsOffered() * $legCount;
            }

            $key = $departure->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['earned'] += $tripEarned;
                $months[$key]['upcoming'] += $tripUpcoming;
            }

            $state = TripStatus::Cancelled === $trip->getStatus() ? 'cancelled' : (TripStatus::Published === $trip->getStatus() && !$left ? 'upcoming' : 'completed');
            $rows[] = [
                'trip' => $trip,
                'state' => $state,
                'amount' => 'upcoming' === $state ? $tripUpcoming : $tripEarned,
                'seats' => $tripSeats,
                'legs' => $legs,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['trip']->getFirstStop()->getDepartureAt() <=> $a['trip']->getFirstStop()->getDepartureAt());

        return new EarningsReport(
            $earned,
            $upcoming,
            $seatsSold,
            $tripsWithSales,
            $resold,
            $seatLegsOffered > 0 ? (int) round(100 * $seatLegsTaken / $seatLegsOffered) : null,
            array_values($months),
            $rows,
            $upcomingBookings,
        );
    }

    /** Money the driver keeps from a booking: paid minus refunded (the frozen price when no payment is recorded and the trip was driven). */
    private function kept(Booking $booking): int
    {
        $payment = $booking->getPayment();
        if (null !== $payment) {
            return max(0, $payment->getAmount() - $payment->getRefundedAmount());
        }

        return BookingStatus::Completed === $booking->getStatus() ? $booking->getTotalPrice() : 0;
    }
}
