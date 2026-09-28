# Rocket Clean

**Ménage** des lieux (logements, locaux…) : ménages planifiés par lieu, checklist, photos avant/après/dégât (Rocket Cloud), relevés de stock (Rocket Place), lien secret sans compte pour la personne qui fait le ménage, e-mails (Rocket Mailer), tableau de bord. Extrait de [rocket-place](https://github.com/fayouz/rocket-place) ; brique du Middleware Rocket, sur le socle [rocket-core](https://github.com/fayouz/rocket-core).

| Dossier | Stack |
|---|---|
| `backend/` | Symfony 8.1, API Platform, Doctrine (PostgreSQL), rocket-core (`rocket/core-bundle`) |
| `frontend/` | Nuxt 4, Nuxt UI 4, layer `@rocket/core` |
| `docs/` | Documentation (Nuxt UI + Nuxt Content), changelog sur `/changelog` |

Le socle commun (comptes, LDAP, SSO / Rocket Auth, applications externes, tableau de bord, mises à jour, modes autonome et suite) vient de rocket-core : ce dépôt ne contient que le métier.

## Lieux : Rocket Place ou autonome

Un ménage référence un lieu par son **identifiant** (`placeId`, UUID) et garde son nom en cache (`placeName`) : Rocket Clean ne possède aucun lieu.

- **Avec Rocket Place** (`ROCKET_PLACE_URL` + `ROCKET_PLACE_TOKEN` `rpl_…`, ou jeton Rocket Auth en mode suite) : lieux et stock sont ceux de Place (`/api/places`, `/api/stock-levels`), lus par `App\Place\PlaceClient`.
- **Autonome** (sans `ROCKET_PLACE_URL`) : lieux locaux (entité `Site`, nom seulement) créés dans Rocket Clean, sans stock.

Les photos vont directement dans Rocket Cloud, un dossier par lieu (sous « Rocket Clean »).

## Démarrage rapide

```bash
docker compose up -d --build
```

- Application : http://localhost:4000 (configuration initiale : création de l'administrateur)
- API + OpenAPI : http://localhost:9000/api/docs
- Démo complète : `docker compose -f compose.yaml -f compose.demo.yaml up -d --build` (voir [demo/README.md](demo/README.md))

### Développement sans Docker

```bash
# base locale
docker run -d --name rocket-clean-db -e POSTGRES_USER=app -e POSTGRES_PASSWORD=app -e POSTGRES_DB=app -p 127.0.0.1:55437:5432 postgres:16-alpine
# backend (PHP 8.4) ; .env.local : DATABASE_URL=...:55437/app, MESSENGER_TRANSPORT_DSN=sync://
cd backend && composer install --ignore-platform-req=ext-ldap
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:migrations:migrate
php -S 127.0.0.1:9000 -t public
php bin/phpunit

# frontend
cd frontend && npm install && NUXT_PUBLIC_API_BASE=http://localhost:9000 npm run dev -- --port 4000
```

## Configuration

| Variable | Rôle |
|---|---|
| `ROCKET_PLACE_URL` / `ROCKET_PLACE_TOKEN` | Rocket Place (lieux, stock), jeton d'application `rpl_…`. Vide : lieux locaux. |
| `ROCKET_CLOUD_URL` / `ROCKET_CLOUD_TOKEN` | Rocket Cloud (photos), jeton `rca_…`. Vide : démo. |
| `ROCKET_MAILER_URL`, `ROCKET_MAILER_TOKEN`, `ROCKET_MAILER_MAILBOX`, `ROCKET_MAILER_SENDER` | Rocket Mailer (e-mails d'attribution, retard, bilan). Vide : démo (`var/demo-mailer-<env>.json`). |
| `CLEANING_RECURRENCE_DAYS` | Jours d'avance des ménages générés par les récurrences (défaut 14). |
| `ROCKET_AUTH_URL`, `ROCKET_AUTH_INTERNAL_URL`, `ROCKET_AUTH_CLIENT_ID` (`rocket-clean`), `ROCKET_AUTH_CLIENT_SECRET`, `ROCKET_AUTH_ADMIN_GROUP`, `ROCKET_PUBLIC_URL`, `ROCKET_INTERNAL_URL` | Mode suite. En suite, Place et Cloud sont appelés avec un jeton Rocket Auth (audiences `rocket-place`, `rocket-cloud`), les jetons statiques restent le repli. |

## API (pour un PMS)

Mêmes chemins et contrats que le ménage de Rocket Place : passer de Place à Clean ne change que l'URL et le jeton (`rcl_…`).

- `POST /api/places/{placeId}/cleanings` — idempotent par `externalRef` (201 puis 200)
- `GET /api/places/{placeId}/cleanings`, `GET /api/cleanings?date=…&mine=1&place=…`
- `GET · PATCH · DELETE /api/cleanings/{id}`, `POST /api/cleanings/{id}/photos`, `GET /api/cleanings/{id}/photos/{fileId}`, `POST /api/cleanings/{id}/stock`
- `GET · POST · DELETE /api/cleanings/{id}/link`, public `/api/public/cleaning/{token}` (+ `/photos`, `/stock`)
- `GET · PUT /api/places/{placeId}/cleaning-checklist`, `GET /api/places/{placeId}/stock`
- Types (`?type=`), origine, coûts : `GET · PUT /api/places/{placeId}/cleaning-costs`, `GET /api/cleanings/export?type=rental`
- Séjours `PUT /api/places/{placeId}/occupancy` → drapeau `conflict` ; récurrences `/api/places/{placeId}/recurrences`, `/api/recurrences/{id}`
- `GET /api/cleaning-assignees`, `GET · PUT /api/cleaning-settings` (administrateur)

Détail : `docs/content/3.api/2.domain.md`.
