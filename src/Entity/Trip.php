<?php

namespace App\Entity;

use App\Enum\TripStatus;
use App\Repository\TripRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A trip is an ordered list of stops. The leg between stop N and stop N+1
 * is priced on stop N. This row is the one locked when someone books.
 */
#[ORM\Entity(repositoryClass: TripRepository::class)]
#[ORM\Index(name: 'IDX_TRIP_STATUS', columns: ['status'])]
class Trip
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $driver = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vehicle $vehicle = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 6)]
    private int $seatsOffered = 3;

    #[ORM\Column(length: 20, enumType: TripStatus::class)]
    private TripStatus $status = TripStatus::Published;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TripStop> */
    #[ORM\OneToMany(targetEntity: TripStop::class, mappedBy: 'trip', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Count(min: 2, minMessage: 'Un trajet a au moins un départ et une arrivée.')]
    #[Assert\Valid]
    private Collection $stops;

    /** @var Collection<int, Booking> */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'trip')]
    private Collection $bookings;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->stops = new ArrayCollection();
        $this->bookings = new ArrayCollection();
    }

    public function __toString(): string
    {
        $first = $this->getFirstStop();
        $last = $this->getLastStop();

        return $first && $last ? sprintf('%s → %s', $first->getCity(), $last->getCity()) : 'Trajet';
    }

    #[Assert\Callback]
    public function validateSeats(ExecutionContextInterface $context): void
    {
        if ($this->vehicle && $this->seatsOffered > $this->vehicle->getPassengerSeats()) {
            $context->buildViolation('Le véhicule n\'a que {{ max }} places passagers.')
                ->setParameter('{{ max }}', (string) $this->vehicle->getPassengerSeats())
                ->atPath('seatsOffered')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDriver(): ?User
    {
        return $this->driver;
    }

    public function setDriver(User $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    public function getVehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function getSeatsOffered(): int
    {
        return $this->seatsOffered;
    }

    public function setSeatsOffered(int $seatsOffered): static
    {
        $this->seatsOffered = $seatsOffered;

        return $this;
    }

    public function getStatus(): TripStatus
    {
        return $this->status;
    }

    public function setStatus(TripStatus $status): static
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

    /** @return Collection<int, TripStop> stops ordered by position */
    public function getStops(): Collection
    {
        return $this->stops;
    }

    /** Appends a stop at the next position. */
    public function addStop(TripStop $stop): static
    {
        if (!$this->stops->contains($stop)) {
            if (null === $stop->getPosition()) {
                $stop->setPosition($this->stops->count());
            }
            $this->stops->add($stop);
            $stop->setTrip($this);
        }

        return $this;
    }

    public function removeStop(TripStop $stop): static
    {
        $this->stops->removeElement($stop);

        return $this;
    }

    public function getFirstStop(): ?TripStop
    {
        return $this->stops->first() ?: null;
    }

    public function getLastStop(): ?TripStop
    {
        return $this->stops->last() ?: null;
    }

    /** @return Collection<int, Booking> */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }
}
