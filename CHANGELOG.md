# Changelog

Toutes les évolutions notables de Rocket Clean. Format [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté
- **Assistant vocal du ménage** (fiche d’un ménage et lien sans compte) : guidage de la checklist à voix haute (synthèse vocale du navigateur, français), commandes (« suivant », « c’est fait », « précédent », « passer », « répète », « pause », « reprendre », « où j’en suis », « combien il reste », « terminer », « aide »), points reconnus en langage libre (« j’ai fini la salle de bain », synonymes par point), stock à la voix (« il manque du papier toilette », « j’ai utilisé deux rouleaux »), problèmes (« signaler un problème : … » + photo du dégât), confirmation orale avant d’enregistrer, tournée photo par pièce en fin de ménage, puis compte rendu. Reconnaissance vocale du navigateur (Web Speech API), appui pour parler ou mains libres, boutons et saisie en repli, aucun son envoyé au serveur. Analyse dans `frontend/app/utils/assistant/parser.ts` (tests `npm run test`, `node --test`).
- Checklist : `synonyms`, `photo` et `area` par point (`PUT /api/places/{placeId}/cleaning-checklist` accepte des objets, `?details=1` pour les lire ; éditeur `Libellé | synonymes | photo: Pièce`), recopiés dans chaque ménage.
- Ménage : `incidents` (`"incident": "…"` dans `PATCH`, aussi par le lien secret), `area` des photos, `quantity` des relevés de stock, **compte rendu** écrit à la fin (`report`, `hasReport`, `GET /api/cleanings/{id}/report` et `/api/public/cleaning/{token}/report`, brouillon avant la fin), page `/cleanings/<id>/report`, e-mail aux administrateurs (notification `report`, désactivée par défaut).
- **Application installable (PWA)** sur Android et iPhone : manifeste (`/manifest.webmanifest`, et `/m/<jeton>/manifest.webmanifest` pour rouvrir un ménage sans compte), icônes 192/512 + maskable + apple-touch-icon (`frontend/scripts/pwa-icons.mjs`, sans dépendance), balises iOS, raccourci « Ménages du jour », bouton « Installer l’application » (instructions Safari sur iPhone). Service worker écrit à la main (`/sw.js`) : assets `/_nuxt/` en cache d’abord, pages réseau d’abord avec page hors ligne, jamais `/api/` ; production seulement (`NUXT_PUBLIC_PWA=false` pour couper) ; « Nouvelle version disponible » à chaque déploiement. Page publique hors ligne : dernières données gardées, badge « Hors ligne », modifications mises en file puis rejouées.
- **Rocket Stock** : les relevés de stock d'un ménage vont à Rocket Stock quand `ROCKET_STOCK_URL`/`ROCKET_STOCK_TOKEN` sont configurés (`App\Stock\StockClient`, audience suite `rocket-stock`) : consommation `POST /api/movements` (`type` consume, `usage` rental pour un ménage de location sinon personal, `externalRef` `cleaning:<id>:<levelId>`, `origin` clean, consommation seulement si une quantité est saisie ou si l’état passe à bas/vide — 1 par défaut dans ce cas) puis état du niveau (`PATCH /api/stock-levels/{id}`). Repli : stock de Rocket Place, sinon aucun (`App\Stock\CleaningStock`).
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
