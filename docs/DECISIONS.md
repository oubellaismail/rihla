# Journal des décisions

Chaque entrée est datée. Les décisions de conception prises avant le premier commit (phase de maquette, 9 et 10 novembre 2026) sont reportées ici telles quelles : le dépôt a été créé le 10 novembre, après cette phase.

## 2026-11-09 — Choix du sujet

- **Sujet 3, covoiturage régional.** La règle centrale (places par tronçon, réservations partielles, accès concurrents) est un vrai problème d'algorithme et de transactions, testable de façon isolée. C'est elle qui porte les niveaux 1 et 2.
- **Stack** : Symfony 7.4 LTS, MySQL 8.4, Twig avec Stimulus et Turbo. Pas de front séparé : le temps va au métier et aux tests.

## 2026-11-09 / 10 — Phase de conception (maquette)

Avant de coder, une maquette interactive de 10 écrans a été réalisée pour trancher les questions produit. Elle est décrite dans [`docs/design/`](design/).

- **Identité** : nom « Rihla », palette « Golden hour » (bleu crépuscule `#22305C`, orange coucher de soleil `#E8743B`, safran, sable, asphalte), polices Bricolage Grotesque et Figtree.
- **Interface sans « look IA »** : règles du skill Impeccable appliquées (pas de cartes imbriquées, pas d'emoji comme icônes, une seule échelle d'arrondis, photos réelles des routes).
- **Choix de la place précise dans la voiture** : maquetté, mais **reporté en bonus**. Le brief demande de compter les places par tronçon ; le choix de siège rend la règle et ses tests plus complexes. Il ne sera ajouté que si le niveau 2 est terminé et testé.

## Réponses aux « Questions à trancher »

### Une place libérée à une étape intermédiaire peut-elle être revendue pour la suite du trajet ?
**Oui.** Une réservation occupe des **tronçons**, pas une place pour tout le trajet. Si Youssef descend à Essaouira, la place est libre d'Essaouira à Casablanca. Les places libres d'une portion = places proposées − maximum des places occupées sur chacun des tronçons de cette portion.
*Rejeté* : une place pour le trajet entier, qui fait perdre de l'argent au conducteur et ne correspond pas au brief.

### Comment se calcule le prix d'une portion de trajet ?
Le prix d'une portion est la **somme des prix de ses tronçons**. Le conducteur fixe le prix de chaque tronçon ; Rihla lui **suggère** un prix à partir de la distance routière réelle (OSRM) : 0,35 MAD par kilomètre et par passager, arrondi à 5 MAD.
*Rejeté* : un prix proportionnel au trajet complet, qui ignore les routes de montagne plus longues que la distance à vol d'oiseau.

### Que se passe-t-il quand le conducteur modifie un horaire ou supprime une étape après des réservations ?
- **Décalage de moins de 30 minutes** : les réservations sont gardées, les passagers sont prévenus.
- **Décalage de 30 minutes ou plus** : chaque passager concerné peut annuler sans frais.
- **Étape supprimée** : les réservations qui montent ou descendent à cette étape sont annulées et remboursées en totalité.
- Le conducteur voit l'effet de sa modification sur chaque passager **avant** de l'enregistrer.

### Un passager peut-il réserver plusieurs places en une fois ?
**Oui, de 1 à 4 places** dans une même réservation. La disponibilité est vérifiée pour le nombre de places demandé sur chaque tronçon de la portion.

### Qui note qui, et quand ?
Le passager note le conducteur, et le conducteur note chaque passager. L'avis n'est possible **qu'après un trajet effectué**, pendant 14 jours, une fois par réservation et par auteur (contrainte d'unicité en base).

## Règles d'annulation

- **Passager** : remboursement total jusqu'à 24 h avant le départ de sa portion, 50 % ensuite, rien après le départ.
- **Conducteur** : toutes les réservations sont remboursées en totalité et les passagers prévenus ; l'annulation est comptée sur son profil.

## Accès concurrents (surréservation)

Pour réserver, le service ouvre une transaction, **verrouille la ligne du trajet** (`SELECT … FOR UPDATE`, `LockMode::PESSIMISTIC_WRITE` dans Doctrine), recalcule les places libres par tronçon, puis enregistre ou refuse. Deux réservations simultanées sur la dernière place sont donc traitées l'une après l'autre : la seconde voit la place prise et reçoit un message clair.
*Rejeté* : le verrou optimiste seul (colonne `version`), qui obligerait à réessayer côté client pour un cas fréquent sur les petits trajets.
