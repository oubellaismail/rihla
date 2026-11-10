<?php

namespace App\Model;

/** Aggregated rating of a user: average (one decimal, null without reviews) and number of reviews. */
final readonly class RatingSummary
{
    public function __construct(
        public ?float $average,
        public int $count,
    ) {
    }
}
