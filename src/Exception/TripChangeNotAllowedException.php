<?php

namespace App\Exception;

/** A delay or a stop removal breaks a rule; the (French) message is meant to be shown to the driver. */
final class TripChangeNotAllowedException extends \DomainException
{
}
