# Desktop UX review, 12 Nov 2026

Desktop is what the jury will see, on a laptop at about 1440 px. Three reviewers looked at the same 21 desktop screenshots (1440 × 900) and the code, after the phone fixes of [UX-REVIEW.md](UX-REVIEW.md) were in:

- **A, the jury**: 10–15 minutes on a laptop, grading against the subject and the grid.
- **B, a visual designer**: width, hierarchy, consistency, the "templated" look, within the Golden hour system.
- **C, flows and scope**: every flow end to end (visitor, passenger, driver), and what is worth doing before Sunday 15 Nov.

Round 1: up to 12 findings each (35 in all). Round 2: each reviewer read the other two, checked the claims against the code or with curl, and answered agree, agree with a change, or disagree. No factual claim was found wrong. C downgraded its own C-2 after checking the code: bags typed before « Pas de préférence » are sent along. The real issue is only that the bags sit below the fold.

## Where it stands

Nothing is broken at 1440 px. The palette is disciplined, the copy is natural French, and the strongest pieces are the trip timeline, the drawn seat plan, the map coloured by leg fullness and the trunk checklist. All three reviewers say to keep them.

The jury reviewer's "grade feeling" for Level 3 (product) is about **7/10 now, 9/10 with the top fixes**. Two things cost the points:

- **The core idea is not visible where it should be.** Seats and prices are counted leg by leg, but the trip page prints « Complet » on the stop, so « Agadir · Complet » reads as « Agadir is full ». « 65 MAD » is shown with no breakdown.
- **The way in for the jury is rough.** The home flagship card lands on « Complet ». The login page has no demo accounts. The README's "Try it" steps no longer match the app.

## Agreed fixes, in order

| # | Fix | Findings merged | Who agrees | Effort |
|---|---|---|---|---|
| 1 | **The home search fits on one row.** At 1440 px « C'est parti » wraps onto its own row, the first thing the jury sees (`app.css:136`, `auto-fit minmax(140px)` in a ~531 px column). Use explicit columns for the hero bar. | B-1, A-3 | A B C | S |
| 2 | **The flagship card lands on a portion you can book.** « Agadir → Casablanca · dès 60 MAD » opens `/trips/23` on the whole route, which says « Complet sur cette portion ». And 60 MAD is the price of the full leg; the only bookable portion costs 65 MAD. When no portion is given and the whole trip is full, preselect the first portion with a free seat, and compute « dès » over free legs only. The upcoming list has the same problem. | C-1 | A B C | S–M |
| 3 | **Seats and price on each leg of the timeline**, not on the stop. A small pill on the line between two stops: « 1 place libre · 65 MAD », green text when free, the existing dark orange when full. The passenger's legs keep the orange line and their price in ink, so the 65 MAD in the panel reads as their sum. The same on the driver page. The per-leg price already exists (`TripStop::getLegPriceToNext`). | A-1, A-2 | A B C | M (~2 h) |
| 4 | **The jury's way in.** (a) In dev only, a « Comptes de démo » card on the login page: Karim conducteur, Salma passagère, Imane nouvelle passagère, and the shared password. (b) The login message follows the target: « …pour réserver votre place » only for a seat page, « …pour publier un trajet » for `/trips/new`, nothing otherwise (today it shows after any redirect). (c) The sign-up page says « Votre réservation reprendra juste après » when a booking is waiting. | A-4, C-4 | A B C | S |
| 5 | **Confirm before cancelling a whole trip.** It cancels and refunds every passenger in one click (`driver/show.html.twig:124`), while deleting a vehicle already asks. At least a `confirm()` naming the number of passengers. | C-3 | A B C | S |
| 6 | **Driver side tells the per-portion story.** On the driver trip page, « Arrêts et passagers » (graded) goes first in the left column and the map under it; one muted line under the title: « 3 passagers · 1 place libre sur Essaouira → Marrakech ». In the driver list, « 3 / 3 places réservées » (the busiest leg, `DriverTripController.php:49`) becomes « Complet sauf Essaouira → Marrakech (1 place) ». | A-6, C-8, C-7, A-5 (one line, not a stats strip) | A B C | S–M |
| 7 | **Publishing shows the Level 4 work.** The empty form never says that Rihla suggests a price from the real road distance. Add an aside line « Choisissez vos villes : Rihla calcule la distance par la route et vous propose un prix par tronçon », and a placeholder « Prix conseillé après le choix des villes ». « Point de rendez-vous à choisir » leaves the error colour (`picker.css:8`). | A-7 | A B C | S |
| 8 | **« Pas de préférence, choisir pour moi » picks the seat instead of booking.** It books at once, while the bags are below the fold at 1440 × 900. With JavaScript, it ticks the best free seats and scrolls to the bags; without it, it still submits. | C-2 | A B, C agrees with the fix but rates it minor | S |
| 9 | **Consistency batch (all S):** (a) results heading glued to the search bar, a desktop regression of phone fix 8 (`trip.css:14` misses `.search-summary + .search-results`); (b) one drawn route arrow everywhere: the booking and driver H1s use a text « → », and the home cards leave the arrow alone at the end of a line; (c) home « testimonials » only from passengers about drivers (today two are drivers rating passengers), and four different photos on the four route cards; (d) a wider date column in the « Mes trajets » lists so dates stop wrapping, a gap between the timeline and the map on the booking page, and the footer kept at the bottom on short pages. | B-2, B-6, B-10b/c, A-10, A-11, B-4 (date column only), B-8 | A B C | S each, ~2 h |
| 10 | **README "Try it" matches the app**: five numbered steps (home example link → Karim's trip → « Choisir ma place » as Salma or Imane with a suitcase → cancel from Mes trajets → Karim's driver page with passengers per stop and the trunk checklist), and one test count everywhere (today 149, 343 and 251). | C-5 | A B C (B: do it anyway, outside the ranking) | S |

Estimated total: about 8.5 h (C's estimate). If time runs short, cut after #8, about 7 h.

## After the top 10, if time is left

- A type scale for the booking path: page titles go 64 → 52 → 44 → 64 px across trip, seats and booking (B-3, about 30 min of CSS).
- The wording « trajet complet » means both "whole trip" and "sold out": use « trajet entier » for the route (A-8, C-10).
- Empty states with a next step (unknown city, no results) (C-6), reviews to leave flagged in « Mes trajets » (C-9), the passenger count kept from search to trip (C-11).
- Result cards: a one-line route « 09:45 Essaouira ─── 12:00 Marrakech » in the empty middle (B-5).

## Not before Sunday (agreed or withdrawn)

- A site-wide width system (B-4): B withdrew it after C's scope warning, and kept only the date column.
- The dusk band as a per-leg diagram (A-9, B-9), and restyling « Comment ça marche » (B-10a): the first to cut, as polish below the fold.
- One back-link style (B-7) and one decision-panel component (B-11): withdrawn by B.
- The trip page right column (C-12): withdrawn by C.
- A stats strip on the driver space (the A-5 figures part): B warns that big-number strips are the "AI-looking" pattern the owner wants to avoid. One line under the title is enough (fix 6).

## Process step

Reload the demo data before any screenshot for the concept note: the home banner still shows a test leftover (« Karim monte à Marrakech · 330 MAD »).
