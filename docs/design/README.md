# Conception visuelle

Phase de conception réalisée les 9 et 10 novembre 2026, avant le premier commit.

## Maquette interactive

10 écrans cliquables : accueil, recherche, page trajet, choix de la place, place déjà réservée, profil public, publication d'un trajet (prix suggéré), mes trajets (passager et conducteur), modification d'un trajet réservé, identité visuelle.

Lien : <https://claude.ai/artifact/A32XRcGEbhedSzzrTqBmYe>

## Identité

| Rôle | Couleur | Usage |
|---|---|---|
| Crépuscule | `#22305C` | Liens, sélection, carte, confiance |
| Coucher de soleil | `#E8743B` | Action principale (« Réserver »), arrivée |
| Safran | `#F2B544` | Étoiles, place libérée en route |
| Sable | `#FBF5EC` / `#F5E9D7` | Fonds |
| Asphalte | `#2B2D33` | Texte, prix |

- Polices : **Bricolage Grotesque** (titres) et **Figtree** (texte), Google Fonts.
- Logo : [`logo/`](logo/) — une route à trois arrêts, du bleu de départ à l'orange d'arrivée.
- Photos : [`photos/`](photos/), licence Unsplash, crédits dans [`photos/CREDITS.md`](photos/CREDITS.md).

## Règles d'interface

- Pas de cartes dans des cartes, une seule échelle d'arrondis (12 / 16 / 20 px).
- Icônes dessinées en SVG, jamais d'emoji ni de symboles texte.
- Prix en texte foncé ; l'orange est réservé à l'action principale.
- Fond uni, rythme par bandes de couleur pleine, sans dégradés décoratifs.
