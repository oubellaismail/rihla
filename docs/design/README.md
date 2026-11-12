# Visual design

Design phase carried out on 9 and 10 November 2026, before the first commit.

## Interactive mockup

10 clickable screens: home, search, trip page, seat choice, seat already taken, public profile, publishing a trip (suggested price), my trips (passenger and driver), editing a booked trip, visual identity.

Link: <https://claude.ai/artifact/A32XRcGEbhedSzzrTqBmYe>

The mockup's text is in French, like the application's interface.

Added on 11 November 2026, after clicking through Level 4: **finished trip** (driver and passenger) and **cancelled trip**. A trip that is over is a summary, not something to organise: no seat counters, no phone numbers, and the driver can now review each passenger.

## Identity

| Role | Colour | Use |
|---|---|---|
| Dusk | `#22305C` | Links, selection, map, trust |
| Sunset | `#E8743B` | Main action ("Réserver"), arrival |
| Saffron | `#F2B544` | Stars, seat freed along the way |
| Sand | `#FBF5EC` / `#F5E9D7` | Backgrounds |
| Asphalt | `#2B2D33` | Text, prices |

- Fonts: **Bricolage Grotesque** (headings) and **Figtree** (body), from Google Fonts.
- Logo: [`logo/`](logo/), a road with three stops, from the blue departure to the orange arrival.
- Photos: [`photos/`](photos/), Unsplash licence, credits in [`photos/CREDITS.md`](photos/CREDITS.md).

## Interface rules

- No cards inside cards, a single radius scale (12 / 16 / 20 px).
- Icons drawn in SVG, never emoji or text symbols.
- Prices in dark text; orange is reserved for the main action.
- Flat backgrounds, rhythm from full-width colour bands, no decorative gradients.

Added on 11 November 2026, after a UX critique of « Publier un trajet » (15/40: pins landed silently in the wrong city, and on a phone the map was 1,000 px away from the field): **meeting-point picker**, desktop and phone. Each stop gets « Choisir sur la carte », which opens a large map centred on that city with a fixed centre pin; a point more than 15 km from the city cannot be validated.

Added on 11 November 2026: **seat choice, v2** (« Choisir ses places », « Place déjà réservée »). Built on the first seat boards, with one seat to tap per place booked, seats the driver does not offer shown « Non proposée » (hatched), and no names on taken seats. Code changes: [`../diagrams/seat-choice.drawio`](../diagrams/seat-choice.drawio).

Added on 12 November 2026: **luggage** (« Vos bagages, voyageur », « Le coffre, conducteur », « Le coffre dans les pages »). The passenger picks bag sizes with − / + buttons next to a trunk gauge for their portion; the driver gives the trunk volume in litres and sees what to load and unload at each stop. Code changes: [`../diagrams/luggage.drawio`](../diagrams/luggage.drawio).
