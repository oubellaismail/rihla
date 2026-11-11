<?php

namespace App\Service\Geo;

/** Internal: a call to an external map service failed. Thrown inside a cache callback so the failure is not cached. */
final class GeoFailure extends \RuntimeException
{
}
