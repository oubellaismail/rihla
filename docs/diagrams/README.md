# Design diagrams

Approved on 10 November 2026, before the entities were implemented.

| File | Content |
|---|---|
| [`rihla-design.drawio`](rihla-design.drawio) | Three pages: **class diagram** (Level 1 entities), **booking lifecycle** (`BookingStatus`), **use cases**. Open it with [draw.io](https://app.diagrams.net/) or the draw.io VS Code extension. |
| [`booking-sequence.mmd`](booking-sequence.mmd) | **Sequence diagram** of a booking with the database lock (Mermaid; GitHub renders it, and draw.io imports it via *Arrange › Insert › Mermaid*). |
| [`level2-services.drawio`](level2-services.drawio) | **Level 2 services**: pure rule classes, services, clock and domain exceptions (approved 10 November 2026). |
| [`booking-concurrency-test.mmd`](booking-concurrency-test.mmd) | **Concurrency test** sequence: two processes, one last seat. |
| [`level3-product.drawio`](level3-product.drawio) | **Level 3**: site map (French pages, English routes, access zones) and components (controllers, forms, new test-first services, voters). |
| [`level4-map.drawio`](level4-map.drawio) | **Level 4**: components (Leaflet, our `/api` proxy, Nominatim, OSRM, cache), publish sequence (meeting point, suggested price and time), who sees what on the map, data model changes, and what happens when an API fails. |
| [`finished-trips.drawio`](finished-trips.drawio) | **Finished and cancelled trips**: what changes in the code (screens are on the mockup canvas). |
| [`meeting-picker.drawio`](meeting-picker.drawio) | **Meeting-point picker**: 15 km rule, city-aware API, picker on the publish page (screens are on the mockup canvas). |
| [`seat-choice.drawio`](seat-choice.drawio) | **Seat choice**: car plans and comfort order, seat states on a portion, booking with chosen seats under the trip lock, pages (screens are on the mockup canvas, boards « Choisir ses places » and « Place déjà réservée », v2). |
| [`luggage.drawio`](luggage.drawio) | **Luggage**: trunk volume in litres (80 % usable), bag sizes, litres per leg, the check under the trip lock after the seats, pages (screens are on the mockup canvas, boards « Vos bagages, voyageur », « Le coffre, conducteur », « Le coffre dans les pages »). |
