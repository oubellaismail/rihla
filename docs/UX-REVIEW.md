# UX review, 12 Nov 2026

Three reviewers looked at the same 15 screenshots (11 phone at 390 px, 4 desktop) and the code:

- **A, a passenger on a phone**: mid-range Android, one thumb, first visit.
- **B, a visual designer**: hierarchy, consistency, the "AI-generated" look, within the Golden hour system.
- **C, product and jury**: what the jury sees in 10 minutes, and what is worth doing before Sunday 15 Nov.

Round 1: each wrote up to 12 findings. Round 2: each read the other two, checked their claims against the code or with curl, and answered agree, agree with a change, or disagree, with a reason. Only findings that survived round 2 are kept below. The full round files are in the session scratchpad.

## The owner's two complaints

**1. "Pages on the phone have UI/UX problems": all three agree, and agree on the cause.**
Nothing is broken at 390 px: no sideways scroll, readable text, big buttons. The problem is that desktop columns collapse into one long column without being reordered for the phone. So on the two pages that matter, the main action ends up at the bottom:

- trip page: « Réserver » is about 2,000 px down;
- seat page: the total and « Réserver » are about two screens below the car.

Search results start at the bottom edge of the first screen. The Symfony debug toolbar also covers the bottom of every page (the jury runs the dev environment).

**2. "Find trips must show today's trips, not an empty page": all three agree with the problem, not with "today" literally.**
`/trips` shows an empty form, and three links lead to it (« Voir toutes les routes », « Rechercher », the empty « Mes trajets »). But the demo trips are always created 2 to 4 days after the data is loaded (`AppFixtures.php:96-120`), so a list of strictly today's trips would be empty for the jury too, whatever day they open it. The agreed answer is « Prochains départs »: upcoming trips grouped by day, starting with « Aujourd'hui » when there is one.

## Agreed fixes, in order

| # | Fix | Findings merged | Who agrees | Effort |
|---|---|---|---|---|
| 1 | The home example search returns results. The placeholders become « Essaouira » / « Marrakech » (the example in the hero text), the date is empty by default, and an « Exemple : Essaouira → Marrakech » link is added. Today the shown example, Agadir → Marrakech, returns « Aucun trajet » even on 14 Nov. | C-2, A-10 | A B C | S |
| 2 | « Prochains départs » on `/trips` when nothing is searched: upcoming trips grouped by day (« Aujourd'hui », « Demain », then the date). The date becomes optional, so a search without a date lists the next departures on that route. It reuses `TripRepository::findUpcoming`. | C-1, A-1, B-1 | A B C | M (~4 h) |
| 3 | The debug toolbar is off by default (`PROFILER_TOOLBAR=0`), and `/_profiler` stays available. This must come first: the toolbar would cover the sticky bars of fixes 4 and 5. | C-9 | A B C | S |
| 4 | Trip page on the phone: the price and « Réserver » become visible without scrolling, as a bottom bar « 65 MAD · Essaouira → Marrakech [Choisir ma place] ». The h1 bug is fixed (`.route` at 21 px overrides the title, so it is smaller than its own h2s, `app.css:145`). The title shows the passenger's portion. « au lieu de 210 MAD pour le trajet complet » goes under the price. | A-3, B-2, C-4, B-3, A-6, C-5 | A B C | S–M |
| 5 | Honest availability. Home cards say « Complet » only when every leg is full: today the flagship trip shows « 210 MAD · Complet » although Essaouira → Marrakech has a free seat. Search results stop hiding trips that are full on the searched portion and show them as « Complet sur votre portion » (`TripSearch.php:88`), instead of the false « Aucun conducteur ne propose… ». | C-6, C-3 | A B C | S–M |
| 6 | Seat page on the phone: a sticky bottom bar « A1 · 65 MAD [Réserver] », and « Pas de préférence, choisir pour moi » moved up under the title. The aside is sticky only from 641 px today (`seats.css:85`). | A-4, B-2, C-10 | A B C (C: the first to cut if time runs short) | M |
| 7 | Signing up keeps the trip. Today registration always lands on the home page (`RegistrationController.php:40`), so a first-time passenger (the jury's likely path) loses the trip they were booking. Fix: redirect to the saved target path. | A-5 | A B C | S |
| 8 | Compact search results on the phone. After a search, the form folds into a one-line summary « Essaouira → Marrakech · sam. 14 nov. · 1 passager [Modifier] » (a `<details>`, no JS needed). Each card puts the price top-right and drops from about 300 px to about 140 px. | A-2, A-8, B-4 | A B, C agrees but left it out of its top 8 | M |

Estimated total: about 1.5 days (C's estimate, which A and B did not dispute). That leaves about 1.5 days for the concept note, the AI traces and the email.

## Quick wins, if time is left (all S, all agreed)

- Header taps: « Rechercher », « Mes trajets » and « Publier » at 44 px on the phone (A-9, B-5). C notes that the 44 px rule is the owner's rule but is not written in `docs/design/README.md`.
- The passenger count from the search is kept on the trip page and on « Retour aux résultats » (A-7, B-12).
- Changing the portion on the trip page updates the price without the extra « Changer de portion » click; the button stays as a fallback (C-11, B-12).
- Demo accounts on the login page, in dev only: Karim, Salma, Imane, with the password (C-7).
- Booking page: space between the timeline and the map (A-12).
- French typography: non-breaking space before « : », and tab labels on one line (B-11).
- One scoped CSS rule for the `.section-title` leak between pages (B-7, cut down by C).
- Publish form: « Point de rendez-vous à choisir » in a neutral colour instead of the error colour (B-9, cut down to the colour by C).

## Process step

Reload the demo data just before taking any screenshot for the concept note or the README (C-8). The local database has test leftovers, for example the home banner « Karim monte à Marrakech · 330 MAD ».

## Not before Sunday (agreed or withdrawn)

- **B-6, replacing the home's lower sections** (numbered steps, testimonial cards) with the app's own timeline: B withdrew it, as A and C argued it is below-the-fold polish worth less than the deliverables.
- **A-11, the cryptic « Aga · Ess » note on the home photo**: A withdrew it. B and C disagree with changing it: it is real data, and full city names do not fit at 390 px.
- **B-8 (one back-link style) and B-10 (shorter seat legend)**: C disagrees for now. B-10 matters less once the seat-page bar exists.
- No visual redesign, no new header or tab bar, no date carousel or filters, no "today" demo trip (a fixed hour can already be past when the jury loads the data).
