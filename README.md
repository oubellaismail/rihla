# Rihla — covoiturage régional au Maroc

Projet réalisé pour le **PFE Challenge EMC** (Education Media Company, Agadir).
Sujet choisi : **Sujet 3 — Plateforme de covoiturage régional**.

Rihla (رحلة, « voyage ») met en relation des conducteurs qui font un trajet entre villes marocaines, avec des arrêts, et des passagers qui peuvent réserver **tout ou partie** du parcours. Une place libérée à une étape intermédiaire est remise en vente pour la suite du trajet.

## Stack

- Symfony 7.4 (LTS), PHP 8.3
- MySQL 8.4, Doctrine ORM et migrations
- Twig, Stimulus et Turbo (Symfony UX), AssetMapper
- Docker Compose : PHP-FPM, Nginx, MySQL

## Lancer le projet

Prérequis : Docker et Docker Compose.

```bash
docker compose up -d --build
```

L'application est servie sur <http://localhost:8080>. Au premier démarrage, le conteneur PHP installe les dépendances, attend MySQL et applique les migrations.

Le guide complet (comptes de démonstration, dépannage) sera dans `INSTALL.md`.

## Avancement

| Niveau | Contenu | État |
|---|---|---|
| 1 — Modéliser | Entités, migrations, données de démonstration, back-office | En cours |
| 2 — Règles | Places par tronçon, surréservation, annulations, avis, tests | À faire |
| 3 — Produit | Comptes, recherche, réservation, espace conducteur, profils | À faire |
| 4 — API externe | Nominatim et OSRM : prix suggéré et carte | À faire |
| 5 — Livrer | Docker, `INSTALL.md` | Squelette Docker en place |

## Documentation

- [`docs/MODELE.md`](docs/MODELE.md) : modèle de données et choix
- [`docs/DECISIONS.md`](docs/DECISIONS.md) : journal daté des décisions et réponses aux « Questions à trancher »
- [`docs/design/`](docs/design/) : identité visuelle, maquette et photos

## Usage de l'IA

Le projet est développé avec l'aide d'agents IA (Claude Code). Les commits produits avec l'IA portent le trailer `Co-Authored-By`. Les traces d'usage sont fournies à part, dans l'archive demandée par le brief.
