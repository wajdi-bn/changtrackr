# API REST ChargeTrackr

Ce dossier contient le contrat OpenAPI 3.1 de l'API REST ChargeTrackr. La
spécification est générée depuis les routes, contrôleurs, Form Requests et
Resources Laravel afin de rester synchronisée avec le code source.

## Accès local

Lancer le backend depuis la racine du dépôt :

```powershell
npm run dev:backend
```

Puis ouvrir :

- interface interactive : `http://localhost:8000/docs/api`
- document JSON dynamique : `http://localhost:8000/docs/api.json`
- export versionné : `docs/api/openapi.json`

L'interface est accessible librement en environnement local. Hors environnement
local, son accès est réservé au rôle `super_admin`.

## Authentification Sanctum

ChargeTrackr utilise l'authentification SPA stateful de Laravel Sanctum. Il ne
faut pas inventer ou coller un Bearer token.

1. Appeler `GET /sanctum/csrf-cookie` avec les cookies activés.
2. Appeler `POST /api/auth/login` avec `email` et `password`.
3. Conserver le cookie de session `laravel_session`.
4. Pour `POST`, `PUT`, `PATCH` et `DELETE`, envoyer aussi la valeur du cookie
   `XSRF-TOKEN` dans l'en-tête `X-XSRF-TOKEN`.
5. Fermer la session avec `POST /api/auth/logout`.

Le bouton **Try it** de l'interface conserve les cookies du navigateur. Le flux
CSRF et la connexion doivent néanmoins être effectués avant de tester une route
protégée.

## Périmètre documenté

Le contrat couvre notamment :

- authentification, inscription client, vérification email et mot de passe ;
- demandes de démonstration et invitations de comptes ;
- profil, préférences, notifications et recherche globale ;
- organisations, utilisateurs, clients et gestion commerciale ;
- stations, connecteurs, supervision et commandes OCPP ;
- alertes, interventions, maintenance et documents ;
- sessions de recharge, tentatives, tarification et paiements ;
- abonnements, factures, reçus, rapports et exports ;
- administration globale, intégrations, audit et paramètres système.

Les routes `/api/internal/ocpp/*` et
`/api/internal/payments/webhooks` sont volontairement exclues : elles sont
réservées aux échanges machine-à-machine et possèdent leur propre frontière de
sécurité.

## Conventions

- Base locale : `http://localhost:8000/api`
- Représentation : JSON UTF-8
- Dates : ISO 8601
- Pagination : métadonnées Laravel lorsque la collection est paginée
- Validation : HTTP `422` avec `message` et `errors`
- Non authentifié : HTTP `401`
- Non autorisé : HTTP `403`
- Ressource absente : HTTP `404`
- Limite dépassée : HTTP `429`
- Conflit métier : HTTP `409` lorsque le workflow l'exige

L'isolation multi-organisation est appliquée côté serveur. Un identifiant valide
appartenant à une autre organisation ne donne pas accès à la ressource.

## Régénération

Après toute modification de route, validation, ressource ou contrôleur :

```powershell
cd backend
php artisan scramble:export
```

Le fichier `docs/api/openapi.json` doit être relu dans la documentation
interactive et inclus dans le même commit que le changement d'API.
