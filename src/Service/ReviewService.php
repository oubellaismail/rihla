<?php

namespace App\Service;

use App\Entity\Booking;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Exception\ReviewNotAllowedException;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Saves the reviews that ReviewPolicy allows, one per author and booking.
 */
final class ReviewService
{
    private const MIN_RATING = 1;
    private const MAX_RATING = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReviewPolicy $policy,
        private readonly ReviewRepository $reviews,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Records the review $author leaves for the other party of $booking.
     *
     * @throws ReviewNotAllowedException when the review is not allowed (the message is meant for the user)
     */
    public function review(Booking $booking, User $author, int $rating, ?string $comment): Review
    {
        if ($rating < self::MIN_RATING || $rating > self::MAX_RATING) {
            throw new ReviewNotAllowedException('La note doit être comprise entre 1 et 5.');
        }

        $now = $this->clock->now();
        $alreadyReviewed = null !== $this->reviews->findOneBy(['booking' => $booking, 'author' => $author]);

        if (!$this->policy->canReview($booking, $author, $now, $alreadyReviewed)) {
            throw new ReviewNotAllowedException($this->refusalMessage($booking, $author, $alreadyReviewed));
        }

        $comment = null === $comment ? '' : trim($comment);
        $review = (new Review())
            ->setBooking($booking)
            ->setAuthor($author)
            ->setTarget($this->policy->targetFor($booking, $author))
            ->setRating($rating)
            ->setComment('' === $comment ? null : $comment)
            ->setCreatedAt($now);

        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    /** The most specific reason (in French) why the policy refused the review. */
    private function refusalMessage(Booking $booking, User $author, bool $alreadyReviewed): string
    {
        return match (true) {
            $alreadyReviewed => 'Vous avez déjà laissé un avis pour ce trajet.',
            BookingStatus::Completed !== $booking->getStatus() => 'Vous pourrez laisser un avis une fois le trajet terminé.',
            $author !== $booking->getPassenger() && $author !== $booking->getTrip()->getDriver() => 'Seuls le conducteur et le passager de cette réservation peuvent laisser un avis.',
            default => 'Le délai de 14 jours pour laisser un avis est dépassé.',
        };
    }
}
