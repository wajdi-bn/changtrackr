# Avancement et traçabilité du backlog produit

État établi le 17 juillet 2026 à partir du code, des routes, des interfaces et des tests présents dans le dépôt. Une page statique ou des données fictives ne suffisent pas pour classer une User Story comme réalisée.

## Légende

- **Réalisée** : le workflow principal est disponible de bout en bout, protégé et couvert par des tests adaptés.
- **Partielle** : une partie fonctionnelle existe, mais au moins un critère majeur manque ou repose encore sur des données simulées/statiques.
- **À faire** : aucune implémentation métier exploitable n'est présente ; une route ou une page placeholder ne change pas ce statut.
- **Reportée** : fonctionnalité volontairement placée hors du périmètre immédiat.

## Synthèse

| Statut | User Stories | Points | Part des points |
|---|---:|---:|---:|
| Réalisée | 12 | 70 | 24,3 % |
| Partielle | 17 | 127 | 44,1 % |
| À faire | 10 | 78 | 27,1 % |
| Reportée | 1 | 13 | 4,5 % |
| **Total** | **40** | **288** | **100 %** |

La part des points n'est pas un pourcentage d'achèvement linéaire : une User Story partielle peut encore contenir son travail le plus risqué, notamment OCPP, cartographie ou temps réel.

## Matrice de traçabilité

| ID | Pts | Statut | Preuves actuelles | Écart principal |
|---|---:|---|---|---|
| US-01 | 8 | Réalisée | Session Sanctum/CSRF, inscription client, vérification email en file, récupération de mot de passe, OAuth2 Google, liaison `social_accounts` et tests de sécurité | Microsoft est hors périmètre du MVP actuel |
| US-02 | 3 | Partielle | Profil retourné par `UserResource`, champs présents dans `users` | Page profil statique, aucune API de mise à jour ou changement de mot de passe |
| US-03 | 8 | Partielle | Une demande approuvée provisionne atomiquement une organisation d'essai, un administrateur en attente et une invitation sécurisée révocable/renouvelable ; isolation des rôles validée | Le CRUD général des organisations et la gestion de leur cycle de vie restent absents |
| US-04 | 5 | Réalisée | `UserController`, `UserPolicy`, `UsersPage`, `UserManagementApiTest` ; service d'invitation générique disponible | La création d'employés utilise encore un mot de passe initial et doit être raccordée au service d'invitation |
| US-05 | 5 | À faire | Routes frontend réservées au super administrateur | Dashboard, audit, statistiques et état réel des intégrations absents |
| US-06 | 5 | Réalisée | `CustomerController`, `CustomersPage`, `CustomerManagementApiTest` | Les exports avancés restent couverts par US-34 |
| US-07 | 8 | Réalisée | `StationController`, `StationsPage`, formulaire Ant Design, `StationApiTest` | La désactivation pourra être distinguée d'une suppression logique |
| US-08 | 5 | Réalisée | `ConnectorController`, gestion dans `StationDetailPage`, tests d'appartenance borne-connecteur | Les états seront ultérieurement alimentés par OCPP |
| US-09 | 8 | Partielle | Liste, recherche, filtres et métriques dans `StationController` et `StationsPage` | Disponibilité encore stockée/manuelle, pas calculée depuis les événements |
| US-10 | 8 | À faire | Route `/map` et permissions existantes | Carte réelle et création géographique absentes |
| US-11 | 5 | Partielle | Le technicien peut consulter les bornes sans les modifier | Carte technicien encore placeholder |
| US-12 | 8 | Partielle | `FindStationPage` liste/recherche les bornes disponibles de réseaux actifs | Pas de carte, géolocalisation ni filtres connecteur/tarif complets |
| US-13 | 8 | Partielle | Fiche borne, aperçu et CRUD connecteurs alimentés par API | Onglets sessions, alertes, maintenance et documents encore statiques |
| US-14 | 13 | À faire | Champs OCPP dans les modèles et données de démonstration uniquement | Aucun serveur/gateway OCPP ni simulateur connecté |
| US-15 | 13 | À faire | Aucun moteur de projection métier | Matrice d'états, Heartbeat, délais et traitement idempotent à construire |
| US-16 | 13 | Partielle | États affichés par les listes et tags de l'interface | Pas de source OCPP, calcul métier ou diffusion temps réel |
| US-17 | 8 | Partielle | Dashboard opérateur présent visuellement | KPI et événements sont fictifs, aucune API analytique |
| US-18 | 8 | Partielle | Dashboard administrateur présent visuellement | Utilisateurs, revenus, régions et classements sont fictifs |
| US-19 | 8 | Partielle | Modèle d'alerte, CRUD, contexte OCPP et interface disponibles | Les alertes sont créées manuellement, sans règles automatiques ni déduplication |
| US-20 | 5 | Réalisée | Filtres, statuts, affectation, chronologie, policies et tests inter-organisations | SLA métier détaillé encore à paramétrer |
| US-21 | 5 | Réalisée | Alertes/interventions assignées, transitions contrôlées, vues technicien et tests | Notifications d'assignation couvertes ultérieurement par US-40 |
| US-22 | 8 | Partielle | Diagnostic, résolution, commentaires, pièces et chronologie persistés | Téléversement de photos et rapport final immuable absents |
| US-23 | 8 | Partielle | Interventions correctives planifiables avec technicien et durée | Maintenance préventive, récurrence et calendrier dédiés absents |
| US-24 | 8 | Réalisée | `ChargingSessionService`, verrouillage, client global, UI et tests multi-organisations | Le démarrage est simulé ; l'acquittement OCPP viendra avec US-14 |
| US-25 | 5 | Réalisée | Arrêt, calcul énergie/coût, remise du connecteur et tests | Les index réels de compteur viendront d'OCPP |
| US-26 | 5 | Réalisée | `ChargingSessionController`, `SessionsPage`, scoping client/organisation et tests | Les filtres temporels avancés pourront être ajoutés avec les rapports |
| US-27 | 5 | À faire | Route et page placeholder uniquement | Modèle, API, formulaire et compatibilité connecteurs absents |
| US-28 | 8 | À faire | Aucune implémentation | Jetons RFID/QR, sécurité et autorisation OCPP à concevoir |
| US-29 | 8 | Partielle | Paiement simulé, statuts, retry, idempotence, UI et tests | Génération et téléchargement de facture absents |
| US-30 | 8 | Réalisée | `PaymentGateway`, `SimulatedPaymentAdapter`, binding configurable et tests succès/refus | Webhooks et premier adaptateur réel restent futurs |
| US-31 | 8 | Partielle | CRUD tarifs/plans, affectation connecteur-borne, résolution, snapshot et simulation | Plages horaires et coût temporel effectif non implémentés |
| US-32 | 8 | Réalisée | Catalogue multi-organisations, changement/annulation, remise et tests | Paiement récurrent réel hors MVP actuel |
| US-33 | 8 | À faire | Page placeholder uniquement | API d'agrégation et génération de rapports absentes |
| US-34 | 5 | Partielle | Exports CSV/JSON des employés et clients avec permissions | PDF, Excel et exports des autres modules absents |
| US-35 | 5 | À faire | Page paramètres placeholder | Modèle de paramètres organisationnels et API absents |
| US-36 | 8 | À faire | Page intégrations placeholder et configuration serveur dispersée | Console globale, tests de santé et audit absents |
| US-37 | 5 | À faire | Onglet documents statique dans la fiche borne | Stockage, métadonnées, versions et autorisations absents |
| US-38 | 13 | Reportée | Version OCPP déclarative dans les bornes uniquement | Déploiement firmware à traiter après stabilisation OCPP et test matériel |
| US-39 | 3 | Réalisée | Formulaire public persisté, validation, consentement, honeypot, limitation de débit, console Super Admin, workflow de qualification et provisionnement testés | La conversion est volontairement réservée au Super Admin |
| US-40 | 5 | Partielle | Vérification email, récupération de mot de passe, notification interne de demande de démo et invitation administrateur utilisent les files et Resend/Mailpit | Notifications in-app persistées, préférences utilisateur et canaux d'alertes métier absents |

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

1. **Entrée et identité** - US-01 et US-39 sont réalisées ; finaliser plus tard US-02 et US-03, puis compléter les canaux de US-40.
2. **Cartographie** - implémenter US-10, puis compléter US-11 et US-12 avec le même composant cartographique.
3. **Communication OCPP** - implémenter US-14 avec le simulateur et un scénario connexion-Heartbeat-StatusNotification.
4. **Disponibilité calculée** - définir la matrice métier puis implémenter US-15 avant de terminer US-09 et US-16.
5. **Alertes automatiques et temps réel** - terminer US-19, relier les projections à l'interface et aux notifications US-40.
6. **Sessions réelles** - relier US-24 et US-25 aux commandes et mesures OCPP sans casser le simulateur actuel.
7. **Maintenance** - compléter US-22 et US-23 avec photos, préventif, calendrier et SLA.
8. **Tarification et facturation** - terminer US-31 et US-29, puis ajouter le premier adaptateur de paiement externe si le périmètre le permet.
9. **Dashboards et rapports** - remplacer les mocks de US-17 et US-18, implémenter US-33 puis généraliser US-34.
10. **Modules complémentaires** - réaliser US-27, US-28, US-35, US-36 et US-37 selon la priorité métier.
11. **Firmware** - conserver US-38 reportée jusqu'à la stabilisation d'OCPP et la disponibilité d'une procédure fournisseur ou d'une borne de test.

## Décisions externes encore nécessaires

- validation de la matrice de disponibilité, des seuils Heartbeat et de la priorité des états ;
- choix du fournisseur cartographique et obtention éventuelle d'une clé ;
- identifiants OAuth2 Microsoft uniquement si cette intégration sort du report ;
- adresses et paramètres du service email de production ;
- règles de TVA, facturation et numérotation des factures ;
- prestataire de paiement réel et accès sandbox, seulement au moment de son intégration ;
- formats et stockage retenus pour photos, documents et firmwares ;
- accès à une borne physique uniquement pour la phase de validation matérielle.
