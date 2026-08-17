# API REST ChargeTrackr

Ce dossier contient le contrat OpenAPI 3.1 de l'API REST ChargeTrackr. La
spécification est générée depuis les routes, contrôleurs, Form Requests et
Resources Laravel : le code backend reste ainsi la source de vérité.

L'export versionné couvre actuellement 143 chemins, 180 opérations et 69 schémas.
Ces nombres sont indicatifs ; les tests vérifient surtout qu'aucune route publique
Laravel n'est absente du contrat.

## Accès à la documentation

| Environnement | Interface Swagger | Document JSON |
|---|---|---|
| Local | `http://localhost:8000/docs/api` | `http://localhost:8000/docs/api.json` |
| Production | `https://api.chargetrackr.me/docs/api` | `https://api.chargetrackr.me/docs/api.json` |
| Export versionné | - | `docs/api/openapi.json` |

Lancer localement le backend depuis la racine du dépôt avec
`npm run dev:backend`. La documentation est libre en environnement local. Dans
les autres environnements, elle est réservée à un utilisateur authentifié ayant
le rôle `super_admin`.

## Frontières de l'API

Le contrat public regroupe les opérations selon douze domaines fonctionnels :

- accès, authentification, invitations et onboarding ;
- compte, profil et préférences personnelles ;
- espace de travail, recherche et notifications ;
- organisations, employés et clients ;
- stations, connecteurs, OCPP et laboratoire de simulation ;
- sessions et tentatives de recharge ;
- paiements, reçus et règlements ;
- tarifs, plans, abonnements et facturation des organisations ;
- alertes, interventions et maintenance ;
- rapports, analyses, pièces jointes et exports ;
- documents protégés ;
- gouvernance globale du Super Administrateur.

Les routes `/api/internal/ocpp/*` et
`/api/internal/payments/webhooks` sont volontairement exclues. Elles servent aux
échanges machine-à-machine, avec leurs propres contrôles HMAC, anti-rejeu et
idempotence ; elles ne constituent pas l'API destinée aux applications clientes.

## Authentification Sanctum

ChargeTrackr utilise l'authentification SPA stateful de Laravel Sanctum. Les
clients web utilisent une session et un jeton CSRF, pas un Bearer token.

1. Appeler `GET /sanctum/csrf-cookie` en conservant les cookies.
2. Appeler `POST /api/auth/login` avec `email` et `password`.
3. Conserver les cookies `laravel_session` et `XSRF-TOKEN`.
4. Pour `POST`, `PUT`, `PATCH` et `DELETE`, transmettre la valeur décodée de
   `XSRF-TOKEN` dans l'en-tête `X-XSRF-TOKEN`.
5. Fermer la session avec `POST /api/auth/logout`.

Exemple PowerShell local :

```powershell
$backend = "http://localhost:8000"
$cookies = Join-Path $env:TEMP "chargetrackr-cookies.txt"

curl.exe -sS -c $cookies -b $cookies "$backend/sanctum/csrf-cookie" | Out-Null
$csrfLine = Get-Content $cookies |
    Where-Object { $_ -match "`tXSRF-TOKEN`t" } |
    Select-Object -Last 1
$csrf = [uri]::UnescapeDataString(($csrfLine -split "`t")[-1])

$body = @{ email = "operator@chargetrackr.local"; password = "your-password" } |
    ConvertTo-Json -Compress

curl.exe -sS -c $cookies -b $cookies `
    -H "Accept: application/json" `
    -H "Content-Type: application/json" `
    -H "X-XSRF-TOKEN: $csrf" `
    --data-raw $body `
    "$backend/api/auth/login"

curl.exe -sS -c $cookies -b $cookies `
    -H "Accept: application/json" `
    "$backend/api/stations?per_page=10"
```

Dans Swagger UI, le navigateur conserve les cookies. Il faut néanmoins appeler
le cookie CSRF puis la connexion avant d'exécuter une opération protégée.

Une route publique peut être testée directement :

```powershell
curl.exe -sS -H "Accept: application/json" `
    "http://localhost:8000/api/public/commercial-plans"
```

## Autorisation et isolation

L'authentification ne suffit pas à autoriser une action. Les contrôleurs,
Policies et requêtes vérifient le rôle, l'organisation et la propriété de la
ressource. Un identifiant valide appartenant à une autre organisation ne doit
donc jamais permettre de lire ou modifier cette ressource.

Les comptes `admin`, `operator` et `technician` sont rattachés à une seule
organisation. Le `super_admin` gère la plateforme, tandis que le client conserve
un compte indépendant et peut interagir avec plusieurs réseaux de recharge.

## Conventions d'échange

- Base locale : `http://localhost:8000/api`
- Base de production : `https://api.chargetrackr.me/api`
- Représentation principale : JSON UTF-8
- Dates : ISO 8601 avec fuseau horaire
- Pagination : `page` et `per_page`, avec une taille bornée par le serveur
- Validation : HTTP `422` avec `message` et `errors`
- Non authentifié : HTTP `401`
- Non autorisé : HTTP `403`
- Session ou jeton CSRF expiré : HTTP `419`
- Ressource absente : HTTP `404`
- Conflit métier : HTTP `409`
- Limite dépassée : HTTP `429`

Les montants sont exprimés en TND dans les réponses métier ; certaines valeurs
internes de paiement sont conservées en millimes pour éviter les erreurs
d'arrondi. Les opérations sensibles de paiement et de simulation utilisent une
clé `idempotency_key` UUID dans leur corps lorsque le schéma OpenAPI l'indique.

Les téléchargements de reçus, factures, rapports et documents peuvent retourner
un flux PDF ou un autre contenu binaire. Les exports tabulaires utilisent CSV ou
JSON selon le paramètre de format documenté.

## Régénération et contrôle

Après toute modification de route, validation, Resource ou contrôleur :

```powershell
cd backend
C:\php\php.exe -d memory_limit=512M artisan scramble:export
C:\php\php.exe -d memory_limit=512M artisan scramble:analyze
C:\php\php.exe artisan test --filter=ApiDocumentationTest
```

Avec la pile Docker :

```powershell
pnpm stack:artisan -- scramble:export
pnpm stack:artisan -- scramble:analyze
```

Avant de committer une évolution d'API, vérifier que :

1. le résumé, le groupe fonctionnel et l'`operationId` sont explicites ;
2. les entrées, réponses et erreurs correspondent au code exécuté ;
3. la route ne contourne ni Policy ni périmètre d'organisation ;
4. une opération publique possède explicitement `security: []` ;
5. `docs/api/openapi.json` est régénéré dans le même commit ;
6. les tests de documentation et la suite backend passent.
