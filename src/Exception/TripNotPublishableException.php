<?php

namespace App\Exception;

/** A trip that cannot be published because it breaks a business rule. The message is shown to the driver, hence in French. */
class TripNotPublishableException extends \DomainException
{
}
