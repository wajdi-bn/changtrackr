# ⚡ Rapport Performance & Scalabilité — ChargeTrackr

**Date :** 6 août 2026  
**Complément du rapport Correction/Sécurité/Qualité**  
**Méthode :** Analyse du code, des migrations, du build, et de la configuration réelle. Tous les chiffrages sont basés sur les paramètres effectifs (.env, heartbeat 30s, poll 1.5s, etc.)

---

## Résumé exécutif

**Verdict :** L'architecture actuelle plafonne vers **~15 bornes** à cause d'un rate limiter interne trop restrictif (goulot n°1), puis vers **1 000-1 500 bornes** à cause du modèle de polling OCPP (goulot n°2) et des drivers `database` (goulot n°3). Le frontend initial charge **~31 Mo d'images PNG non optimisées** + un favicon de 858 Ko (goulot n°4).

Les **3 goulots backend critiques** bloquent la montée en charge bien avant les vrais problèmes de code. Les correctifs proposés permettront de tenir **10 000+ bornes** et **quelques centaines de sessions simultanées** sans refonte architecturale.

---

## 🔴 GOULOTS CRITIQUES (bloquent la montée en charge dès maintenant)

### 1. Rate limiter interne : plafond ABSOLU à ~15 bornes [OCPP/Realtime]

**Fichier :** `backend/app/Providers/AppServiceProvider.php:63-64`

```php
RateLimiter::for('ocpp-gateway', fn (Request $request) => Limit::perMinute(600)
    ->by('ocpp-gateway:'.$request->ip()));
```

Appliqué aux 4 endpoints internes (`/authenticate`, `/events`, `/commands/claim`, `/commands/{}/result`). Le gateway Python est un **process unique = une seule IP source**. Les 4 endpoints **partagent le même seau de 600 req/min = 10 req/s combinées**.

**Chiffrage :**
- Le seul **polling** consomme `N bornes × 0.667 req/s` (intervalle 1.5s)
- **10 ÷ 0.667 ≈ 15 bornes saturent le seau** avant même de compter les événements OCPP
- Au-delà → Laravel 429 → le gateway traite ça comme une `GatewayApiError` → **échec silencieux** des `StatusNotification`, `StartTransaction`, `MeterValues`

**Seuil de rupture :** **15 bornes.**

**Correctif (effort trivial, gain massif) :**
L'endpoint est déjà authentifié par signature HMAC (`VerifyOcppGatewaySignature`). **Retirer le `throttle` pour ce pair interne de confiance**, ou le porter à un ordre de grandeur cohérent (ex. 10 000/min) et **clé par borne** (`->by('ocpp:'.$stationIdentity)`) plutôt que par IP.

---

### 2. Modèle de polling OCPP : 1 tâche/borne qui interroge le backend en boucle [OCPP/Realtime]

**Fichiers :** `ocpp-gateway/src/chargetrackr_ocpp/server.py:21-64`, `api_client.py:77-95`

Chaque borne effectue un `POST /commands/claim` toutes les ~1.5s **en permanence**, que des commandes existent ou non → **~100 % de polls vides**.

**Chiffrage req/s vers Laravel (polling seul) :**

| N bornes | req/s (poll 1.5s) | req/s (plancher 0.5s) |
|----------|-------------------|-----------------------|
| 100      | 67                | 200                   |
| 1 000    | 667               | 2 000                 |
| 5 000    | 3 333             | 10 000                |

Chaque `/commands/claim` déclenche un `DB::transaction` + `Station ... lockForUpdate` + requête `ocpp_commands` → **transactions verrouillantes sur PostgreSQL à vide**.

**Seuil de rupture (indépendamment du limiter #1) :** PostgreSQL + php-fpm encaissent difficilement > ~500-1000 req/s de transactions verrouillantes → **mur vers 1 000-1 500 bornes**.

**Correctif (effort moyen, gain x15-30) :**
- **Long-poll :** `/commands/claim` maintient la requête ouverte jusqu'à ~25s, renvoyant immédiatement dès qu'une commande arrive (LISTEN/NOTIFY Postgres ou Redis blpop) → **divise le débit par 15-30×**
- **Push :** canal Redis pub/sub par borne, ou WebSocket backend→gateway → **débit ≈ 0 en régime nominal**

---

### 3. Drivers en `database` : cache/queue/session sur PostgreSQL [Cache/Queues]

**Config actuelle :** `.env` → `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`

**Problème :** tout le **broadcasting** (événements `ShouldBroadcast` : disponibilité, sessions, commandes) passe par la file `database` → **3-4 écritures PostgreSQL par diffusion** (INSERT + SELECT FOR UPDATE + UPDATE + DELETE). Une session de charge active génère un broadcast `ChargingSessionChanged` par MeterValues.

**Seuil de rupture :** la file `database` tient jusqu'à ~50-100 jobs/s. À **500-1 000 sessions simultanées** (MeterValues toutes les 10-60s), on dépasse ce seuil **uniquement pour la diffusion**, avant les jobs métier (emails, captures). Contention sur la table `jobs` unique.

**Correctif (effort faible, gain structurel) :**
Basculer `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis` (predis déjà installé). Définir des queues dédiées (`payments`, `emails`, `broadcasts`), passer en `queue:work` multi-workers (Horizon). Débloque aussi le scaling horizontal de Reverb (`REVERB_SCALING_ENABLED=true`).

---

### 4. Images frontend non optimisées : ~31 Mo de PNG + favicon 858 Ko [Frontend]

**Fichiers :** `frontend/public/assets/*.png` (14 images de 1.6-2.5 Mo chacune), `index.html:5` (favicon 858 Ko), `LandingPage.tsx:28-71`

La landing page publique référence à elle seule **~10 Mo d'images PNG** (`charge-hero.png` 2 Mo, `ev-charging-hub.png` 2.3 Mo × 2, `ev-operations-desk.png` 1.7 Mo, etc.). Aucune balise `<img>` n'a `loading="lazy"` ni format WebP/AVIF, aucun `srcSet` responsive. Le favicon de **858 Ko** est chargé dans le `<head>` de toutes les pages (un `favicon.svg` de 9.5 Ko existe déjà dans `dist/` mais n'est pas utilisé).

**Conséquence :** LCP/TTI catastrophiques sur `/` (première impression) — bien avant le JS. C'est de loin le **premier poste de latence du projet**.

**Correctif (effort moyen, impact maximal) :**
1. **Favicon** (1 ligne) : remplacer par le `favicon.svg` existant → **-858 Ko immédiat**
2. **Images** : convertir en WebP/AVIF (gain typique 85-95 %, un PNG 2.3 Mo → ~120-200 Ko), redimensionner aux dimensions d'affichage réelles, `loading="lazy"` sous la ligne de flottaison, `srcSet` → **~31 Mo → 1-2 Mo**

---

## 🟠 IMPACT ÉLEVÉ (bloquants à moyen terme)

### 5. Index manquants sur foreign keys [DB]

PostgreSQL **ne crée pas automatiquement** d'index sur les clés étrangères. Chaque filtre/join sur une FK non indexée = scan séquentiel.

**FK critiques sans index (vérifiées dans les migrations) :**
- `ocpp_events.organization_id` — filtré par `DashboardService.php:72`
- `payment_provider_events.organization_id`
- `availability_transitions.organization_id` — filtré par `AvailabilityMetrics.php:70`
- `platform_audit_logs.actor_id`
- `charging_attempts.organization_id`
- `ocpp_commands.organization_id`
- `user_notifications.organization_id`

**Volume critique :** à partir de **1 000+ sessions/jour, 50+ stations**

**Correctif (effort 1h, impact élevé) :**
```sql
CREATE INDEX idx_ocpp_events_organization_id ON ocpp_events(organization_id);
CREATE INDEX idx_availability_transitions_organization_id ON availability_transitions(organization_id);
CREATE INDEX idx_payment_provider_events_organization_id ON payment_provider_events(organization_id);
CREATE INDEX idx_platform_audit_logs_actor_id ON platform_audit_logs(actor_id);
CREATE INDEX idx_charging_attempts_organization_id ON charging_attempts(organization_id);
CREATE INDEX idx_ocpp_commands_organization_id ON ocpp_commands(organization_id);
CREATE INDEX idx_user_notifications_organization_id ON user_notifications(organization_id);
```

---

### 6. Listings non paginés : `->get()` sans limite [DB]

**Fichiers :**
- `AlertController::index:63` → `$alerts = $scope->...->get()` (charge toutes les alerts)
- `ChargingSessionController::index:38` → `->get()` (toutes les sessions)
- `PaymentController::index:34` → `->get()` (tous les paiements)
- `StationController::map:119` → `limit(1000)` hardcodé, pas de pagination pour > 1000 stations

**Conséquence :** avec **1 000+ enregistrements**, timeout + explosion mémoire.

**Correctif (effort 2h) :**
```php
$alerts = $scope->with(self::RELATIONS)->...->paginate(25);
// Idem pour sessions, payments
```

---

### 7. Agrégations dashboard recalculées à chaque hit sans cache [DB + Cache/Queues]

**Fichier :** `backend/app/Services/Dashboard/AvailabilityMetrics.php:18-129`

`AvailabilityMetrics::calculate` charge **toutes les transitions de toutes les stations jusqu'à `$periodEnd`** (ligne 70-76), **sans borne inférieure** → boucle PHP imbriquée sur stations × transitions. Appelé dans **chaque** requête dashboard (super_admin, admin, operator). Pour 100 stations × 30 jours × 50 transitions/jour = **150 000 transitions en mémoire**.

**Seuil de rupture :** à quelques centaines de stations sur 6-12 mois, un seul dashboard charge **des centaines de milliers de lignes** → OOM.

**Correctif (effort faible, impact élevé) :**
1. **Borner la requête** : `where('occurred_at', '>=', $periodStart)` (ajouter la borne inférieure)
2. **Cache Redis 30-60s** :
```php
$availability = Cache::remember(
    "dashboard:{$role}:availability:{$organizationId}:{$period['key']}", 
    300,
    fn() => $this->availabilityMetrics->calculate($stations, $period['start'], $period['end'])
);
```
3. **Table pré-agrégée** (meilleur) : `availability_metrics_hourly` alimentée par job quotidien

---

### 8. Recalcul de disponibilité à chaque événement OCPP + écriture inconditionnelle [OCPP/Realtime]

**Fichier :** `backend/app/Services/Availability/AvailabilityProjectionService.php:36-112`

Après **chaque** événement OCPP (y compris Heartbeat et MeterValues), `availabilityProjector->project()` :
- `lockForUpdate` sur station + tous ses connecteurs (2ᵉ transaction verrouillante)
- `$connector->update([...])` **systématiquement**, même sans changement
- `$station->update([...])` systématiquement
- `syncStationAlertCount` fait un `COUNT(*)` sur alerts + update **à chaque fois**

**Coût par événement :** ~**C+2 écritures + 1 COUNT(\*)**, en plus des ~3 écritures de l'ingestion.

**Correctif :**
1. N'écrire les connecteurs/station **que si `changed`**
2. Court-circuiter la projection pour Heartbeat/MeterValues (ne peuvent pas changer la disponibilité)
3. Sortir le `COUNT(*)` du chemin chaud (compteur incrémental)

---

### 9. MeterValues : fréquence élevée + coût unitaire lourd [OCPP/Realtime]

Par événement MeterValues : **3 transactions séquentielles + ~10-15 écritures + 1 broadcast**

**Chiffrage (S sessions, MeterValues toutes les T secondes) :**

| S sessions | T   | événements/s | écritures DB/s (~12) | broadcasts/s |
|------------|-----|--------------|----------------------|--------------|
| 100        | 30  | 3.3          | ~40                  | 3.3          |
| 500        | 10  | 50           | ~600                 | 50           |
| 500        | 3   | 167          | ~2 000               | 167          |

**Correctif :**
1. INSERT groupé des samples (un seul `insert()` multi-lignes)
2. **Débouncer le broadcast** de session (max 1 toutes les 10-15s par session — l'UI n'a pas besoin de chaque tick)
3. Supprimer le recalcul de disponibilité sur MeterValues (#8)

---

### 10. Cron `availability:refresh` recalcule TOUTES les stations toutes les 30s [Cache/Queues]

**Fichier :** `backend/routes/console.php:18-20`, `RefreshStationAvailability.php:33-38`

Balayage séquentiel de toutes les stations toutes les 30s, chaque `project()` = 2 transactions verrouillantes + écriture tous connecteurs + station + COUNT alertes, **à vide**.

**Charge permanente :**

| N stations | projections/30s | ≈ projections/s | ≈ écritures/s (C+2) |
|------------|-----------------|-----------------|---------------------|
| 1 000      | 1 000           | 33              | ~130                |
| 10 000     | 10 000          | 333             | ~1 300              |

Exécution **séquentielle** : si `project()` ≈ 20ms, une passe dépasse 30s vers **~1 500 stations** → `withoutOverlapping(2)` fait sauter les passes.

**Correctif :**
Ne rafraîchir que les stations dont la fraîcheur a pu basculer (requête ciblée sur `ocpp_last_message_at < now() - timeout`), étaler le travail, rendre `project()` no-op sans écriture quand rien ne change.

---

### 11. Bundle frontend initial (index-*.js) = 752 Ko, incluant du code inutile aux invités [Frontend]

Le chunk d'entrée contient React/Router/Query + Ant Design + **tout ce qui est importé statiquement** dans `AppRouter.tsx:11-21`, dont `AppLayout` → `AvailabilityRealtimeSync` → `features/realtime/echo.ts` qui charge **`laravel-echo` + `pusher-js` statiquement**. Un visiteur sur la landing publique télécharge le moteur temps réel Pusher/Echo dont il n'a aucun usage.

**Correctif :**
Passer `AppLayout` (ou au minimum `AvailabilityRealtimeSync`/`echo`) en `React.lazy` / import dynamique → Pusher/Echo chargés uniquement après login.

---

### 12. Listes de stations sans pagination ni virtualisation + carte sans clustering [Frontend]

**Fichiers :** `stationApi.ts:26` (pas de paramètres de pagination), `StationsPage.tsx:283` (`pagination={false}`), `StationMap.tsx:28` (un marqueur + Popup monté par station, **sans clustering**)

**Conséquence :** avec quelques centaines/milliers de stations, blocage du thread principal au rendu, scroll saccadé, carte qui rame. Invisible en démo, bloquant en production.

**Correctif :**
- Pagination côté serveur (`page`/`per_page`)
- Virtualisation pour table (`virtual` d'antd Table 6, ou `react-window`)
- Clustering des marqueurs Leaflet (`react-leaflet-cluster`)
- Monter les `<Popup>` seulement à l'ouverture

---

## 🟡 IMPACT MOYEN

### 13. Renouvellements d'abonnement en série dans le scheduler [Cache/Queues]

`PlanSubscriptionService::scan()` parcourt toutes les souscriptions à échéance et effectue un **appel HTTP bloquant** au gateway **en série**, dans le processus du scheduler. À **1 200 renouvellements** dans la même heure + gateway lent, le tick peut approcher **~60 min** → retard de facturation.

**Correctif :** transformer chaque renouvellement en **job en file** avec batch, tries/backoff.

---

### 14. Autorisation de paiement synchrone dans le cycle requête [Cache/Queues]

`ChargingAttemptService.php:68` fait `$this->payments->authorize(...)` (appel HTTP, jusqu'à 3s) **dans la requête** de démarrage de session → latence perçue + chaque démarrage monopolise un worker PHP-FPM pendant l'I/O. Couplage disponibilité API ↔ latence gateway.

**Correctif :** déplacer `authorize()` vers un job, statut `pending`, suivi via broadcast.

---

### 15. `notifications:check-sla` chaque minute sur un ensemble non borné [Cache/Queues]

`NotificationSlaService::scan()` charge **toutes** les alertes non résolues échues, **sans borne temporelle basse**. Pour chacune, itère sur toutes les parties prenantes → N×M `firstOrCreate` chaque minute, la plupart ne produisant rien.

**Correctif :** marquer les alertes notifiées (`sla_notified_at`) et filtrer `whereNull('sla_notified_at')` → ensemble borné aux nouvelles échéances.

---

### 16. Un seul worker, `--tries=1`, `--timeout=0` [Cache/Queues]

`composer.json` script `dev` → `queue:listen --tries=1 --timeout=0`

- `queue:listen` recharge le framework à **chaque** job (lent)
- `--tries=1` = **aucun retry** (les jobs définissent pourtant `backoff`, ignoré)
- `--timeout=0` = un job bloqué peut **geler le worker indéfiniment**
- Aucune config de production (ni Horizon, ni Supervisor)

**Correctif :** en production, `queue:work` (pas `listen`), plusieurs workers/queues dédiées, `--tries=3 --backoff --timeout=30`, sous Supervisor ou **Horizon** (nécessite Redis).

---

### 17. Gateway Python mono-process, pool httpx par défaut [OCPP/Realtime]

`server.py:165-191` : `asyncio.run(main())` avec un seul `serve()` → **une seule boucle d'événements sur un seul cœur**. `api_client.py:28-31` : `httpx.AsyncClient` **sans `limits`** → défauts (`max_connections=100`) partagés par toutes les bornes → sérialisation cachée sous charge.

**Correctif :** plusieurs process gateway derrière LB (sharding par hash d'identité), dimensionner `httpx.Limits`.

---

### 18. Reverb mono-process + canaux firehose super-admin [OCPP/Realtime]

`REVERB_SCALING_ENABLED=false` → **un seul process Reverb**. Canaux problématiques :
- `stations.super-admin` et `stations.public` → un socket super-admin reçoit **tout changement de disponibilité de toute la plateforme**
- `sessions.super-admin` → reçoit **chaque tick MeterValues de chaque session** (50-167 msg/s)

**Correctif :** activer le scaling Redis + plusieurs nœuds ; remplacer les canaux firehose par un canal agrégé/résumé (throttle + synthèse).

---

### 19. Thundering herd + bcrypt à chaque connexion [OCPP/Realtime]

`OcppStationAuthenticationService.php:22-24` : `Hash::check` (**bcrypt, ~50-100 ms**) à chaque connexion, **sans cache**. Au redémarrage : N bornes se reconnectent simultanément → rafale de 2N requêtes → dépasse le limiter (#1) + sature le CPU php-fpm.

**Correctif :** cacher l'authentification (hash mémorisé quelques minutes), jitter/accept staggering côté gateway.

---

### 20. Index fonctionnels manquants pour la recherche globale [DB]

`GlobalSearchService.php` : `whereRaw('LOWER(name) LIKE ?', [$needle])` (wildcard en tête) → scan séquentiel sur chaque table. Non caché.

**Correctif :** index trigram PostgreSQL (`pg_trgm` + `GIN`) ou tsvector full-text, debounce côté front.

---

## 🟢 IMPACT FAIBLE (mais à surveiller)

- `index.css` monolithique 300 Ko bloquant le premier rendu
- Deux librairies d'icônes (`@ant-design/icons` + `lucide-react`)
- Polling dashboard (30s) re-rendant tous les graphiques
- Leaflet CSS global chargé sur login/landing
- Colonnes/tableaux définis au render sans memoization
- Recherche globale sans debounce
- Sessions en `database`
- `ocpp:expire-commands` toutes les 10s (correctement borné, à surveiller)
- Croissance non bornée : tables `ocpp_events`, `ocpp_meter_samples`, `availability_transitions`, `platform_audit_logs`, `user_notifications` sans purge ni archivage
- Eager-load de relations inutiles à chaque broadcast

---

## 📊 Synthèse — seuils de rupture chiffrés

| Composant | Seuil de rupture ACTUEL | Après correctifs prioritaires |
|-----------|-------------------------|-------------------------------|
| **OCPP polling** | ~15 bornes (rate limiter) | **10 000+ bornes** (long-poll + limiter retiré) |
| **DB transactions** | ~1 000-1 500 bornes (polling) | **5 000+ bornes** (long-poll) |
| **File broadcasts** | ~500-1 000 sessions (database) | **5 000+ sessions** (Redis) |
| **Dashboard** | 100 stations × 6 mois (OOM) | **1 000+ stations × plusieurs années** (borné + cache) |
| **Frontend initial** | ~32 Mo assets (LCP 10-20s) | **~2 Mo** (WebP + lazy) |
| **Listing stations** | ~1 000 stations (freeze UI) | **Illimité** (pagination + virtualisation) |

---

## 🎯 Top 10 actions — ratio impact/effort

| # | Action | Impact | Effort | Fichiers clés |
|---|--------|--------|--------|---------------|
| **1** | Retirer/assouplir le rate limiter `throttle:ocpp-gateway` | 🔴 Critique | Trivial | `AppServiceProvider.php:63` |
| **2** | Remplacer `favicon.png` (858 Ko) par `favicon.svg` existant (9.5 Ko) | 🔴 Élevé | Trivial (1 ligne) | `index.html:5` |
| **3** | Ajouter index sur `organization_id` des tables événementielles | 🟠 Élevé | Faible (1h) | 7 migrations à créer |
| **4** | Basculer queue + broadcast sur Redis, `queue:work` multi-workers (Horizon) | 🔴 Critique | Faible | `.env`, Events, `composer.json` |
| **5** | Borner `AvailabilityMetrics::transitions()` + cache Redis 30-60s sur dashboard | 🔴 Élevé | Faible | `AvailabilityMetrics.php:72`, `DashboardService.php` |
| **6** | Optimiser images : WebP/AVIF + redimensionnement + `loading="lazy"` | 🔴 Très élevé | Moyen | `public/assets/*`, `LandingPage.tsx` |
| **7** | Paginer Alert/Session/Payment controllers | 🟠 Élevé | Faible (2h) | 3 controllers `index` |
| **8** | Passer polling OCPP en long-poll (LISTEN/NOTIFY ou Redis) | 🔴 Critique | Moyen | `server.py`, `api_client.py`, `OcppCommandService.php` |
| **9** | Alléger projection availability : n'écrire que si `changed`, débouncer broadcasts MeterValues | 🟠 Élevé | Moyen | `AvailabilityProjectionService.php`, `ChargingSessionService.php:241` |
| **10** | Lazy-loader `AppLayout` + realtime (Pusher/Echo) pour sortir du bundle initial guest | 🟠 Élevé | Faible | `AppRouter.tsx:10`, `features/realtime/echo.ts` |

**Actions bonus faible effort :**
- Pagination serveur + clustering Leaflet + virtualisation table stations
- Réduire `availability:refresh` à `everyFiveMinutes` ciblé sur timeouts
- Marquer SLA traitées (`sla_notified_at`) pour borner le scan
- Cacher l'auth OCPP + jitter reconnexions

---

## Conclusion

Les **4 goulots dominants** sont tous **externes à la logique métier** :
1. **Rate limiter trop restrictif** (config 1 ligne)
2. **Modèle de polling** (architecture OCPP)
3. **Drivers database** (config + workers)
4. **Assets frontend non optimisés** (tooling build)

La qualité du code applicatif est **solide** ; les problèmes de scalabilité sont des **choix d'infrastructure et de configuration** facilement corrigeables. Les correctifs proposés (top 10) permettront de tenir **10 000+ bornes** et **quelques centaines de sessions simultanées** sans refonte, pour un effort cumulé de **~2-3 semaines**.
