# Rihla — regional carpooling in Morocco

Built for the **EMC PFE Challenge** (Education Media Company, Agadir).
Chosen subject: **Subject 3 — Regional carpooling platform**.

Rihla (رحلة, "journey") connects drivers travelling between Moroccan cities, with stops along the way, and passengers who can book **all or part** of the route. A seat freed at an intermediate stop goes back on sale for the rest of the trip.

## Stack

- Symfony 7.4 (LTS), PHP 8.4
- MySQL 8.4, Doctrine ORM and migrations
- Twig, Stimulus and Turbo (Symfony UX), AssetMapper
- Docker Compose: PHP 8.4-FPM, Nginx, MySQL 8.4

## Running the project

Requirements: Docker and Docker Compose.

```bash
docker compose up -d --build
```

The app is served at <http://localhost:8080>. On first start, the PHP container installs the dependencies, waits for MySQL, runs the migrations and, on an empty database, loads the demo data.

### Demo accounts

All demo accounts use the password `rihla2026`.

| Account | Role |
|---|---|
| `admin@rihla.ma` | Back-office at <http://localhost:8080/admin> |
| `karim@rihla.ma` | Driver of the Agadir → Essaouira → Marrakech → Casablanca trip |
| `salma@rihla.ma` | Passenger with upcoming, completed and cancelled bookings |

Try it: search **Essaouira → Marrakech** on the date of Karim's trip, open the trip, book a seat as Salma, then cancel it from *Mes trajets*. Log in as Karim to see the passengers per leg on *Mes trajets › En tant que conducteur*.

The full guide (configuration, tests, reset, troubleshooting) is in [`INSTALL.md`](INSTALL.md).

## Progress

| Level | Scope | Status |
|---|---|---|
| 1 — Model | Entities, migrations, demo data, back-office | Done |
| 2 — Rules | Seats per leg, no overbooking, cancellations, reviews, tests | Done |
| 3 — Product | Accounts, search, booking, driver space, profiles | Done (149 tests in total) |
| 4 — External API | Nominatim and OSRM: suggested price and map | Done |
| 5 — Delivery | Docker, `INSTALL.md` | Done (251 tests in total) |

## Tests

```bash
docker compose exec php composer test
```

Runs the unit tests (pure rules, permissions), the integration tests on a separate `rihla_test` MySQL database (including a real concurrency test where two processes book the last seat at the same time) and the functional tests, which click through the pages like a user: sign up, search, book, cancel, review, publish a trip, cancel it. Each integration test is rolled back, so the demo data is never touched.

## Documentation

- [`docs/MODELE.md`](docs/MODELE.md): data model and design choices
- [`docs/DECISIONS.md`](docs/DECISIONS.md): dated decision log, including answers to the brief's open questions
- [`docs/diagrams/`](docs/diagrams/): approved design diagrams (classes, booking lifecycle, use cases, sequences, Level 2 services)
- [`docs/design/`](docs/design/): visual identity, mockup and photos

Code, documentation and commits are in English. The application's interface is in French, the language of its Moroccan users.

## Use of AI

The project is built with the help of AI agents (Claude Code). Commits produced with AI carry a `Co-Authored-By` trailer. Usage traces are provided separately, in the archive the brief asks for.
