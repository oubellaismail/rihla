# Decision log

Every entry is dated. Design decisions made before the first commit (mockup phase, 9 and 10 November 2026) are recorded here as they were taken: the repository was created on 10 November, after that phase.

## 2026-11-09 — Choice of subject

- **Subject 3, regional carpooling.** The core rule (seats per leg, partial bookings, concurrent access) is a real algorithmic and transactional problem that can be tested in isolation. It carries Levels 1 and 2.
- **Stack**: Symfony 7.4 LTS, MySQL 8.4, Twig with Stimulus and Turbo. No separate front end: the time goes into business rules and tests.

## 2026-11-09 / 10 — Design phase (mockup)

Before coding, a 10-screen interactive mockup was built to settle the product questions. It is described in [`docs/design/`](design/).

- **Identity**: name "Rihla", "Golden hour" palette (dusk blue `#22305C`, sunset orange `#E8743B`, saffron, sand, asphalt), Bricolage Grotesque and Figtree fonts.
- **Interface without the "AI look"**: rules from the Impeccable skill applied (no nested cards, no emoji as icons, a single radius scale, real photos of the roads).
- **Choosing a specific seat in the car**: mocked up, but **deferred as a bonus**. The brief asks for seats to be counted per leg; per-seat choice makes the rule and its tests more complex. It will only be added once Level 2 is done and tested.

## 2026-11-10 — Languages

- **Code, documentation, commits and README in English.**
- **The application's interface is in French**, the language of its Moroccan users.

## Answers to the brief's open questions

### Can a seat freed at an intermediate stop be resold for the rest of the trip?
**Yes.** A booking occupies **legs**, not a seat for the whole trip. If Youssef gets off at Essaouira, the seat is free from Essaouira to Casablanca. Free seats on a portion = seats offered − the maximum number of seats taken on any leg of that portion.
*Rejected*: one seat for the whole trip, which loses money for the driver and does not match the brief.

### How is the price of a portion of the trip calculated?
The price of a portion is the **sum of the prices of its legs**. The driver sets the price of each leg; Rihla **suggests** a price from the real road distance (OSRM): 0.35 MAD per kilometre per passenger, rounded to 5 MAD.
*Rejected*: a price proportional to the full trip, which ignores mountain roads that are much longer than the straight-line distance.

### What happens when the driver changes a time or removes a stop after bookings?
- **Delay under 30 minutes**: bookings are kept and passengers are notified.
- **Delay of 30 minutes or more**: each affected passenger can cancel free of charge.
- **Stop removed**: bookings that board or get off at that stop are cancelled and fully refunded.
- The driver sees the effect of the change on each passenger **before** saving it.

### Can a passenger book several seats at once?
**Yes, 1 to 4 seats** in a single booking. Availability is checked for the requested number of seats on every leg of the portion.

### Who reviews whom, and when?
The passenger reviews the driver, and the driver reviews each passenger. A review is only possible **after a completed trip**, within 14 days, once per booking and per author (uniqueness constraint in the database).

## Cancellation rules

- **Passenger**: full refund up to 24 h before the departure of their portion, 50 % after that, nothing once the car has left.
- **Driver**: every booking is fully refunded and passengers are notified; the cancellation is counted on the driver's profile.

## Concurrent access (overbooking)

To book, the service opens a transaction, **locks the trip row** (`SELECT … FOR UPDATE`, `LockMode::PESSIMISTIC_WRITE` in Doctrine), recalculates free seats per leg, then saves or rejects the booking. Two simultaneous bookings for the last seat are therefore handled one after the other: the second one sees the seat taken and gets a clear message.
*Rejected*: optimistic locking alone (a `version` column), which would force the client to retry in a case that is common on small trips.

## 2026-11-10 — Level 1 implemented

- The four design diagrams were approved before any entity was written ([`docs/diagrams/`](diagrams/)).
- **Users, bookings, payments and reviews cannot be created by hand in the back-office.** They come from the application flows (sign-up, booking service), so the business rules and the overbooking lock cannot be bypassed from `/admin`. Trips, stops, vehicles and cities stay editable for support.
- **Demo data loads automatically** on an empty database in dev, so `docker compose up` gives a working demo straight away. Dates are relative to today, so there are always upcoming trips.
- **Table name `app_user`**: `user` is reserved in several SQL dialects.

## 2026-11-10 — Level 2: business rules

- **Design approved first** (Level 2 services diagram and concurrency test sequence), then implemented.
- **No Symfony Workflow component.** Bookings have four statuses; each transition lives in one service method guarded by explicit checks (`BookingService`, `CancellationService`, `TripCompletionService`). That is easier to read and to test than a state machine configuration for so few states.
- **Pure rules are separate from the services.** `SeatAvailabilityCalculator`, `PriceCalculator`, `CancellationPolicy` and `ReviewPolicy` take plain values (including "now") and never touch the database, so they are unit-tested without a kernel. Services only load data, lock, call the rules and save.
- **Time comes from Symfony's `ClockInterface`.** Tests freeze it (`ClockSensitiveTrait`), so the 24-hour refund limit and the 14-day review window are tested to the minute.
- **The lock covers every write that changes seat counts or the trip status**: booking and the driver cancelling the trip both lock the trip row first. Bookings are always reloaded from the database inside the lock, never taken from a possibly stale in-memory collection.
- **Cancelling after departure is allowed for a passenger but refunds nothing** (policy band 0 %); a driver can no longer cancel once the trip has started.
- **Trip completion runs as a command** (`app:trips:complete`, for a cron job): it completes trips whose last stop's time has passed, which opens the 14-day review window.
- **Concurrency is proven, not assumed**: an integration test starts two real PHP processes booking the last seat at the same time; one keeps the lock for 1.5 s. Exactly one booking exists afterwards.

## 2026-11-10 — How business code is written (AI workflow)

Every business function follows the same loop, visible in the Git history:

1. **Claude Opus writes the tests first** and they are committed failing (`test: …` commits).
2. **Claude Sonnet subagents write the implementation** against those tests, one class per agent, without touching the tests and without committing.
3. **Claude Opus reviews the code** and sends feedback to the same agent until it is right; only then is it committed (`feat: …` commits, with both `Co-Authored-By` trailers and the review notes in the message).

Examples from Level 2: the seat-count error message was sent back because it described the whole trip instead of the passenger's portion and used incorrect French; the cancellation agent found that private services were missing from the test container and that the Docker `APP_ENV=dev` variable overrode PHPUnit's, which led to a fix in the test configuration.

## 2026-11-10 — How the map is used (studied before Level 3)

Before designing the pages we looked at how competitors use maps. **Uber** and inDrive's city rides are on-demand (a car in five minutes), while Rihla is scheduled intercity carpooling booked days ahead. The closest references are **BlaBlaCar** and **inDrive Intercity**.

| Seen at | What their map does | Kept for Rihla? |
|---|---|---|
| Uber | Suggests a precise, easy-to-reach pickup spot, with walking guidance | Yes, as an exact meeting point plus a link to the passenger's own GPS app |
| Uber, BlaBlaCar "Ease Pickup" | Live tracking of the car approaching | No: needs a mobile app and live GPS (future work) |
| BlaBlaCar "Ride Map" | The driver marks the exact departure spot on a map | Yes, for every stop, not only the departure |
| BlaBlaCar search | Exact points, so any point on the road can be a meeting point | Already covered by intermediate stops |
| inDrive Intercity | Passenger sets pickup on a map and proposes a price; drivers bid | No: haggling conflicts with the price being frozen at booking |

**What Rihla does with the map** (Level 4, with Nominatim, OSRM and Leaflet; the Level 3 pages keep a space for it):

1. **Trip page: the passenger's portion is highlighted.** Full route in grey, the booked portion in orange, with boarding and drop-off pins. It shows partial booking, the core idea of the project, at a glance.
2. **Exact meeting point with "Ouvrir dans Google Maps / Waze".** One tap and the passenger's own navigation app guides them there; no tracking needed.
3. **Privacy: exact pin only after booking.** Before booking, the passenger sees the city and an approximate area; the driver's exact address is not public.
4. **Publishing a trip: the route is drawn as stops are added.** The driver clicks the map to place each meeting point; for every leg Rihla fills in the road distance, the **suggested price** (0.35 MAD/km, see `PriceCalculator`) and the **suggested time of the next stop** from the driving time. The driver can change all of them.
5. **Driver's trip: legs coloured by occupancy** (seats left, almost full, full), so the driver sees where seats free up.

**Future work** (only if time allows): live tracking on the day of the trip, map-based search ("trips passing near me"), price negotiation.
