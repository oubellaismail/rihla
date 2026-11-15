<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An in-app notice for a passenger or a driver (the bell): created with the e-mail of the same event and saying the same thing.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    public const KIND_DELAY = 'delay';
    public const KIND_STOP_REMOVED = 'stop_removed';
    public const KIND_TRIP_CANCELLED = 'trip_cancelled';
    public const KIND_NEW_BOOKING = 'new_booking';
    public const KIND_PASSENGER_CANCELLED = 'passenger_cancelled';
    public const KIND_TRIP_CANCELLED_BY_ADMIN = 'trip_cancelled_by_admin';
    public const KIND_REVIEW = 'review';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Booking $booking;

    /** The trip a driver notice is about (a booking is not always involved). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Trip $trip;

    /** One of the KIND_* constants. */
    #[ORM\Column(length: 30)]
    private string $kind;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 500)]
    private string $body;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(User $user, ?Booking $booking, string $kind, string $title, string $body, \DateTimeImmutable $createdAt, ?Trip $trip = null)
    {
        $this->trip = $trip;
        $this->user = $user;
        $this->booking = $booking;
        $this->kind = $kind;
        $this->title = mb_substr($title, 0, 255);
        $this->body = mb_substr($body, 0, 500);
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
