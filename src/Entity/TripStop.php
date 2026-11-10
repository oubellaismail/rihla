<?php

namespace App\Entity;

use App\Repository\TripStopRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A stop of a trip. The price and distance stored here belong to the leg
 * that starts at this stop; they are null on the last stop.
 */
#[ORM\Entity(repositoryClass: TripStopRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_TRIP_STOP_POSITION', fields: ['trip', 'position'])]
#[ORM\Index(name: 'IDX_TRIP_STOP_CITY_DEPARTURE', columns: ['city_id', 'departure_at'])]
class TripStop
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stops')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Trip $trip = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?City $city = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private ?int $position = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $departureAt = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private ?string $meetingPoint = null;

    /** Price in MAD per seat for the leg to the next stop. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $legPriceToNext = null;

    /** Road distance in km to the next stop (from OSRM). */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $legDistanceKmToNext = null;

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->city, $this->departureAt?->format('d/m H:i'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrip(): ?Trip
    {
        return $this->trip;
    }

    public function setTrip(?Trip $trip): static
    {
        $this->trip = $trip;

        return $this;
    }

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function setCity(City $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getDepartureAt(): ?\DateTimeImmutable
    {
        return $this->departureAt;
    }

    public function setDepartureAt(\DateTimeImmutable $departureAt): static
    {
        $this->departureAt = $departureAt;

        return $this;
    }

    public function getMeetingPoint(): ?string
    {
        return $this->meetingPoint;
    }

    public function setMeetingPoint(string $meetingPoint): static
    {
        $this->meetingPoint = $meetingPoint;

        return $this;
    }

    public function getLegPriceToNext(): ?int
    {
        return $this->legPriceToNext;
    }

    public function setLegPriceToNext(?int $legPriceToNext): static
    {
        $this->legPriceToNext = $legPriceToNext;

        return $this;
    }

    public function getLegDistanceKmToNext(): ?int
    {
        return $this->legDistanceKmToNext;
    }

    public function setLegDistanceKmToNext(?int $legDistanceKmToNext): static
    {
        $this->legDistanceKmToNext = $legDistanceKmToNext;

        return $this;
    }
}
