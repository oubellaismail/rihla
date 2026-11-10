<?php

namespace App\Entity;

use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Simulated payment of a booking; no real money moves (allowed by the brief). */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'payment')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $amount = 0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $refundedAmount = 0;

    #[ORM\Column(length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status = PaymentStatus::Paid;

    #[ORM\Column]
    private \DateTimeImmutable $paidAt;

    public function __construct()
    {
        $this->paidAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%d MAD (%s)', $this->amount, $this->status->label());
    }

    /** Records a refund and updates the status (partial or full). */
    public function refund(int $amount): static
    {
        $this->refundedAmount = min($this->amount, $this->refundedAmount + max(0, $amount));
        $this->status = $this->refundedAmount >= $this->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(Booking $booking): static
    {
        $this->booking = $booking;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getRefundedAmount(): int
    {
        return $this->refundedAmount;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPaidAt(): \DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }
}
