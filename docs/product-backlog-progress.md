# Avancement et traçabilité du backlog produit

État mis à jour le 28 juillet 2026 à partir du code, des routes, des interfaces et des tests présents dans le dépôt. Une page statique ou des données fictives ne suffisent pas pour classer une User Story comme réalisée.

## Légende

- **Réalisée** : le workflow principal est disponible de bout en bout, protégé et couvert par des tests adaptés.
- **Partielle** : une partie fonctionnelle existe, mais au moins un critère majeur manque ou repose encore sur des données simulées/statiques.
- **À faire** : aucune implémentation métier exploitable n'est présente ; une route ou une page placeholder ne change pas ce statut.
- **Reportée** : fonctionnalité volontairement placée hors du périmètre immédiat.

## Synthèse

| Statut | User Stories | Points | Part des points |
|---|---:|---:|---:|
| Réalisée | 37 | 262 | 87,04 % |
| Partielle | 4 | 26 | 8,64 % |
| À faire | 0 | 0 | 0 % |
| Reportée | 1 | 13 | 4,32 % |
| **Total** | **42** | **301** | **100 %** |

La part des points n'est pas un pourcentage d'achèvement linéaire : une User Story partielle peut encore contenir son travail le plus risqué, notamment OCPP, cartographie ou temps réel.

## Matrice de traçabilité

| ID | Pts | Statut | Preuves actuelles | Écart principal |
|---|---:|---|---|---|
| US-01 | 8 | Réalisée | Session Sanctum/CSRF, inscription client, vérification email en file, récupération de mot de passe, OAuth2 Google, liaison `social_accounts` et tests de sécurité | Microsoft est hors périmètre du MVP actuel |
| US-02 | 3 | Réalisée | Profil personnel modifiable, avatar, coordonnées, adresse, liens professionnels, préférences locales et changement de mot de passe pour les comptes locaux | Les comptes Google-only masquent volontairement le changement de mot de passe |
| US-03 | 8 | Réalisée | Provisionnement atomique depuis une demande de démonstration, CRUD et cycle de vie des organisations, administrateur initial, invitation sécurisée et isolation des rôles | La facturation réelle reste remplacée par le simulateur du MVP |
| US-04 | 5 | Réalisée | L'administrateur invite uniquement des opérateurs/techniciens de son organisation ; compte en attente sans mot de passe initial, email en file, activation à usage unique, rappel avec rotation, annulation, renouvellement, statuts contextuels, rate limiting, policies et tests multi-tenant | Les changements de mot de passe restent volontairement un workflow personnel et non une action administrateur |
| US-05 | 5 | Réalisée | Dashboard global, organisations, utilisateurs, santé OCPP, activité, audit, paramètres système et état des intégrations | Les métriques d'infrastructure de production seront ajoutées au déploiement |
| US-06 | 5 | Réalisée | `CustomerController`, `CustomersPage`, `CustomerManagementApiTest` | Les exports avancés restent couverts par US-34 |
| US-07 | 8 | Réalisée | `StationController`, `StationsPage`, formulaire Ant Design, `StationApiTest` | La désactivation pourra être distinguée d'une suppression logique |
| US-08 | 5 | Réalisée | `ConnectorController`, gestion dans `StationDetailPage`, tests d'appartenance borne-connecteur et 18 connecteurs alimentés par OCPP | La compatibilité physique devra être validée sur du matériel réel |
| US-09 | 8 | Réalisée | Liste, recherche, filtres, métriques et disponibilité calculée dans `StationController`, `StationsPage` et le moteur de projection OCPP | Les KPI historiques avancés restent rattachés aux dashboards et rapports |
| US-10 | 8 | Réalisée | Carte React Leaflet réelle, marqueurs filtrables, sélection géographique et saisie manuelle de secours | Un fournisseur de tuiles dédié sera requis avant une charge de production |
| US-11 | 5 | Réalisée | Le technicien consulte les bornes et la carte de son organisation en lecture seule | Les actions techniques distantes relèvent de la suite d'US-14 |
| US-12 | 8 | Réalisée | Recherche des bornes disponibles, vues cartes/carte, géolocalisation, tri par distance, disponibilité des connecteurs, copie des coordonnées, itinéraire Google Maps et démarrage guidé | Les filtres tarifaires avancés pourront enrichir la recherche sans bloquer le workflow principal |
| US-13 | 8 | Réalisée | Fiche borne alimentée par API avec vue opérationnelle, télémétrie, connecteurs, commandes, maintenances, sessions, alertes et documents autorisés | Les séries longues dépendront de la politique de rétention de production |
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
| US-27 | 5 | Réalisée | Onboarding personnalisé par rôle, première action utile, progression persistée, configuration d'organisation et conseils contextuels | Les conseils pourront être enrichis après tests utilisateurs |
| US-28 | 8 | Partielle | Modèle de jeton OCPP rattaché au client, stockage du hash uniquement, idTag virtuel généré pour le démarrage distant, QR d'entrée sans secret et réponse `Authorize` couverts par tests | Gestion RFID physique, rotation/révocation dans l'interface et lecture matérielle absentes |
| US-29 | 8 | Réalisée | Préautorisation, capture/libération, statuts, idempotence, interface de paiement et reçu PDF prévisualisable ou téléchargeable | Le prestataire financier réel reste hors du MVP |
| US-30 | 8 | Réalisée | Contrat `PaymentGateway` extensible, pilote mémoire pour les tests, adaptateur HTTP WireMock, autorisation/capture/libération/paiement, erreurs réseau, webhooks HMAC idempotents, journal fournisseur et méthodes carte/e-DINAR/D17 simulées | Le branchement à un prestataire financier réel reste hors du MVP |
| US-31 | 8 | Partielle | CRUD tarifs/plans, affectation connecteur-borne, résolution, snapshot et simulation | Plages horaires et coût temporel effectif non implémentés |
| US-32 | 8 | Réalisée | Catalogue multi-organisations, changement/annulation, remise et tests | Paiement récurrent réel hors MVP actuel |
| US-33 | 8 | Réalisée | Analyses distinctes par rôle, rapports opérationnels, composition, destinataires autorisés, pièces jointes, lecture, archivage et échange interne | Les résumés assistés par IA restent optionnels |
| US-34 | 5 | Partielle | Exports PDF, JSON et CSV avec périmètre d'autorisation, filtres et documents visuels pour analyses, opérations et paiements | Le basculement en tâche de fond au-delà d'un seuil de volume reste à implémenter |
| US-35 | 5 | Partielle | Identité visuelle de l'organisation, fuseau horaire personnel, rayon cartographique et préférences de notification sont exposés par API et interface | TVA, langue et fuseau propres à l'organisation ne sont pas encore appliqués à tous les traitements concernés |
| US-36 | 8 | Réalisée | Console Super Admin pour intégrations, paramètres globaux validés côté serveur, indicateurs de configuration et journalisation | Les secrets restent volontairement gérés hors du navigateur |
| US-37 | 5 | Réalisée | Documents de bornes et d'interventions stockés de manière privée avec métadonnées, contrôle d'accès, consultation et suppression | Le stockage objet externe sera choisi au déploiement |
| US-38 | 13 | Reportée | Version OCPP déclarative dans les bornes uniquement | Déploiement firmware à traiter après stabilisation OCPP et test matériel |
| US-39 | 3 | Réalisée | Formulaire public persisté, validation, consentement, honeypot, limitation de débit, console Super Admin, workflow de qualification et provisionnement testés | La conversion est volontairement réservée au Super Admin |
| US-40 | 5 | Réalisée | Notifications in-app et email, compteur et badges contextuels, lecture individuelle/globale, temps réel Reverb, retries, préférences par catégorie et isolation inter-organisations | Les canaux SMS/push mobile ne font pas partie du périmètre actuel |
| US-41 | 8 | Réalisée | Offres SaaS, essais, portefeuille des organisations, factures, prolongation, suspension, restauration, rappels et transitions planifiées | Le règlement reste simulé jusqu'au choix d'un prestataire réel |
| US-42 | 5 | Réalisée | Portail administrateur de suivi du plan, quotas, échéances, factures et demandes de changement d'offre | L'acceptation et le règlement final restent sous contrôle du Super Admin |

## Dépendances fonctionnelles

| Fondation | Dépendances directes | Raison |
|---|---|---|
| US-01 et US-02 | US-27, US-28, US-32, US-40 | Les fonctionnalités personnelles nécessitent une identité et un profil fiables |
| US-03 et US-04 | US-05, US-06, US-18, US-35, US-36, US-41, US-42 | Les périmètres administratifs et commerciaux dépendent des organisations et rôles |
| US-07 et US-08 | US-09 à US-16, US-19, US-23, US-24, US-31, US-37, US-38 | Les bornes/connecteurs sont les agrégats centraux du domaine |
| US-10 | US-11 et US-12 | Les vues cartographiques partagent fournisseur, composants et géodonnées |
| US-14 | US-15, US-19, US-24, US-25, US-28, US-38 | Les événements et commandes réels transitent par le gateway OCPP |
| US-15 | US-09, US-16, US-17, US-19, US-23 | La disponibilité calculée alimente supervision, KPI et alertes |
| US-20 et US-21 | US-22 et US-23 | Le workflow d'intervention précède le rapport et la maintenance complète |
| US-30 et US-31 | US-29, US-32, US-41 et US-42 | Paiements et abonnements reposent sur le contrat de paiement et le calcul tarifaire |
| US-17, US-18 et US-33 | US-34 | Les exports doivent réutiliser les mêmes agrégations et filtres |
| US-40 | US-19, US-21, US-34, US-39 | Alertes, assignations, exports asynchrones et demandes de démo doivent notifier |

## Ordre d'implémentation recommandé

1. **Entrée et identité** - authentification, demande de démonstration, invitations, profil, onboarding et préférences sont réalisés ; il reste à tester ces parcours sur les principaux navigateurs.
2. **Cartographie** - US-10 à US-12 partagent désormais la même carte, la géolocalisation et les actions d'itinéraire ; le fournisseur de tuiles de production reste à choisir.
3. **Communication OCPP** - neuf bornes simulées, disponibilité, autorisation, transactions, mesures, démarrage/arrêt distants et commandes de supervision sont intégrés ; la prochaine validation OCPP significative nécessitera une borne physique.
4. **Disponibilité calculée** - la matrice métier, les projections et l'affichage temps réel sont réalisés ; il reste à recalibrer les seuils avec une borne physique.
5. **Alertes automatiques et temps réel** - US-19 est réalisée et reliée aux notifications personnelles de US-40, y compris les préférences par catégorie.
6. **Sessions réelles** - US-24 et US-25 couvrent le parcours client, la préautorisation, le démarrage/arrêt distants, les limites et la capture finale ; valider ensuite le scénario Docker puis matériel.
7. **Maintenance** - les workflows terrain, preuves, rapports, planification, récurrence, calendrier et disponibilité sont réalisés ; il reste à valider les SLA avec l'encadrant.
8. **Tarification et facturation** - tarifs, reçus, abonnements clients et gestion commerciale des organisations sont intégrés ; les plages horaires de US-31 restent à compléter avant un éventuel prestataire financier réel.
9. **Dashboards et rapports** - les tableaux de bord par rôle, analyses, échanges internes, pièces jointes et exports sont réalisés ; leur ergonomie reste à consolider par recette.
10. **Modules complémentaires** - l'onboarding, les paramètres, intégrations et documents sont réalisés ; US-28 reste partielle tant qu'aucun badge RFID physique n'est testé.
11. **Industrialisation** - conteneurisation locale et CI GitHub réalisées ; déploiement, TLS, sauvegardes automatisées et supervision de production restent à préparer.
12. **Firmware** - conserver US-38 reportée jusqu'à la stabilisation d'OCPP et la disponibilité d'une procédure fournisseur ou d'une borne de test.

## Décisions externes encore nécessaires

- validation finale des seuils Heartbeat et de la priorité des états sur une borne physique ;
- choix du fournisseur cartographique et obtention éventuelle d'une clé ;
- identifiants OAuth2 Microsoft uniquement si cette intégration sort du report ;
- adresses et paramètres du service email de production ;
- validation métier des règles de TVA, de numérotation et de conservation des factures ;
- prestataire de paiement réel et accès sandbox, seulement au moment de son intégration ;
- formats et stockage de production à retenir pour les documents et firmwares ;
- accès à une borne physique uniquement pour la phase de validation matérielle.
