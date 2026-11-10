<?php

namespace App\Entity;

use App\Repository\VehicleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
class Vehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private ?string $model = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    private ?string $color = null;

    /** Seats available to passengers (driver excluded): 4 for a sedan, up to 6 for a van. */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 6)]
    private int $passengerSeats = 4;

    public function __toString(): string
    {
        return trim($this->model . ' ' . $this->color);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getPassengerSeats(): int
    {
        return $this->passengerSeats;
    }

    public function setPassengerSeats(int $passengerSeats): static
    {
        $this->passengerSeats = $passengerSeats;

        return $this;
    }
}
