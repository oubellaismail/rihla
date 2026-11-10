# Design diagrams

Approved on 10 November 2026, before the entities were implemented.

| File | Content |
|---|---|
| [`rihla-design.drawio`](rihla-design.drawio) | Three pages: **class diagram** (Level 1 entities), **booking lifecycle** (`BookingStatus`), **use cases**. Open it with [draw.io](https://app.diagrams.net/) or the draw.io VS Code extension. |
| [`booking-sequence.mmd`](booking-sequence.mmd) | **Sequence diagram** of a booking with the database lock (Mermaid; GitHub renders it, and draw.io imports it via *Arrange › Insert › Mermaid*). |
| [`level2-services.drawio`](level2-services.drawio) | **Level 2 services**: pure rule classes, services, clock and domain exceptions (approved 10 November 2026). |
| [`booking-concurrency-test.mmd`](booking-concurrency-test.mmd) | **Concurrency test** sequence: two processes, one last seat. |
