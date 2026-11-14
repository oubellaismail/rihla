# UX review of the flows, 14 Nov 2026

The third review, after [UX-REVIEW.md](UX-REVIEW.md) (phone) and [UX-REVIEW-DESKTOP.md](UX-REVIEW-DESKTOP.md) (desktop), whose fixes are all in. This one looks at UX only: tasks, flows, feedback, errors, wording, trust. Visual design is out of scope.

Three Opus reviewers, each walking the code step by step (the local app was down, so nothing was clicked):

- **A, passenger journeys**: 18 steps, from the home page to the review after the trip.
- **B, driver journeys**: 12 steps, from adding a vehicle to the finished trip.
- **C, feedback, errors and accessibility**: messages after each action, error states, keyboard and screen reader, wording, trust.

Round 1: up to 10 findings each (30 in all). Round 2: each reviewer checked the other two in the code. **No claim was found wrong.** The budget given was 4–5 hours of fixes on Saturday, with the deadline on Sunday night.

## What the review found

- **Two real bugs in the booking path:**
  - Changing « Je monte à / Je descends à » without pressing « Changer de portion » books the **old portion at the old price** (A-1).
  - After a seat conflict, the booking is made **without the bags**, and nothing tells the passenger (A-3).
- **The end of the journey only works on demo data.** Nothing marks a trip as finished: the `app:trips:complete` command exists, but nothing runs it. So a trip the jury books or publishes never reaches « Laisser un avis » (A-5, found by both A and B as B-10).
- **The driver's publish form can fail on a field labelled « facultatif ».** The meeting point is required by the server, but the field reads « Précision (facultatif) » (B-1).
- **Small but visible gaps:**
  - cancelling a booking twice ends on a 403 page (C-1);
  - the search fields have no keyboard focus (C-3);
  - the seat page, where you click « Réserver », never says the payment is simulated (C-4).

## Agreed fixes, in order

| # | Fix | Findings | Who agrees | Effort |
|---|---|---|---|---|
| 1 | **The portion you see is the portion you book.** On the trip page, changing « Je monte à » or « Je descends à » reloads the portion at once (price, bar and « Choisir ma place » follow). A drop-off before the boarding stop says so, instead of silently showing the whole trip. | A-1 | A B C | 40 min |
| 2 | **Trips finish on their own, and cancelling twice is handled.** The existing completion service runs when « Mes trajets » and the trip pages load (cheap, safe to repeat; the command stays). A booking that can no longer be cancelled redirects with a message instead of a 403. « Annuler » stays visible after departure with its 0 % refund, as decided in DECISIONS.md (B and C; A wanted it hidden). | A-5 = B-10, C-1 | A B C | 1 h |
| 3 | **Bags survive a booking conflict.** The « place déjà réservée » and « coffre plein » pages keep the bags that were chosen, and speak in bags (« une grande valise ne passe plus »), not litres. | A-3, A-4 | A B C | 45 min |
| 4 | **The meeting point is never a hidden required field.** When the map picker can't name the spot, the server uses « Point sur la carte, {ville} »; the field is labelled for what it is. | B-1 | A B C | 30 min |
| 5 | **The driver sees who gets off at each stop**, not only who gets on. | B-8 | A B C | 30–40 min |
| 6 | **Small batch:** visible keyboard focus in the search bar (WCAG 2.4.7), error messages announced as alerts, a message after logging out. | C-3, C-5 (part), C-9 | A B C | 20 min |
| 7 | **Trust on the seat page:** « Paiement simulé » and the full cancellation policy next to « Réserver ». | C-4 | A C (B: next in line) | 20 min |
| 8 | **A trip stays bookable from a later stop** after it left its first city: the trip and seat pages test the boarding stop, like the search and the booking service already do. Rare during the demo, so it ranks here. | A-2 | A C (B: lower) | 20–30 min |
| 9 | **Publishing says when the route is being calculated**, and the price placeholder doesn't promise a suggestion that never comes when the routing API fails. | B-3 = C-10 | B C (A: merged) | 30 min |
| 10 | **Publish errors name the stop or leg** (« Arrêt 2 (Marrakech) : … »), still shown as today. Per-field errors are out of budget. | B-2 (part 1) | B, A and C: if time | 40 min |

Estimated total: about 5 h 30. **Cut line after #8, about 4 h.**

## Later, if time is left

- A way out of an empty dated search: « Voir les prochains départs » (A-6).
- Keep the passenger count from search to trip (A-7), and the user's intent after adding a first vehicle (B-7).
- An « Avis à laisser » badge in « Mes trajets » (A-9).
- Driver-side details: city change clears the old meeting point (B-4); vehicle and seat defaults (B-5); « Départ / Arrivée prévue » instead of « Date et heure » (B-6); the confirm before cancelling a trip says it shows on the profile (B-9).
- Sign-up phone help text: who sees the number (C-6).
- One word for one idea: « portion », « bout de route », « tronçon », « bout de trajet » (C-7, A-8), and « trajet entier » instead of the ambiguous « trajet complet ».
- The luggage « + » button keeps keyboard focus when it becomes disabled, and the no-result pages get an h1 (C-8).

## Not before Sunday

- **Custom error pages (C-2).** All three agree to drop it: the jury runs the dev environment, where Symfony shows its own debug page whatever templates exist.
- **Per-field publish errors (B-2 parts 2 and 3):** 2–3 h, and they would trip on the arrival row, whose price field is disabled.
- **A-10** (a link to the free portion when the chosen one is full): withdrawn by A, already covered by the per-leg labels and the first bookable portion.

## Keep

The per-leg labels and the « au lieu de » price, the login wall that returns to the seat page, the live seat and bag summary, the cancel page with the exact refund, the meeting-point picker with its 15 km circle, « Utiliser » on suggested prices and times, the trunk checklist, the specific French message after every action, the real checkboxes of the seat plan, and the fallbacks when the map APIs fail.
