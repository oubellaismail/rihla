<?php

namespace App;

/**
 * The two spaces of one account (like inDrive): the passenger one and the driver one.
 * This is the only place that says which route belongs to which space.
 */
final class Space
{
    public const PASSENGER = 'passenger';
    public const DRIVER = 'driver';
    public const SESSION_KEY = 'rihla_space';

    /** Route names that belong to the driver space. */
    private const DRIVER_ROUTES = ['app_trip_new', 'app_vehicles', 'app_vehicle_delete', 'app_vehicle_edit'];
    /** Route name prefixes that belong to the driver space. */
    private const DRIVER_PREFIXES = ['app_driver_'];

    /** Route names that belong to the passenger space. */
    private const PASSENGER_ROUTES = ['app_trip_search', 'app_trip_show', 'app_trip_seats', 'app_trip_book'];
    /** Route name prefixes that belong to the passenger space. */
    private const PASSENGER_PREFIXES = ['app_booking_'];

    /** @return self::PASSENGER|self::DRIVER|null null when the route belongs to neither space */
    public static function ofRoute(?string $route): ?string
    {
        if (null === $route) {
            return null;
        }
        if (\in_array($route, self::DRIVER_ROUTES, true) || self::startsWithAny($route, self::DRIVER_PREFIXES)) {
            return self::DRIVER;
        }
        if (\in_array($route, self::PASSENGER_ROUTES, true) || self::startsWithAny($route, self::PASSENGER_PREFIXES)) {
            return self::PASSENGER;
        }

        return null;
    }

    /** @param list<string> $prefixes */
    private static function startsWithAny(string $route, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
