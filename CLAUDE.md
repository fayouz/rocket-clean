# Rocket Clean

**Ménage** des lieux, extrait de Rocket Place, sur la stack des briques Rocket. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n'est pas ici.

## Repères
- `app_id` `clean`, jetons d'application `rcl_…`, ports front 4000 · api 9000 · docs 4001. Base de dev : conteneur `rocket-clean-db` (postgres:16-alpine, 127.0.0.1:55437, app/app), `.env.local` / `.env.test.local` non suivis.
- Domaine : `CleaningTask` (lieu par **identifiant** `placeId` + `placeName` en cache, jamais de clé étrangère ; fenêtre `DATETIMETZ`, statut todo/in_progress/done/cancelled, assignee User, checklist/photos/relevés de stock en JSON, `externalRef` unique par lieu), `CleaningChecklistItem` (modèle par `placeId`), `Site` (lieu connu localement : `local` en autonome, ou cache `place` d'un lieu de Rocket Place ; porte le dossier Rocket Cloud).
- Lieux et stock : `Place/PlaceDirectory` → `Place/PlaceClient` (Rocket Place, `ROCKET_PLACE_URL`/`ROCKET_PLACE_TOKEN`, mode suite par `ServiceTokenProvider` audience `rocket-place`) si configuré, sinon `Site` locaux sans stock. Photos : `Cloud/CloudClient` (+ `DemoCloud`) direct. E-mails : `Mailer/MailerClient` (+ `DemoMailer`).
- `Controller/CleaningController` (planification = CLEAN_MANAGE, exécution = personne attribuée ou non attribué), `Controller/PlaceController` (lieux), `Controller/PublicCleaningController` (lien secret, `PublicRateLimiter`), `Cleaning/*` (Work, LinkSigner, Notifier, Settings, Schedule). Accès : `Security/CleanAccessVoter`, `Security/CleanScopeGuardListener` (applications pour elles-mêmes). Tableau de bord : `Dashboard/CleaningSection`. Démo : `Command/CleanDemoSeeder`.
- Les chemins d'API restent ceux de Rocket Place (`/api/places/{placeId}/cleanings`…) : un PMS bascule en changeant l'URL et le jeton.
- Front : `pages/menage.vue` (téléphone), `pages/places/index.vue` + `[id].vue` (`CleaningTab`, `CleaningCard`), `pages/m/[token].vue` (public).

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```

## Pièges connus
- Les tests restent hors réseau : `ROCKET_PLACE_URL` vide (autonome), `DemoCloud`, `DemoMailer` ; le mode Place est couvert par `tests/Unit/PlaceDirectoryTest` (MockHttpClient).
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json`.
- Migrations : lancer d'abord celles du socle, puis `doctrine:migrations:diff`.
- Pas de Composer sur le Mac de Faez : `docker run --rm -v "$PWD":/app -w /app composer:2 install --ignore-platform-reqs`. Cache npm global en erreur de droits : `npm ci --cache <dossier temporaire>`.
