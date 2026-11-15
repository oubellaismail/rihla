# Product

What Rihla is, who it is for, and the principles behind its decisions. The visual identity is in [`docs/design/`](docs/design/); dated decisions are in [`docs/DECISIONS.md`](docs/DECISIONS.md).

## The problem

Between Moroccan cities, many cars travel with empty seats, and many people need only **part** of a route. A student goes from Essaouira to Marrakech; a driver already makes Agadir → Casablanca every Friday and passes through both.

Carpooling platforms usually sell a seat for a whole trip. A passenger going only part of the way pays for the whole route or does not find a ride at all. For the driver, a seat freed at Essaouira stays empty until Casablanca.

## The promise

> « Vous ne payez que votre bout de route. »

A trip is a chain of **legs** (Agadir → Essaouira → Marrakech → Casablanca). Each leg has its own price and its own free seats. A passenger books the portion they need and pays the sum of its legs. When they get off, their seat goes back on sale for the rest of the route.

## Who it is for

| | Need | What Rihla gives them |
|---|---|---|
| **Passenger** | A seat between two cities, at a fair price, with their luggage | Search by portion, price « dès » the cheapest leg, choose a seat on a plan of the car, declare bags and see if they fit, cancel with clear refund rules, be told by e-mail and under the bell when the driver changes or cancels, review the driver |
| **Driver** | Share the cost of a trip they make anyway | Publish a trip with stops, a price per leg suggested from the real road distance, a meeting point on a map, passengers listed stop by stop, a trunk checklist, delay the trip or drop a stop with a preview of who is affected, be told of new bookings and cancellations, « Mes gains » |
| **Admin** | Keep the platform healthy | A back-office to see everything, cancel a trip with full refunds, moderate reviews, manage users and cities |

One account can be both passenger and driver: a « Passager | Conducteur » switch says which role is active. An admin account is kept apart and only sees the back-office.

## Principles

1. **The portion is the unit.** Search, price, seats, luggage, refunds and the driver's view are all computed leg by leg. It is the rule of the subject, and what sets Rihla apart.
2. **Never sell a seat twice.** A booking locks the trip, recomputes the free seats on every leg of its portion, then saves or refuses. A real concurrency test covers it.
3. **Speak like the user, not like the database.** Passengers count suitcases, not litres. Drivers see « Vous emportez 2 valises », not a volume. Errors say what happened and what to do, in French.
4. **Suggest, never impose.** Rihla suggests prices from OSRM distances and departure times from road durations. The driver keeps the last word, except for times that cannot physically be met.
5. **The history stays true.** A car used on past trips is archived, not deleted. A finished trip becomes a summary. Reviews are tied to a booking that really happened.
6. **Moroccan context.** French interface, prices in MAD, real cities and meeting points (gares routières, places), golden-hour photos of Moroccan roads.

## Scope

**In:** trips with stops between Moroccan cities, booking by portion (1 to 4 seats), seat choice, luggage, simulated payment and refunds, cancellations by the passenger or the driver, reviews both ways, public profiles, driver earnings, a map with the route and meeting points, a back-office.

**Out, on purpose:**
- **Real payment.** Payments are simulated; a payment provider is the step after the challenge.
- **Live tracking** of the car (started, leg done, delay). It needs a mobile app sending the driver's position. A trip is « Terminé » once the time of its last stop has passed.
- **In-app messaging.** Passengers and the driver use the phone number shown on a confirmed booking.
- **Trips outside Morocco** and recurring trips.

## What success looks like

- A passenger going Essaouira → Marrakech finds a car that drives Agadir → Casablanca, and pays only their part.
- A driver sells the same seat twice on one trip, to two people who never share the car.
- Nobody is ever overbooked, even when two people book the last seat at the same second.
