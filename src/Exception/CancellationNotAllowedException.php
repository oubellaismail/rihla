<?php

namespace App\Exception;

/** A cancellation request breaks a rule; the (French) message is meant to be shown to the user. */
final class CancellationNotAllowedException extends \DomainException
{
}
