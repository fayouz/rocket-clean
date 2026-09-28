# Rocket Clean

**Ménage** des lieux, extrait de Rocket Place, sur la stack des briques Rocket. Socle commun : [rocket-core](https://github.com/fayouz/rocket-core) (bundle Symfony `rocket/core-bundle` + layer Nuxt `@rocket/core`), à lire avant de modifier les comptes, le SSO, les applications, le tableau de bord ou la mise en page : ce code n'est pas ici.

## Repères
- `app_id` `clean`, jetons d'application `rcl_…`, ports front 4000 · api 9000 · docs 4001. Base de dev : conteneur `rocket-clean-db` (postgres:16-alpine, 127.0.0.1:55437, app/app), `.env.local` / `.env.test.local` non suivis.
- Domaine : `CleaningTask` (lieu par **identifiant** `placeId` + `placeName` en cache, jamais de clé étrangère ; fenêtre `DATETIMETZ`, statut todo/in_progress/done/cancelled, assignee User, checklist/photos/relevés de stock en JSON, `externalRef` unique par lieu), `CleaningChecklistItem` (modèle par `placeId`), `CleaningRecurrence` (règle hebdo/mensuelle → `Cleaning/RecurrenceGenerator`, quotidien, `recurrence:<id>:<date>`), `OccupiedPeriod` (séjours poussés par Host/PMS → `conflict`), `CleaningCost` (coût par défaut par lieu+type), `Cleaning/CleaningPlanner` (checklist par type avec repli `rental`, coût par défaut, conflits). Tâche : `type` rental/personal/maintenance, `origin` + `originApp` (une app ne modifie pas les ménages d'une autre). `Site` (lieu connu localement : `local` en autonome, ou cache `place` d'un lieu de Rocket Place ; porte le dossier Rocket Cloud).
- Lieux et stock : `Place/PlaceDirectory` → `Place/PlaceClient` (Rocket Place, `ROCKET_PLACE_URL`/`ROCKET_PLACE_TOKEN`, mode suite par `ServiceTokenProvider` audience `rocket-place`) si configuré, sinon `Site` locaux sans stock. Stock : `Stock/CleaningStock` → `Stock/StockClient` (Rocket Stock, `ROCKET_STOCK_URL`/`ROCKET_STOCK_TOKEN`, audience `rocket-stock` ; relevé = `POST /api/movements` consume `cleaning:<id>:<levelId>` + `PATCH /api/stock-levels/{id}`), sinon stock de Place, sinon aucun. Photos : `Cloud/CloudClient` (+ `DemoCloud`) direct. E-mails : `Mailer/MailerClient` (+ `DemoMailer`).
- `Controller/CleaningController` (planification = CLEAN_MANAGE, exécution = personne attribuée ou non attribué), `Controller/PlaceController` (lieux), `Controller/PublicCleaningController` (lien secret, `PublicRateLimiter`), `Cleaning/*` (Work, LinkSigner, Notifier, Settings, Schedule). Accès : `Security/CleanAccessVoter`, `Security/CleanScopeGuardListener` (applications pour elles-mêmes). Tableau de bord : `Dashboard/CleaningSection`. Démo : `Command/CleanDemoSeeder`.
- Les chemins d'API restent ceux de Rocket Place (`/api/places/{placeId}/cleanings`…) : un PMS bascule en changeant l'URL et le jeton.
- **Linge** : module isolé `src/Linen/` (namespace `App\Linen\*`, entités `Linen/Entity` mappées à part dans `doctrine.yaml`, tables `linen_*`, API `/api/linen/*`, voter `LINEN_READ`/`LINEN_MANAGE`, `Dashboard/LinenSection`, `Command/LinenDemoSeeder`). Toute quantité change par `LinenLedger::move()` (mouvement idempotent par `externalRef`, strict sauf relevés terrain `lenient`). Couplage au ménage **uniquement** par les ports `Linen/Contract/*` (`CleaningJobs`, `ArrivalSource`, `PlaceNames`) implémentés par `Cleaning/LinenBridge` ; `PublicCleaningController` appelle `Linen/CleaningLinen`. Ne pas importer `App\Entity\*` depuis `App\Linen`. Front : `pages/linge/*`, `components/linen/*`, `types/linen.ts`, `utils/linen.ts`.
- Assistant vocal : `frontend/app/utils/assistant/parser.ts` (analyse pure, tests `npm run test` = `node --test tests/*.test.ts`, repris de Doc Assist `src/Assistant`), `composables/useSpeech.ts` (Web Speech API, aucun son au serveur), `components/CleaningAssistant.vue` (dans `CleaningCard`), compte rendu `Cleaning/CleaningReport` (écrit par `CleaningWork::apply` au passage à done, e-mail `report` désactivé par défaut).
- Front : `pages/menage.vue` (téléphone), `pages/places/index.vue` + `[id].vue` (`CleaningTab`, `CleaningCard`), `pages/m/[token].vue` (public).

## Vérifier avant de pousser
```bash
cd backend && php bin/console lint:container && php bin/console doctrine:schema:validate && php bin/phpunit
cd frontend && npm run lint && npm run typecheck && npm run test
cd docs && npm run lint && npm run typecheck && npm run generate   # si docs/ a changé
```

## Pièges connus
- Les tests restent hors réseau : `ROCKET_PLACE_URL` vide (autonome), `DemoCloud`, `DemoMailer` ; le mode Place est couvert par `tests/Unit/PlaceDirectoryTest` (MockHttpClient).
- API Platform répond en JSON-LD par défaut : envoyer `Accept: application/json`.
- Migrations : lancer d'abord celles du socle, puis `doctrine:migrations:diff`.
- Pas de Composer sur le Mac de Faez : `docker run --rm -v "$PWD":/app -w /app composer:2 install --ignore-platform-reqs`. Cache npm global en erreur de droits : `npm ci --cache <dossier temporaire>`.
