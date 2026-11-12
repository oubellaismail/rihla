<?php

namespace App\Entity;

use App\Enum\BookingStatus;
use App\Enum\LuggageSize;
use App\Repository\BookingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A passenger's seats on part of a trip: from one stop to a later stop,
 * so it occupies every leg in between. The price is frozen at booking time.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Index(name: 'IDX_BOOKING_TRIP_STATUS', columns: ['trip_id', 'status'])]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Trip $trip = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $passenger = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?TripStop $fromStop = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?TripStop $toStop = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 4)]
    private int $seats = 1;

    /** Total in MAD for all seats, frozen when the booking is made. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $totalPrice = 0;

    #[ORM\Column(length: 30, enumType: BookingStatus::class)]
    private BookingStatus $status = BookingStatus::Confirmed;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    /** @var list<string>|null seats held, e.g. ["A1", "B3"]; null for bookings made before seat choice existed */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $seatCodes = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $cabinBags = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $mediumBags = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $largeBags = 0;

    #[ORM\OneToOne(mappedBy: 'booking')]
    private ?Payment $payment = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s : %s → %s', $this->passenger?->getPublicName(), $this->fromStop?->getCity(), $this->toStop?->getCity());
    }

    #[Assert\Callback]
    public function validatePortion(ExecutionContextInterface $context): void
    {
        if (!$this->trip || !$this->fromStop || !$this->toStop) {
            return;
        }
        if ($this->fromStop->getTrip() !== $this->trip || $this->toStop->getTrip() !== $this->trip) {
            $context->buildViolation('Les arrêts doivent appartenir à ce trajet.')->atPath('fromStop')->addViolation();
        }
        if ($this->fromStop->getPosition() >= $this->toStop->getPosition()) {
            $context->buildViolation('L\'arrêt de descente doit venir après l\'arrêt de montée.')->atPath('toStop')->addViolation();
        }
        if ($this->passenger && $this->passenger === $this->trip->getDriver()) {
            $context->buildViolation('Le conducteur ne peut pas réserver son propre trajet.')->atPath('passenger')->addViolation();
        }
    }

    /** Positions of the legs this booking occupies: from fromStop's position up to (not including) toStop's. */
    public function occupiesLeg(int $legIndex): bool
    {
        return $legIndex >= $this->fromStop->getPosition() && $legIndex < $this->toStop->getPosition();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(Trip $trip): static
    {
        $this->trip = $trip;

        return $this;
    }

    public function getPassenger(): ?User
    {
        return $this->passenger;
    }

    public function setPassenger(User $passenger): static
    {
        $this->passenger = $passenger;

        return $this;
    }

    public function getFromStop(): ?TripStop
    {
        return $this->fromStop;
    }

    public function setFromStop(TripStop $fromStop): static
    {
        $this->fromStop = $fromStop;

        return $this;
    }

    public function getToStop(): ?TripStop
    {
        return $this->toStop;
    }

    public function setToStop(TripStop $toStop): static
    {
        $this->toStop = $toStop;

        return $this;
    }

    public function getSeats(): int
    {
        return $this->seats;
    }

    public function setSeats(int $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getTotalPrice(): int
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(int $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function getStatus(): BookingStatus
    {
        return $this->status;
    }

    public function setStatus(BookingStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;

        return $this;
    }

    /** @return list<string> */
    public function getSeatCodes(): array
    {
        return $this->seatCodes ?? [];
    }

    /** @param list<string> $codes */
    public function setSeatCodes(array $codes): static
    {
        $this->seatCodes = array_values($codes);

        return $this;
    }

    /**
     * @param array<string, int> $bags counts keyed by LuggageSize value; missing sizes count 0
     */
    public function setBags(array $bags): static
    {
        $this->cabinBags = $bags[LuggageSize::Cabin->value] ?? 0;
        $this->mediumBags = $bags[LuggageSize::Medium->value] ?? 0;
        $this->largeBags = $bags[LuggageSize::Large->value] ?? 0;

        return $this;
    }

    /** @return array{cabin: int, medium: int, large: int} */
    public function getBags(): array
    {
        return [
            LuggageSize::Cabin->value => $this->cabinBags,
            LuggageSize::Medium->value => $this->mediumBags,
            LuggageSize::Large->value => $this->largeBags,
        ];
    }

    public function getLuggageLitres(): int
    {
        return LuggageSize::litresOf($this->getBags());
    }

    public function getBagCount(): int
    {
        return array_sum($this->getBags());
    }

    public function getCabinBags(): int
    {
        return $this->cabinBags;
    }

    public function setCabinBags(int $cabinBags): static
    {
        $this->cabinBags = $cabinBags;

        return $this;
    }

    public function getMediumBags(): int
    {
        return $this->mediumBags;
    }

    public function setMediumBags(int $mediumBags): static
    {
        $this->mediumBags = $mediumBags;

        return $this;
    }

    public function getLargeBags(): int
    {
        return $this->largeBags;
    }

    public function setLargeBags(int $largeBags): static
    {
        $this->largeBags = $largeBags;

        return $this;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(Payment $payment): static
    {
        $this->payment = $payment;
        if ($payment->getBooking() !== $this) {
            $payment->setBooking($this);
        }

        return $this;
    }
}
