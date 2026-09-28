# Changelog

Toutes les évolutions notables de Rocket Clean. Format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- Type de ménage `rental` / `personal` / `maintenance` (défaut `rental` pour un `externalRef` `booking:…`), filtre `?type=`, couleurs dans l'interface.
- Origine `origin` (`host`, `pms`, `place`, `clean`, `recurrence`) et `originApp` (application appelante) ; une application modifie ses propres ménages et ceux des personnes, pas ceux d'une autre application.
- Récurrences (`CleaningRecurrence` : hebdomadaire par jours, mensuelle par jour du mois ou n-ième jour de semaine, heure, durée, personne, checklist, coût, période) : génération quotidienne `CLEANING_RECURRENCE_DAYS` (14) jours à l'avance, idempotente (`recurrence:<id>:<date>`), API `/api/places/{placeId}/recurrences`, `/api/recurrences/{id}`, carte « Récurrences ».
- Checklist par lieu **et par type** (`?type=`, repli sur `rental`).
- Séjours `PUT /api/places/{placeId}/occupancy` (Rocket Host / PMS) et drapeau `conflict` des ménages personnels/d'entretien qui les chevauchent ; indicateur au tableau de bord.
- Coûts : `cost` (centimes) par ménage, coût par défaut par lieu et type (`/api/places/{placeId}/cleaning-costs`), export `GET /api/cleanings/export?type=…`.

### Corrigé
- Test du lien secret : le jeton falsifié modifiait parfois seulement des bits de remplissage base64 (test instable).

## [0.1.0] - 2026-09-28

### Ajouté
- Extraction du ménage de Rocket Place : `CleaningTask` (lieu par identifiant `placeId` + nom en cache `placeName`, fenêtre, statut, personne attribuée, checklist, notes, photos, relevés de stock, `externalRef` idempotente unique par lieu) et `CleaningChecklistItem` (modèle de checklist par lieu).
- Lieux : ceux de Rocket Place via `PlaceClient` (`ROCKET_PLACE_URL`/`ROCKET_PLACE_TOKEN`, mode suite par jeton Rocket Auth d'audience `rocket-place`), ou lieux locaux (`Site`) en mode autonome ; `GET/POST /api/places`, `GET/PATCH/DELETE /api/places/{placeId}`.
- Stock pendant un ménage : lu et modifié dans Rocket Place (`/api/stock-levels`), `GET /api/places/{placeId}/stock`.
- Photos directement dans Rocket Cloud (un dossier par lieu), `GET /api/cleanings/{id}/photos/{fileId}`.
- Même API que le ménage de Place (`/api/places/{placeId}/cleanings`, `/api/cleanings/…`, lien secret `/m/<jeton>` et `/api/public/cleaning/{token}`, `/api/cleaning-settings`, `/api/cleaning-assignees`), ouverte aux applications agissant pour elles-mêmes (`CleanAccessVoter`, `CleanScopeGuardListener`), jetons `rcl_…`.
- E-mails via Rocket Mailer (attribution, retard, bilan du jour), tableau de bord (ménages du jour, terminés, en retard), données de démo, interface (ménages du jour, lieux, fiche d'un lieu, page publique).
- Identité : `app_id` `clean`, ports front 4000 · api 9000 · docs 4001.
