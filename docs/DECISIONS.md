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
