<?php

namespace App\Exception;

/** A booking request that breaks a business rule. The message is shown to the user, hence in French. */
class BookingNotAllowedException extends \DomainException
{
}
