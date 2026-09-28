# Environnement de démo

## Dans GitHub Codespaces (rien à installer)

1. Sur GitHub, ouvre le dépôt, choisis la branche qui contient la démo, puis **Code → Codespaces → Create codespace on …**.
2. Attends la fin de la commande de démarrage dans le terminal (5 à 10 minutes au premier lancement, le temps de construire les images). Elle affiche les URLs de la démo.
3. Dans l'onglet **Ports**, ouvre « Rocket Clean » (4000) ou « Documentation et changelog » (4001).

> ⚠️ Les mots de passe de démo sont publics. Arrête le codespace quand tu as fini (menu Codespaces → *Stop codespace*).

Pour relancer la démo à la main : `bash demo/codespaces/start.sh`.

## En local

Pré-requis : Docker avec Compose v2.24 ou plus récent.

```bash
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
```

Le service `demo-seed` prépare la base, charge les données de démo et synchronise l'annuaire LDAP, puis s'arrête : `docker compose -f compose.yaml -f compose.demo.yaml logs -f demo-seed`.

| Adresse | Contenu |
|---|---|
| http://localhost:4000 | Rocket Clean |
| http://localhost:4001 | Documentation, et le changelog sur `/changelog` |
| http://localhost:9000/api/docs | Documentation de l'API |

La démo contient deux lieux locaux (« Le port », « Les vignes »), leur checklist et deux ménages attribués à Alice (aujourd'hui, et un en retard). Ni Rocket Place, ni Rocket Cloud, ni Rocket Mailer : rien n'est envoyé.

## Comptes

| Compte | Mot de passe | Type |
|---|---|---|
| `admin@example.org` | `demo-admin-password` | local, administrateur |
| `alice@example.org` | `demo-alice-password` | local |
| `marie.martin@example.org` | `password` | LDAP, administratrice via le groupe `rocket-admins` |
| `jean.dupont@example.org` | `password` | LDAP |

## Scénarios à tester

1. Connectez-vous avec `alice@example.org`.
2. **Ménages du jour** : commencer, cocher la checklist, ajouter une photo, terminer.
3. En administrateur, **Lieux → Le port** : planifier un ménage, copier le lien sans compte et l'ouvrir en navigation privée.
