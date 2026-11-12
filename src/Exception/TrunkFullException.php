<?php

namespace App\Exception;

/** The bags the passenger declared no longer fit in the trunk on their portion. */
final class TrunkFullException extends BookingNotAllowedException
{
    public function __construct(private readonly int $freeLitres)
    {
        parent::__construct(sprintf('Plus assez de place dans le coffre : il reste %d L sur cette portion.', $freeLitres));
    }

    public function getFreeLitres(): int
    {
        return $this->freeLitres;
    }
}
