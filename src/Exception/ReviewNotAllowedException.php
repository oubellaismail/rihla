<?php

namespace App\Exception;

/** A review that breaks a business rule. The message is shown to the user, hence in French. */
class ReviewNotAllowedException extends \DomainException
{
}
