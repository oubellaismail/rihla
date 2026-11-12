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

## 2026-11-10 — Level 3: the product

- **Design approved first** ([`level3-product.drawio`](diagrams/level3-product.drawio): site map and components), then built.
- **Tests first, at two levels.** New business code (`TripSearch`, `TripPublisher`, the two voters, profile figures) was specified by integration and unit tests; every page was specified by functional tests that click through the flows. The pages were then built by three Sonnet subagents in parallel, against fixed route names, and reviewed.
- **Routes in English, pages in French**: `/trips`, `/bookings/{id}/cancel`, `/driver/trips`… with French content.
- **Permissions live in voters** (`TripVoter`, `BookingVoter`), not in templates or controllers: only the driver sees the passenger list and cancels the trip, only the passenger cancels or reviews their booking. Ownership is never decided on ids alone.
- **Privacy**: a public profile shows the first name and initial only, never the email or phone. The driver's phone appears on a booking only while it is confirmed; the driver sees passengers' phones on their own trip.
- **No invented numbers** on the home page: free seats per leg, the latest booking and the reviews shown are read from the database.
- **Search is by city name, in any case**, and on a day; a trip is found for any portion served in the right direction with enough free seats on every leg of that portion.
- **Not in Level 3**: editing a trip once published (a driver cancels it instead, which refunds everyone), choosing a specific seat (bonus), the map (Level 4 fills the reserved space).

## 2026-11-11 — Level 4: map, geocoding and routing

Design: [`docs/diagrams/level4-map.drawio`](diagrams/level4-map.drawio).

- **Free services, no key.** Nominatim (OpenStreetMap) for addresses, OSRM for road distance, time and the line to draw, OpenStreetMap tiles in Leaflet. No account or secret is needed, so the project runs on any machine.
- **The browser never calls Nominatim or OSRM.** It calls our `/api/geocode`, `/api/reverse` and `/api/route` (logged-in users, 30 calls per minute). The server sends an identifying User-Agent, caches answers for 30 days and calls Nominatim at most once per second, as their usage policies ask. Only the map tiles come straight from OpenStreetMap, with the attribution.
- **The route is saved when the trip is published** (distance, time and line per leg). Trip pages never call OSRM: they stay fast and keep working if OSRM is down later.
- **One call per leg**, not one for the whole trip: each leg gets its own line, so the map can draw a passenger's portion in orange.
- **Suggestions, not rules.** Suggested price: 0.35 MAD per km, rounded to 5 MAD (175 km gives 60 MAD, close to the prices drivers already set in the demo data). Suggested time at the next stop: driving time plus a 10-minute pause, rounded up to 5 minutes. The driver can type anything; the Level 3 checks decide.
- **Privacy decided on the server.** The exact meeting point is only put in the page for the driver and for passengers with a confirmed or completed booking, and a passenger only gets their own two stops. Hiding it with JavaScript would still leak it in the page source; a test checks the HTML of a visitor.
- **When a service fails** (timeout of 3 s, error, garbage): no exception reaches the user. The publish page shows « Suggestions indisponibles pour le moment. », the map draws dashed straight lines between cities, and publishing still works. After one failed leg the others are not tried, so publishing never waits more than 3 s for OSRM.
- **Tests never use the network.** `tests/Support/FakeGeoApis.php` answers in place of Nominatim and OSRM, and can simulate an outage.

## 2026-11-11 — Meeting-point picker and finished trips

- **Meeting point on a large map, per stop.** « Choisir sur la carte » opens a map centred on the stop's city with a fixed centre pin: the driver moves the map, not the pin. Places other drivers already used in that city are suggested (real data, at most 5).
- **15 km rule.** A meeting point more than 15 km (straight line) from its city is refused, in the picker and again by `TripPublisher`, so a passenger looking for « Essaouira » never waits in the wrong town.
- **Finished and cancelled trips get their own pages.** A finished trip is a summary (passengers carried, money received, reviews), not an upcoming trip with seats left; a cancelled one lists who was refunded.

## 2026-11-11 — Seat choice (bonus)

Design: [`docs/diagrams/seat-choice.drawio`](diagrams/seat-choice.drawio), mockup boards « Choisir ses places » and « Place déjà réservée » (v2).

- **Two car plans.** Up to 4 passenger seats is a berline (A1 in front, B1 B2 B3 in the back); 5 or 6 is a monospace with a third row (C1 C2).
- **Seats open in comfort order** A1, B1, B3, B2, C1, C2. A driver offering 3 seats in a 4-seat car keeps the back middle seat closed; it is shown « Non proposée ». Publishing a trip did not change.
- **Seats follow the legs, like the seat counts.** A seat taken from Agadir to Essaouira is free from Essaouira on: the plan shows it « libérée en route ». The per-leg count of Level 2 is still checked first; the seat check comes after it, inside the same lock on the trip row, so two people choosing the same seat at the same time cannot both get it. The slower one sees « La place B3 vient d'être réservée » with another free seat to book in one tap.
- **One seat per place, optional.** Booking two places means choosing two seats; « Pas de préférence, choisir pour moi » lets Rihla pick, side by side in the same row when possible. Every booking ends up with seat numbers.
- **Privacy.** A taken seat says « occupée », never who sits there.
- **Older bookings keep working.** A booking made before seat choice has no seat stored; it is given the first free seats in comfort order when the plan is drawn, so no data migration is needed.
- **Works without JavaScript.** Seats are checkboxes in a form; JavaScript only limits the number ticked and updates the summary.

## 2026-11-12 — Luggage (trunk volume)

Design: [`docs/diagrams/luggage.drawio`](diagrams/luggage.drawio), mockup boards « Vos bagages, voyageur », « Le coffre, conducteur » and « Le coffre dans les pages ».

- **Litres for the car, sizes for the passenger.** Car makers publish the trunk volume in litres (Logan ≈ 530 L, Clio ≈ 390 L, 208 ≈ 310 L), so the driver types that number once, on the vehicle. Passengers do not know their bag in litres, so they pick a size: « Bagage cabine » 40 L, « Valise moyenne » 70 L, « Grande valise » 100 L. A handbag or small backpack is never counted.
- **80 % of the trunk is usable.** The maker's figure is measured with 1-litre blocks that fill every corner; rigid suitcases do not. A Logan's 530 L gives 424 L, about four large suitcases.
- **The trunk is frozen on the trip.** When publishing, the trip copies the vehicle's volume and the driver may lower it for that trip (own things, 0 for no luggage). It is not editable afterwards, like the seats offered.
- **Bags follow the legs, like seats.** On every leg of the passenger's portion, the litres booked plus the new bags must fit. A bag unloaded at an intermediate stop frees its litres for the rest of the trip.
- **Checked inside the same lock, after the seats.** The seat rules come first; then at most 2 counted bags per place, then the volume. If the trunk filled meanwhile, `TrunkFullException` refuses the booking and the seat page comes back (409) with the choices kept. A booking without bags is always possible.
- **Free.** Bags never change the price.
- **Shown where it helps a decision:** search results and the trip page (« Coffre : place pour 2 grandes valises »), the booking page, and the driver's page, stop by stop (what to load, what to unload).

## 2026-11-12 — The driver's trunk as a checklist

Tried on a local branch with the frontend-design skill, then chosen from three ideas (checklist per stop, passenger timeline, trunk drawing).

- **A checklist, not a gauge.** At a stop the driver asks « whose bag comes out, whose goes in », not « how many litres ». Each stop lists « Rendre d'abord », then « Puis ranger » (just « Rendre » or « Ranger » when there is only one), in the order it is done on the pavement. Every line is a box to tick; ticks are not saved.
- **Furthest first, at the back.** Bags going furthest are listed first and marked « à mettre au fond » when the stop loads bags for more than one destination. Every line says where its passenger gets off.
- **Words before litres.** After each stop: « Coffre à moitié plein jusqu'à Essaouira. Il reste de la place pour une grande valise. » Litres stay in brackets. Thresholds: empty, under 40 % « peu chargé », under 70 % « à moitié plein », under 95 % « presque plein », then « plein ».
- **Size without numbers.** Each bag is a suitcase icon, larger for larger bags.
- **The driver's trip page uses the full width.** Map and stops on the left, trunk and cancellation on the right; one column under 960 px.
