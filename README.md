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

The app is served at <http://localhost:8080>. On first start, the PHP container installs the dependencies, waits for MySQL and runs the migrations.

The full guide (demo accounts, troubleshooting) will live in `INSTALL.md`.

## Progress

| Level | Scope | Status |
|---|---|---|
| 1 — Model | Entities, migrations, demo data, back-office | In progress |
| 2 — Rules | Seats per leg, no overbooking, cancellations, reviews, tests | To do |
| 3 — Product | Accounts, search, booking, driver space, profiles | To do |
| 4 — External API | Nominatim and OSRM: suggested price and map | To do |
| 5 — Delivery | Docker, `INSTALL.md` | Docker skeleton in place |

## Documentation

- [`docs/MODELE.md`](docs/MODELE.md): data model and design choices
- [`docs/DECISIONS.md`](docs/DECISIONS.md): dated decision log, including answers to the brief's open questions
- [`docs/design/`](docs/design/): visual identity, mockup and photos

Code, documentation and commits are in English. The application's interface is in French, the language of its Moroccan users.

## Use of AI

The project is built with the help of AI agents (Claude Code). Commits produced with AI carry a `Co-Authored-By` trailer. Usage traces are provided separately, in the archive the brief asks for.
