# Project status

Where Rihla stands at the deadline (15 November 2026), for the challenge: levels reached, what works, what is left. How to run it is in [`INSTALL.md`](../INSTALL.md); why things are the way they are is in [`DECISIONS.md`](DECISIONS.md).

## Levels reached

| Level | Scope | Status |
|---|---|---|
| 1 — Model | Entities, migrations, demo data, back-office | Done |
| 2 — Rules | Seats per leg, no overbooking, cancellations, reviews, tests | Done |
| 3 — Product | Accounts, search, booking, driver space, profiles | Done |
| 4 — External API | Nominatim and OSRM: suggested price, route and map | Done |
| 5 — Delivery | Docker, `INSTALL.md` | Done |
| Bonus — Seat choice | Choose your seat on a plan of the car; seats follow the legs | Done |
| Bonus — Luggage | Trunk volume, bags declared at booking and checked leg by leg | Done |

## What works

**Passenger**
- Search by portion (with or without a date), « Prochains départs » when nothing is searched; result cards draw the route as a timeline.
- Filters (departure time, maximum price, a bag that still fits, well-rated drivers) and sorting (departure, price, rating), kept in the address.
- Trip page with every leg, its free seats and its price; change the portion on the page.
- Booking of 1 to 4 seats, seat choice on a plan of the car, bags by size with a check against the trunk on the portion.
- « Mes réservations », cancellation with the refund shown before confirming (100 % up to 24 h, 50 % after, nothing once the car has left).
- Reviews of the driver after the trip; public profiles with ratings.

**Driver**
- « Publier un trajet » with stops, a meeting point picked on a map, a price per leg suggested from the road distance (OSRM), times filled in from road durations.
- Driver dashboard, « Mes trajets », passengers listed stop by stop, the trunk as a checklist of what to load and unload.
- Trip cancellation with full refunds; « Mes gains » (earned, upcoming, fill rate, seats resold along the way).
- « Mes véhicules »: add, edit, and remove a car (archived if it was used, refused while a trip is coming).

**Platform**
- Accounts, sign-up, a passenger space and a driver space with a switch.
- Back-office: read-only views of trips, stops, bookings and payments; « Annuler le trajet » with refunds; review moderation; users and cities. Admin accounts stay in the back-office.
- French error pages (404, 403, 500 and others).
- Trips become « Terminé » after their last stop (`bin/console app:trips:complete`, also run by the pages).

## Tests

**548 tests, 1,569 assertions**, all green: `docker compose exec php composer test`.

- Unit tests for the pure rules (seats per leg, prices, refunds, permissions).
- Integration tests on a separate `rihla_test` database, including a real concurrency test where two processes book the last seat at the same time.
- Functional tests that click through the pages like a user.
- Query-count tests: search, « Prochains départs » and « Mes trajets » must run the same number of SQL queries for one trip as for six.

## Performance

Measured on 15 November in prod mode, on a copy of the demo data grown to 519 trips:

| Page | Before | After |
|---|---|---|
| Search Fès → Tanger (~170 results) | 488 queries, 749 ms | 9 queries, 174 ms |
| Search Agadir → Tiznit | 351 queries, 308 ms | 9 queries, 147 ms |
| « Mes trajets » (driver, ~340 trips) | 356 queries, 300 ms | 5 queries, 186 ms |

Load test with `ab` (500 requests, 20 at a time): no failed request; search went from 7 to 18 requests per second. These numbers come from a laptop (Docker on macOS, 5 PHP-FPM workers): they show the code has no structural problem, not production capacity.

## Known limits

- **Payments are simulated.** No payment provider; refunds are recorded, not sent.
- **No live tracking.** The app does not know when the driver leaves, where the car is, or whether it is late. A trip ends by the clock.
- **No in-app messaging or e-mail notifications** for delays and cancellations; the passenger sees them on their booking.
- **City photos on the landing page are AI-generated** (stated in the footer).
- **Nominatim and OSRM are the public demo servers**, rate-limited; a production version would host its own or use a paid provider.

## With one more week

1. A payment provider (CMI or a card processor) and real refunds.
2. E-mail and SMS notifications: booking confirmed, trip changed, trip cancelled, reminder the day before.
3. A mobile version for drivers: « Je suis parti », position shared during the trip, lateness warning.
4. Recurring trips (« every Friday Agadir → Casablanca »).
5. Deployment on a server with HTTPS, backups and monitoring.
