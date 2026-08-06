# 🔍 Rapport de revue de code — ChargeTrackr

**Date :** 6 août 2026  
**Périmètre :** Backend Laravel (~20,5K lignes), Frontend React/TS (~30K lignes), Gateway OCPP Python (~800 lignes), infrastructure Docker, scripts  
**Méthode :** Chaque constat a été vérifié dans le code réel (pas de faux positif sur convention de nommage)

---

## Verdict global

ChargeTrackr est une **base de code mature et disciplinée**. Côté sécurité applicative backend : **aucune vulnérabilité exploitable Critique/Élevée/Moyenne** — défense en profondeur cohérente (autorisation scopée par rôle/organisation, webhooks HMAC, idempotence, anti-rejeu).

Les vrais points d'attention concernent :
1. **Trois bugs de facturation critiques** (double débit, fonds bloqués, compteurs "du jour" faux)
2. **Durcissement de l'infra/transport** (exposition DB/Redis, TLS OCPP)
3. **Robustesse frontend** (typage strict, gestion d'erreurs centralisée, tests)

### Signaux de qualité positifs

- ✅ **0** marqueur `TODO/FIXME/HACK` dans tout le code applicatif
- ✅ **0** reste de debug (`dd`/`var_dump`/`console.log`/`print`)
- ✅ Aucun secret commité, `.env.example` propres
- ✅ 39 tests backend + 3 tests gateway OCPP
- ✅ Config sécurité saine (CORS scopé, cookies `http_only`/`same_site=lax`)

---

## Findings classés par gravité

### 🔴 CRITIQUE (bugs d'argent)

#### 1. Double débit possible sur les sessions OCPP pré-autorisées

**Fichiers :**
- `backend/app/Services/PaymentService.php:79` (et `:127-184`)
- `backend/app/Http/Controllers/Api/PaymentController.php:100`
- `backend/app/Policies/PaymentPolicy.php:25`

**Description :**
Deux chemins de paiement peuvent s'exécuter pour **la même session** sans exclusion mutuelle :
- `process()` (endpoint manuel, autorisé pour toute session appartenant au client) → **nouveau** `gateway->charge()`
- `captureAuthorized()` (job à l'arrêt OCPP) → `gateway->capture()` de la pré-autorisation

**Dans les deux cas, l'appel externe au gateway est fait HORS du verrou DB** : le verrou est pris puis relâché avant l'appel, et le garde-fou "déjà payée ?" est évalué avant l'appel externe. Résultat : `charge` **et** `capture` peuvent aboutir avec des clés d'idempotence différentes.

**Conséquence :** **Le client est débité deux fois.** Idem si `process()` est appelé 2× en parallèle (double-clic).

**Correctif :**
1. Interdire `process()` pour une session ayant une `ChargingAttempt` pré-autorisée
2. Re-vérifier l'état "payée / autorisation existante" **à l'intérieur** de la section verrouillée, juste avant l'appel gateway
3. Utiliser une clé d'idempotence unique et partagée par session

---

### 🟠 ÉLEVÉ

#### 2. Postgres & Redis exposés sur toutes les interfaces + credentials faibles

**Fichier :** `infra/docker-compose.yml:9-10,17-18`

**Description :**
Contrairement à la stack OCPP (bindée sur `127.0.0.1`), Postgres (`5432:5432`) et Redis (`6379:6379`) publient sur `0.0.0.0`. Postgres utilise un mot de passe trivial codé en dur (`POSTGRES_PASSWORD: changetrackr`, identique au username). Redis n'a **aucune** authentification.

**Conséquence :**
Sur tout réseau non-loopback (Wi-Fi/LAN partagé), accès complet en lecture/écriture aux données clients/transactions/paiements.

**Correctif :**
- Binder sur `127.0.0.1:5432:5432` et `127.0.0.1:6379:6379`
- Externaliser le secret Postgres dans un `.env` gitignoré (comme fait proprement dans `infra/ocpp/compose.yaml`)
- Ajouter `--requirepass` à Redis

---

#### 3. TypeScript `strict` désactivé sur tout le frontend

**Fichier :** `frontend/tsconfig.app.json`

**Description :**
Aucun `strict`/`strictNullChecks`/`noImplicitAny` activé. Le code est *écrit* comme si strict était actif (usage massif de `?.`/`??`), mais le compilateur ne vérifie ni les `null`/`undefined` ni les `any` implicites.

**Conséquence :**
La sûreté de types est **illusoire** sur ~30K lignes. Déréférencements potentiels de `null` (ex. `user`, `data`, `organization`) non détectés à la compilation → risque de crashs runtime non captés.

**Correctif :**
Activer `"strict": true` (au minimum `"strictNullChecks": true`) dans `tsconfig.app.json`, corriger les erreurs remontées, idéalement ajouter `"noUncheckedIndexedAccess": true`.

---

### 🟡 MOYEN

#### 4. Compteurs "du jour" jamais remis à zéro (valeurs cumulées à vie)

**Fichiers :**
- `backend/app/Services/ChargingSessionService.php:156-157,328-331`
- `backend/app/Services/PaymentService.php:102,200`
- `backend/app/Http/Resources/StationResource.php:57-59`

**Description :**
`energy_today_kwh`, `sessions_today` et `revenue_today` sont **uniquement incrémentés**, jamais réinitialisés. Le scheduler (`routes/console.php`) ne contient **aucune** tâche de reset quotidien.

**Conséquence :**
Dès le 2ᵉ jour, ces trois valeurs sont des **totaux cumulés à vie** présentés comme des indicateurs "du jour" → **dashboards/KPI stations faux**, croissance indéfinie.

**Correctif :**
Ajouter une commande planifiée `dailyAt('00:00')` remettant les trois colonnes à 0 ; ou, mieux, calculer le "aujourd'hui" à la volée depuis `charging_sessions`/`payments` filtrés par date.

---

#### 5. Pré-autorisation retenue indéfiniment après interruption de connectivité

**Fichiers :**
- `backend/app/Services/ChargingSessionService.php:341-372`
- `backend/app/Services/Availability/AvailabilityProjectionService.php:92`

**Description :**
Quand une station passe hors ligne, `interruptOcppForConnectivity()` met la transaction OCPP en `awaiting_reconciliation` et la session en `interrupted`, mais **ne capture ni ne libère** la pré-autorisation. Aucune tâche planifiée ne réconcilie ces états.

**Conséquence :**
Si la station ne revient jamais / n'émet jamais de `StopTransaction`, les **fonds du client restent bloqués** sans capture ni libération.

**Correctif :**
Ajouter une commande planifiée qui, après un délai de grâce (ex. 24h), capture (sur base du dernier index compteur) ou libère l'autorisation des transactions `awaiting_reconciliation` / sessions `interrupted` orphelines.

---

#### 6. Job de capture épuisé sans reprise ni libération

**Fichier :** `backend/app/Jobs/CaptureAuthorizedSessionPayment.php:14-27`

**Description :**
Le job a `tries = 3` + backoff mais **aucun `failed()`**. Si les 3 tentatives échouent (gateway indisponible), la session reste non payée et la pré-autorisation n'est **ni capturée ni libérée**.

**Conséquence :**
Perte de revenu **et** fonds retenus sur la carte du client.

**Correctif :**
Implémenter `failed()` (libération ou marquage pour réconciliation manuelle) et/ou un balayage périodique qui retente la capture ou libère l'autorisation.

---

#### 7. Pas de TLS sur le WebSocket OCPP

**Fichiers :**
- `ocpp-gateway/src/chargetrackr_ocpp/server.py:168-176`
- `infra/ocpp/simulator/config.json:3`

**Description :**
Le serveur écoute en `ws://` (pas `wss://`). Le secret de borne circule via Basic auth en quasi-clair (base64).

**Conséquence :**
Toute interception sur le chemin borne↔gateway expose les credentials de station. En production sans terminaison TLS en amont : vol de secret et usurpation possible.

**Correctif :**
Terminer le TLS (`wss`) devant le gateway, ou activer `ssl` dans `serve()`. Documenter que le déploiement exige un reverse-proxy TLS.

---

#### 8. Canal gateway→Laravel en HTTP par défaut + réponses non authentifiées

**Fichiers :**
- `ocpp-gateway/src/chargetrackr_ocpp/config.py:31-34`
- `api_client.py:68-75`, `charge_point.py:205,235-237`

**Description :**
Les requêtes sortantes sont signées (HMAC), mais l'URL par défaut est en HTTP clair et les **réponses** du backend (ex. `idTagInfo`, `transactionId`) sont utilisées telles quelles sans vérification d'intégrité/signature.

**Conséquence :**
Un MITM sur le canal backend peut injecter des réponses (ex. forcer `Authorize` accepté, imposer un `transactionId`), contournant l'autorisation.

**Correctif :**
Imposer HTTPS pour `OCPP_LARAVEL_BASE_URL` (rejeter http hors localhost), et idéalement signer/valider aussi les réponses.

---

#### 9. Pas d'intercepteur Axios / gestion centralisée des sessions expirées

**Fichiers :**
- `frontend/src/api/httpClient.ts` (aucun `interceptors.response`)
- `frontend/src/app/queryClient.ts` (aucun `onError`/`QueryCache` global)

**Description :**
La session n'est vérifiée qu'au montage (`AuthProvider.tsx:18-47`). Un `401` (session expirée) ou `419` (CSRF) survenant ensuite ne déconnecte ni ne redirige l'utilisateur.

**Conséquence :**
À l'expiration de session, l'utilisateur reste sur des pages cassées empilant des erreurs, sans redirection ni message clair. Pas de retry CSRF automatique sur 419.

**Correctif :**
Ajouter un intercepteur `response` sur `httpClient` qui, sur `401`, purge la session (`queryClient.clear()` + redirection `/login`) et sur `419` retente après `csrfCookieRequest()`.

---

#### 10. `ConnectionOpened` non protégé contre une erreur backend

**Fichier :** `ocpp-gateway/src/chargetrackr_ocpp/server.py:109-118`

**Description :**
Contrairement à `ConnectionClosed` (`:148-161`), l'appel `publish_event(ConnectionOpened)` n'est pas encapsulé dans un `try/except`. Une `GatewayApiError` (backend indisponible) se propage hors de `handle_connection` : la tâche de polling n'est jamais créée, aucun `ConnectionClosed` n'est émis.

**Conséquence :**
Un simple à-coup du backend déconnecte des bornes venant de s'authentifier et laisse un état incohérent (open jamais enregistré, pas de close).

**Correctif :**
Entourer la publication d'un `try/except GatewayApiError` avec log et fermeture propre (1013), cohérent avec le reste du flux.

---

#### 11. Lint frontend insuffisant — `react-hooks/exhaustive-deps` non activé

**Fichier :** `frontend/.oxlintrc.json` (seules 2 règles : `rules-of-hooks`, `only-export-components`)

**Description :**
La règle des dépendances de `useEffect` n'est pas appliquée. Des effets abonnent des canaux realtime avec des deps sur l'objet `user` (référence changeante à chaque refetch de session) : `AvailabilityRealtimeSync.tsx:78`, `NotificationCenter.tsx:68` → ré-abonnements/désabonnements superflus non signalés.

**Correctif :**
Activer `react-hooks/exhaustive-deps` (warn/error) et stabiliser les deps (dépendre de `user?.id`/`primaryRole` plutôt que de l'objet `user`).

---

#### 12. Duplication de la logique d'extraction d'erreur API

**Fichiers :** redéfinie dans ≥7 fichiers — `StationsPage.tsx:323`, `StationDetailPage.tsx:1053`, `InterventionsPage.tsx:573`, `MaintenancePage.tsx:336`, `PaymentsPage.tsx:115`, `StartSessionDrawer.tsx:301`, `authApi.ts:83`

**Conséquence :**
Comportement d'erreur incohérent d'une page à l'autre (gestion 429/422 présente à certains endroits, absente ailleurs). Maintenance fragile.

**Correctif :**
Extraire un utilitaire unique `getApiErrorMessage(error, fallback)` dans `utils/` et l'importer partout.

---

### 🟢 FAIBLE

#### 13. Frais d'immobilisation (idle fee) jamais facturés

**Fichier :** `backend/app/Services/ChargingSessionService.php:387-399,124-130`

`idle_fee_per_minute_millimes` est résolu depuis le tarif et stocké sur la session, mais **n'est utilisé dans aucun calcul de prix**. Tout tarif configuré avec des frais d'immobilisation sous-facture silencieusement. Intégrer `temps d'immobilisation × idle_fee` au total, ou retirer le champ.

#### 14. Plans de maintenance récurrents jamais `completed`

**Fichier :** `backend/app/Services/Maintenance/MaintenancePlanService.php:74-83,150-152`

En fin de récurrence, `nextDate()` renvoie `null` et le plan reste `active` indéfiniment (la branche `completed` est morte pour les plans normaux). Comptages "plans actifs" surévalués. Positionner `status = 'completed'` lorsque `nextDate()` renvoie `null` en fin de récurrence.

#### 15. Pas de garde DB contre deux sessions actives par client

**Fichier :** `backend/app/Services/ChargingSessionService.php:47-54,172-179`

L'unicité "une seule session active par client" repose uniquement sur un `exists()` applicatif (TOCTOU), sans contrainte DB. Deux `StartTransaction` OCPP simultanés (verrous disjoints) peuvent créer deux sessions actives. Probabilité faible. Ajouter un index unique partiel (client + statut actif).

#### 16. Énumération de comptes (2 vecteurs)

**Fichiers :** `backend/app/Http/Requests/Auth/RegisterClientRequest.php:35-40` (message d'unicité explicite) et `backend/app/Http/Controllers/Api/AuthController.php:60` (canal temporel : `Hash::check` seulement si l'e-mail existe)

*Atténué* par `throttle:5,1`. Renvoyer un message neutre à l'inscription + exécuter un `Hash::check` factice quand l'utilisateur est nul pour normaliser le temps de réponse.

#### 17. Gateway : `username == identity` non imposé localement

**Fichier :** `ocpp-gateway/src/chargetrackr_ocpp/server.py:85-118`

*Réconcilié :* la revue backend confirme que `/authenticate` valide l'identité **couplée** au secret (`hash_equals` identité + `Hash::check`). L'usurpation inter-bornes est donc **bloquée côté backend** ; ce point reste une défense en profondeur triviale à ajouter, pas une faille exploitable. Ajouter `if username != identity: close(1008)`.

#### 18. Robustesse frontend divers

- `getRoleConfig(null)` retombe silencieusement sur `operator` (`frontend/src/features/auth/roleConfig.tsx:116`)
- Gating de rôle basé sur `roles[0]` seul, multi-rôles ignoré (`AuthProvider.tsx:70`)
- Assertions `as`/`!` fragiles (`StationsPage.tsx:77`, `GoogleOAuthCallbackPage.tsx:18`)
- `navigate(notification.action_url)` non validé (`NotificationCenter.tsx:73`) — valider que l'URL commence par `/`
- `frontend/.env` absent du `.gitignore` (risque de commit accidentel ; valeurs actuelles = `VITE_*` publiques)

#### 19. Infra / scripts divers

- Tags d'image Docker mutables (`ocpp-gateway/Dockerfile:1`, `python:3.13-slim`/`node:24-alpine`) → épingler par digest
- Pas de `HEALTHCHECK` gateway ; `depends_on` sans `service_healthy`
- Substitution `sed` du mot de passe sans échappement (`infra/ocpp/simulator/run-scenario.sh:9`)
- Clé d'API mock en dur (`infra/payment-simulator/mappings/*.json`) — valeur de test uniquement

---

## Points examinés SANS anomalie (défense en profondeur confirmée)

- **Autorisation / IDOR :** tous les contrôleurs couplent `Gate::authorize` + requête scopée par rôle/organisation. Aucun IDOR.
- **Mass assignment :** aucun `create($request->all())` ; champs privilégiés verrouillés dans les FormRequests.
- **Injection SQL :** tous les `whereRaw/selectRaw` utilisent des paramètres liés ou des constantes internes.
- **Webhooks paiement/OCPP :** HMAC-SHA256 + `hash_equals`, anti-rejeu (`Cache::add`), idempotence par `event_id`. Le montant crédité provient de l'enregistrement local, pas du webhook.
- **Auth :** login à erreurs génériques, session régénérée, OAuth Google stateful (CSRF `state`), invitations à tokens SHA-256 + expiration.
- **Idempotence paiements/abonnements :** contraintes uniques DB confirmées.
- **Concurrence OCPP :** `lockForUpdate`, garde `stop_event_id`, capture en `afterCommit`.
- **N+1 :** pas de N+1 significatif sur les chemins de listing chauds.
- **Signature gateway (sortante) :** HMAC-SHA256 robuste, secret ≥ 32 car., régénérée à chaque retry.
- **Dockerfile gateway :** non-root (uid 10001), base slim, aucun secret dans l'image.

---

## Synthèse — Top priorités

| Rang | Action | Fichier clé | Gravité | Effort |
|------|--------|-------------|---------|--------|
| 1 | **Empêcher le double débit** (verrou englobant l'appel gateway + clé d'idempotence par session) | `PaymentService.php:79` | 🔴 Critique | Moyen |
| 2 | **Reset quotidien des compteurs `*_today`** (ou calcul à la volée) | `ChargingSessionService.php:156` | 🟡 Moyen | Faible |
| 3 | **Réconciliation des pré-autorisations orphelines** + `failed()` sur le job de capture | `CaptureAuthorizedSessionPayment.php` | 🟡 Moyen | Moyen |
| 4 | Binder Postgres/Redis sur loopback + auth + secret externalisé | `infra/docker-compose.yml:9` | 🟠 Élevé | Faible |
| 5 | Activer TS `strict` (au moins `strictNullChecks`) | `frontend/tsconfig.app.json` | 🟠 Élevé | Moyen |
| 6 | Intercepteur Axios 401/419 + extraction d'erreur unifiée | `frontend/src/api/httpClient.ts` | 🟡 Moyen | Faible |
| 7 | TLS sur WS OCPP + HTTPS gateway→Laravel | `ocpp-gateway/.../server.py` | 🟡 Moyen | Moyen |

---

## Bilan

ChargeTrackr est un projet **solide et abouti** : sécurité applicative backend sans faille exploitable (autorisation scopée, webhooks HMAC, idempotence, anti-rejeu), code discipliné (0 debug, 0 TODO, 0 secret commité), 39 tests backend. Les vrais risques ne sont **pas** des trous de sécurité classiques mais :

1. **Trois bugs de facturation** (double débit, fonds bloqués, compteurs "du jour" faux) — à traiter en priorité car ils touchent l'argent réel des clients.
2. **Durcissement infra/transport** (exposition DB/Redis, TLS OCPP) avant tout usage hors poste local.
3. **Robustesse frontend** (TS strict, gestion d'erreur centralisée, **0 test frontend** vs 39 backend).
