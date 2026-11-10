# Visual design

Design phase carried out on 9 and 10 November 2026, before the first commit.

## Interactive mockup

10 clickable screens: home, search, trip page, seat choice, seat already taken, public profile, publishing a trip (suggested price), my trips (passenger and driver), editing a booked trip, visual identity.

Link: <https://claude.ai/artifact/A32XRcGEbhedSzzrTqBmYe>

The mockup's text is in French, like the application's interface.

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
