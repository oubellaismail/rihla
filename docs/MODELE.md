# Data model

> First version (10 November 2026), written before the entities were implemented. It will be updated during Level 1. The file keeps the name `MODELE.md` required by the brief.

## Core idea: a trip is split into legs

```
Agadir ──leg 0── Essaouira ──leg 1── Marrakech ──leg 2── Casablanca
```

A trip is an ordered list of **stops** (`TripStop`, with a `position`). A booking goes from a boarding stop to a drop-off stop, so it occupies every leg between the two. Free seats on a portion are counted leg by leg: the busiest leg decides.

## Diagram

```mermaid
erDiagram
    USER ||--o{ VEHICLE : owns
    USER ||--o{ TRIP : drives
    USER ||--o{ BOOKING : books
    VEHICLE ||--o{ TRIP : "used for"
    TRIP ||--|{ TRIP_STOP : "ordered stops"
    CITY ||--o{ TRIP_STOP : "located in"
    TRIP ||--o{ BOOKING : contains
    TRIP_STOP ||--o{ BOOKING : "boards at"
    TRIP_STOP ||--o{ BOOKING : "gets off at"
    BOOKING ||--o| PAYMENT : "simulated payment"
    BOOKING ||--o{ REVIEW : "leads to"
    USER ||--o{ REVIEW : "writes / receives"

    USER {
        int id
        string email
        string password
        json roles
        string firstName
        string lastName
        datetime createdAt
    }
    VEHICLE {
        int id
        string model
        string color
        int passengerSeats
    }
    CITY {
        int id
        string name
        float latitude
        float longitude
    }
    TRIP {
        int id
        int seatsOffered
        string status
        datetime createdAt
    }
    TRIP_STOP {
        int id
        int position
        datetime departureAt
        string meetingPoint
        int legPriceToNext
        int legDistanceKmToNext
    }
    BOOKING {
        int id
        int seats
        int totalPrice
        string status
        datetime createdAt
    }
    PAYMENT {
        int id
        int amount
        string status
        datetime paidAt
    }
    REVIEW {
        int id
        int rating
        string comment
        datetime createdAt
    }
```

## Choices

- **Price and distance stored on the stop, for the leg that follows it** (`legPriceToNext`, `legDistanceKmToNext`). A booking's price is frozen when it is made (`Booking.totalPrice`): a later price change does not affect what has already been paid.
- **Amounts as integers (MAD)**: no floats for money.
- **`City` with latitude and longitude**: acts as a cache for Nominatim geocoding, so the API is not called again on every search.
- **Statuses** (`Trip.status`, `Booking.status`): PHP enums, with the lifecycle managed by Symfony's Workflow component.
- **One user can be both driver and passenger**: a single `User`; roles follow from what the person does (publishing a trip, booking a seat).
- **Concurrency lock**: placed on the `TRIP` row when booking (see `DECISIONS.md`).
