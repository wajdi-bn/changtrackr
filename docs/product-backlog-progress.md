# Avancement et traçabilité du backlog produit

État mis à jour le 20 juillet 2026 à partir du code, des routes, des interfaces et des tests présents dans le dépôt. Une page statique ou des données fictives ne suffisent pas pour classer une User Story comme réalisée.

## Légende

- **Réalisée** : le workflow principal est disponible de bout en bout, protégé et couvert par des tests adaptés.
- **Partielle** : une partie fonctionnelle existe, mais au moins un critère majeur manque ou repose encore sur des données simulées/statiques.
- **À faire** : aucune implémentation métier exploitable n'est présente ; une route ou une page placeholder ne change pas ce statut.
- **Reportée** : fonctionnalité volontairement placée hors du périmètre immédiat.

## Synthèse

| Statut | User Stories | Points | Part des points |
|---|---:|---:|---:|
| Réalisée | 22 | 157 | 54,5 % |
| Partielle | 12 | 87 | 30,2 % |
| À faire | 5 | 31 | 10,8 % |
| Reportée | 1 | 13 | 4,5 % |
| **Total** | **40** | **288** | **100 %** |

La part des points n'est pas un pourcentage d'achèvement linéaire : une User Story partielle peut encore contenir son travail le plus risqué, notamment OCPP, cartographie ou temps réel.

## Matrice de traçabilité

| ID | Pts | Statut | Preuves actuelles | Écart principal |
|---|---:|---|---|---|
| US-01 | 8 | Réalisée | Session Sanctum/CSRF, inscription client, vérification email en file, récupération de mot de passe, OAuth2 Google, liaison `social_accounts` et tests de sécurité | Microsoft est hors périmètre du MVP actuel |
| US-02 | 3 | Partielle | Profil retourné par `UserResource`, champs présents dans `users` | Page profil statique, aucune API de mise à jour ou changement de mot de passe |
| US-03 | 8 | Partielle | Une demande approuvée provisionne atomiquement une organisation d'essai, un administrateur en attente et une invitation sécurisée révocable/renouvelable ; isolation des rôles validée | Le CRUD général des organisations et la gestion de leur cycle de vie restent absents |
| US-04 | 5 | Réalisée | L'administrateur invite uniquement des opérateurs/techniciens de son organisation ; compte en attente sans mot de passe initial, email en file, activation à usage unique, rappel avec rotation, annulation, renouvellement, statuts contextuels, rate limiting, policies et tests multi-tenant | Les changements de mot de passe restent volontairement un workflow personnel et non une action administrateur |
| US-05 | 5 | Partielle | Dashboard global réel pour organisations, utilisateurs, bornes, disponibilité suivie, sessions, énergie, revenus et classement des organisations | Journal d'audit global, filtres d'audit et état de santé détaillé des intégrations encore absents |
| US-06 | 5 | Réalisée | `CustomerController`, `CustomersPage`, `CustomerManagementApiTest` | Les exports avancés restent couverts par US-34 |
| US-07 | 8 | Réalisée | `StationController`, `StationsPage`, formulaire Ant Design, `StationApiTest` | La désactivation pourra être distinguée d'une suppression logique |
| US-08 | 5 | Réalisée | `ConnectorController`, gestion dans `StationDetailPage`, tests d'appartenance borne-connecteur et 18 connecteurs alimentés par OCPP | La compatibilité physique devra être validée sur du matériel réel |
| US-09 | 8 | Réalisée | Liste, recherche, filtres, métriques et disponibilité calculée dans `StationController`, `StationsPage` et le moteur de projection OCPP | Les KPI historiques avancés restent rattachés aux dashboards et rapports |
| US-10 | 8 | Réalisée | Carte React Leaflet réelle, marqueurs filtrables, sélection géographique et saisie manuelle de secours | Un fournisseur de tuiles dédié sera requis avant une charge de production |
| US-11 | 5 | Réalisée | Le technicien consulte les bornes et la carte de son organisation en lecture seule | Les actions techniques distantes relèvent de la suite d'US-14 |
| US-12 | 8 | Réalisée | Recherche des bornes disponibles, vues cartes/carte, géolocalisation, tri par distance, disponibilité des connecteurs, copie des coordonnées, itinéraire Google Maps et démarrage guidé | Les filtres tarifaires avancés pourront enrichir la recherche sans bloquer le workflow principal |
| US-13 | 8 | Partielle | Fiche borne, aperçu, CRUD connecteurs, historique des commandes et calendrier de maintenance alimentés par API | Onglets sessions, alertes et documents encore statiques |
| US-14 | 13 | Réalisée | Gateway Python OCPP 1.6 JSON, flotte SAP de 9 bornes et 18 connecteurs, authentification, ingestion HMAC idempotente, projections, transactions, mesures, démarrage/arrêt distants, Soft Reset, déverrouillage, maintenance synchronisée et historique temps réel | La validation sur borne physique et OCPP 2.0.1 restent hors du périmètre actuel |
| US-15 | 13 | Réalisée | Matrice centralisée, priorité des overrides, projection par connecteur, contrôle 30/90 secondes, historique des transitions et protection contre les événements tardifs couverts par tests | Les seuils devront être recalibrés avec une borne physique si elle devient disponible |
| US-16 | 13 | Réalisée | États calculés exposés par REST et actualisés via Reverb dans les listes, cartes, détails et alertes ; statuts OCPP non éditables manuellement | La validation de charge Reverb appartient à la phase de tests non fonctionnels |
| US-17 | 8 | Réalisée | API et dashboard opérateur réels avec périodes 7/30/90 jours, disponibilité reconstruite depuis les transitions, indisponibilité, sessions, énergie, alertes, carte, événements et rafraîchissement Reverb/30 s | Les futurs rapports pourront réutiliser les mêmes agrégations |
| US-18 | 8 | Réalisée | Dashboard administrateur isolé par organisation avec employés, clients, disponibilité, revenus, carte, activité et classements clients/opérateurs/techniciens/bornes/régions ; formules et période affichées | Les formules pourront devenir configurables après retour métier sans modifier le contrat d'isolation |
| US-19 | 8 | Réalisée | Alertes automatiques de perte de communication et de panne connecteur, déduplication, réouverture/résolution, historique, destinataires autorisés, notifications et échéances SLA | Les seuils pourront être recalibrés avec les mesures d'exploitation réelles |
| US-20 | 5 | Réalisée | Filtres, statuts, affectation, acquittement, annulation traçable, chronologie, SLA, policies et tests inter-organisations | Les délais par gravité pourront devenir configurables dans les paramètres d'organisation |
| US-21 | 5 | Réalisée | Alertes/interventions assignées, transitions contrôlées, vues technicien, notifications personnelles et tests | Les préférences de canal restent rattachées à US-40 |
| US-22 | 8 | Réalisée | Assistant en quatre étapes, diagnostic, actions, pièces, contrôles terrain, preuves privées avant/après, résultat final, rapport immuable, réouverture pour suivi et tests d'isolation | La génération PDF appartient à US-33/US-34 |
| US-23 | 8 | Réalisée | Plans préventifs/correctifs, affectation, replanification, calendrier, occurrences récurrentes idempotentes en file, transitions auditées, rapport terrain et synchronisation OCPP du mode maintenance | La validation sur une borne physique reste ultérieure |
| US-24 | 8 | Réalisée | Assistant client en cinq étapes, préautorisation, idTag virtuel, `RemoteStartTransaction`, création de session uniquement après `StartTransaction`, QR par connecteur, progression temps réel et tests | Les vidéos physiques seront ajoutées après validation de leur contenu |
| US-25 | 5 | Réalisée | Arrêt client distant, arrêt automatique par limite énergie/montant/durée, `RemoteStopTransaction`, mesures provisoires puis finales, interruption et réconciliation tardive | Les seuils devront être validés avec le prestataire de paiement et une borne physique |
| US-26 | 5 | Réalisée | `ChargingSessionController`, `SessionsPage`, scoping client/organisation, export CSV/JSON et tests | Les filtres temporels avancés pourront être ajoutés avec les rapports |
| US-27 | 5 | À faire | Route et page placeholder uniquement | Modèle, API, formulaire et compatibilité connecteurs absents |
| US-28 | 8 | Partielle | Modèle de jeton OCPP rattaché au client, stockage du hash uniquement, idTag virtuel généré pour le démarrage distant, QR d'entrée sans secret et réponse `Authorize` couverts par tests | Gestion RFID physique, rotation/révocation dans l'interface et lecture matérielle absentes |
| US-29 | 8 | Partielle | Préautorisation simulée de 30 TND, capture automatique du montant mesuré, libération en cas d'échec, statuts, idempotence, UI et tests | Génération et téléchargement de facture absents |
| US-30 | 8 | Réalisée | Contrat `PaymentGateway` extensible, pilote mémoire pour les tests, adaptateur HTTP WireMock, autorisation/capture/libération/paiement, erreurs réseau, webhooks HMAC idempotents, journal fournisseur et méthodes carte/e-DINAR/D17 simulées | Le branchement à un prestataire financier réel reste hors du MVP |
| US-31 | 8 | Partielle | CRUD tarifs/plans, affectation connecteur-borne, résolution, snapshot et simulation | Plages horaires et coût temporel effectif non implémentés |
| US-32 | 8 | Réalisée | Catalogue multi-organisations, changement/annulation, remise et tests | Paiement récurrent réel hors MVP actuel |
| US-33 | 8 | À faire | Page placeholder uniquement | API d'agrégation et génération de rapports absentes |
| US-34 | 5 | Partielle | Exports CSV/JSON des employés, clients, sessions et paiements avec permissions et isolation organisationnelle | PDF, Excel et exports des autres modules absents |
| US-35 | 5 | À faire | Page paramètres placeholder | Modèle de paramètres organisationnels et API absents |
| US-36 | 8 | À faire | Page intégrations placeholder et configuration serveur dispersée | Console globale, tests de santé et audit absents |
| US-37 | 5 | À faire | Onglet documents statique dans la fiche borne | Stockage, métadonnées, versions et autorisations absents |
| US-38 | 13 | Reportée | Version OCPP déclarative dans les bornes uniquement | Déploiement firmware à traiter après stabilisation OCPP et test matériel |
| US-39 | 3 | Réalisée | Formulaire public persisté, validation, consentement, honeypot, limitation de débit, console Super Admin, workflow de qualification et provisionnement testés | La conversion est volontairement réservée au Super Admin |
| US-40 | 5 | Partielle | Notifications in-app personnelles persistées, compteur non lu, lecture individuelle/globale, temps réel Reverb, emails en file avec suivi des livraisons et retries, isolation inter-organisations et canaux métier pour alertes, assignations, maintenance, SLA et paiements | L'écran et le modèle de préférences permettant de désactiver les notifications non obligatoires restent à réaliser |

## Dépendances fonctionnelles

| Fondation | Dépendances directes | Raison |
|---|---|---|
| US-01 et US-02 | US-27, US-28, US-32, US-40 | Les fonctionnalités personnelles nécessitent une identité et un profil fiables |
| US-03 et US-04 | US-05, US-06, US-18, US-35, US-36 | Les périmètres administratifs dépendent des organisations et rôles |
| US-07 et US-08 | US-09 à US-16, US-19, US-23, US-24, US-31, US-37, US-38 | Les bornes/connecteurs sont les agrégats centraux du domaine |
| US-10 | US-11 et US-12 | Les vues cartographiques partagent fournisseur, composants et géodonnées |
| US-14 | US-15, US-19, US-24, US-25, US-28, US-38 | Les événements et commandes réels transitent par le gateway OCPP |
| US-15 | US-09, US-16, US-17, US-19, US-23 | La disponibilité calculée alimente supervision, KPI et alertes |
| US-20 et US-21 | US-22 et US-23 | Le workflow d'intervention précède le rapport et la maintenance complète |
| US-30 et US-31 | US-29 et US-32 | Paiements et abonnements reposent sur le contrat de paiement et le calcul tarifaire |
| US-17, US-18 et US-33 | US-34 | Les exports doivent réutiliser les mêmes agrégations et filtres |
| US-40 | US-19, US-21, US-34, US-39 | Alertes, assignations, exports asynchrones et demandes de démo doivent notifier |

## Ordre d'implémentation recommandé

1. **Entrée et identité** - US-01 et US-39 sont réalisées ; finaliser plus tard US-02 et US-03, puis ajouter les préférences personnelles de US-40.
2. **Cartographie** - US-10 à US-12 partagent désormais la même carte, la géolocalisation et les actions d'itinéraire ; le fournisseur de tuiles de production reste à choisir.
3. **Communication OCPP** - neuf bornes simulées, disponibilité, autorisation, transactions, mesures, démarrage/arrêt distants et commandes de supervision sont intégrés ; la prochaine validation OCPP significative nécessitera une borne physique.
4. **Disponibilité calculée** - définir la matrice métier puis implémenter US-15 avant de terminer US-09 et US-16.
5. **Alertes automatiques et temps réel** - US-19 est réalisée et reliée aux notifications personnelles de US-40 ; les préférences de canal restent à ajouter dans les paramètres personnels.
6. **Sessions réelles** - US-24 et US-25 couvrent le parcours client, la préautorisation, le démarrage/arrêt distants, les limites et la capture finale ; valider ensuite le scénario Docker puis matériel.
7. **Maintenance** - US-22 et US-23 couvrent désormais le workflow terrain, les preuves privées, le rapport final, la planification, la récurrence, le calendrier et la disponibilité ; préciser ensuite les SLA et notifications.
8. **Tarification et facturation** - le simulateur externe et les webhooks sont intégrés ; terminer US-31 et la facture de US-29 avant un éventuel prestataire financier réel.
9. **Dashboards et rapports** - US-17 et US-18 sont réalisées avec des agrégations communes ; implémenter maintenant US-33 puis généraliser US-34 en réutilisant ces calculs.
10. **Modules complémentaires** - réaliser US-27, US-28, US-35, US-36 et US-37 selon la priorité métier.
11. **Firmware** - conserver US-38 reportée jusqu'à la stabilisation d'OCPP et la disponibilité d'une procédure fournisseur ou d'une borne de test.

## Décisions externes encore nécessaires

- validation de la matrice de disponibilité, des seuils Heartbeat et de la priorité des états ;
- choix du fournisseur cartographique et obtention éventuelle d'une clé ;
- identifiants OAuth2 Microsoft uniquement si cette intégration sort du report ;
- adresses et paramètres du service email de production ;
- règles de TVA, facturation et numérotation des factures ;
- prestataire de paiement réel et accès sandbox, seulement au moment de son intégration ;
- formats et stockage de production à retenir pour les documents et firmwares ;
- accès à une borne physique uniquement pour la phase de validation matérielle.
