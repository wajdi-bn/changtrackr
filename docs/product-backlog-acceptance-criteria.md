# Critères d'acceptation du backlog produit

Ce document complète le backlog validé sans en modifier les User Stories, les priorités ou les estimations. Les critères sont formulés de manière vérifiable et servent de base aux tests fonctionnels, de sécurité et d'isolation multi-organisation.

## Definition of Done commune

Une User Story est considérée comme réalisée uniquement lorsque :

- tous ses critères d'acceptation sont satisfaits dans un environnement proche de la production ;
- les autorisations sont contrôlées côté backend, indépendamment de l'affichage frontend ;
- les données d'une organisation ne sont jamais accessibles depuis une autre organisation ;
- les entrées sont validées et les erreurs métier retournent une réponse explicite ;
- l'interface couvre les états chargement, vide, erreur, succès et accès refusé ;
- les migrations, configurations et variables d'environnement nécessaires sont documentées ;
- les scénarios nominaux, erreurs et permissions possèdent des tests automatisés adaptés au risque ;
- le lint, le build frontend et la suite de tests backend réussissent ;
- aucun secret, jeton ou fichier d'environnement n'est versionné ;
- les actions sensibles sont traçables et les données personnelles sont limitées au strict nécessaire.

## Paramètres métier à valider

Les critères ci-dessous supposent que les valeurs suivantes seront configurables tant qu'elles ne sont pas validées avec l'entreprise :

- intervalle Heartbeat, délai de grâce et seuil de passage à l'état non disponible ;
- priorité entre les états OCPP, une session active, une maintenance et une perte de connexion ;
- délais SLA selon la gravité des alertes ;
- règles de conservation des événements OCPP, journaux, documents et factures ;
- fournisseur cartographique et politique de géocodage ;
- règles de TVA, arrondi, plages horaires et frais d'inactivité ;
- canaux de notification activés et stratégie de relance ;
- limites de taille, formats et stockage des photos, documents et firmwares.

## US-01 - Inscription et authentification

- **CA-01.1** - Étant donné un visiteur avec un email non utilisé, lorsqu'il s'inscrit, alors un compte ayant uniquement le rôle client est créé sans organisation fixe.
- **CA-01.2** - Étant donné un compte actif et valide, lorsque l'utilisateur saisit ses identifiants, alors une session sécurisée par cookie HTTP-only est créée et le profil, le rôle et les permissions sont retournés.
- **CA-01.3** - Étant donné une identité Google vérifiée, lorsque l'utilisateur autorise la connexion, alors elle est associée au bon compte sans créer de doublon d'email. L'intégration Microsoft est reportée.
- **CA-01.4** - Étant donné des identifiants invalides, un compte inactif ou une affectation organisationnelle incohérente, lorsque la connexion est tentée, alors l'accès est refusé sans révéler d'information sensible.
- **CA-01.5** - Étant donné une inscription locale client, lorsque le compte est créé, alors un email de vérification signé et limité dans le temps est mis en file et la connexion locale reste interdite jusqu'à sa validation.
- **CA-01.6** - Étant donné une demande de récupération, lorsque l'email est soumis, alors la réponse ne révèle pas l'existence du compte et un lien de réinitialisation à usage unique est mis en file pour les comptes correspondants.

## US-02 - Profil et sécurité du compte

- **CA-02.1** - Étant donné un utilisateur authentifié, lorsqu'il consulte son profil, alors il voit ses informations personnelles ainsi que son rôle et son organisation en lecture seule.
- **CA-02.2** - Étant donné des données personnelles valides, lorsque l'utilisateur modifie les champs autorisés, alors les changements sont enregistrés sans permettre de modifier son rôle ou son organisation.
- **CA-02.3** - Étant donné le mot de passe actuel correct, lorsque l'utilisateur définit un nouveau mot de passe conforme à la politique, alors le mot de passe est remplacé et les sessions à révoquer sont invalidées.
- **CA-02.4** - Étant donné une valeur invalide ou un email déjà utilisé, lorsque le formulaire est soumis, alors aucun changement partiel n'est enregistré.

## US-03 - Organisations et administrateurs globaux

- **CA-03.1** - Étant donné un super administrateur, lorsqu'il crée ou modifie une organisation, alors les informations et le statut de cette organisation sont persistés et audités.
- **CA-03.2** - Étant donné une organisation active, lorsque le super administrateur crée ou transfère un administrateur, alors cet administrateur appartient à exactement une organisation.
- **CA-03.3** - Étant donné un utilisateur qui n'est pas super administrateur, lorsqu'il tente de gérer une organisation ou un administrateur, alors l'accès est refusé côté API.
- **CA-03.4** - Étant donné une organisation désactivée, lorsque l'un de ses employés utilise un jeton existant, alors l'accès organisationnel est immédiatement refusé.

## US-04 - Employés d'une organisation

- **CA-04.1** - Étant donné un administrateur, lorsqu'il consulte les employés, alors seuls les administrateurs, opérateurs et techniciens de son organisation sont visibles.
- **CA-04.2** - Étant donné des informations valides, lorsque l'administrateur crée un opérateur ou un technicien, alors l'organisation de l'administrateur est affectée automatiquement.
- **CA-04.3** - Étant donné un administrateur, lorsqu'il tente de créer un administrateur, un client, un super administrateur ou d'injecter une autre organisation, alors la requête est rejetée.
- **CA-04.4** - Étant donné un opérateur ou technicien de la même organisation, lorsque l'administrateur le désactive, alors ses nouveaux accès sont bloqués et l'action reste traçable.
- **CA-04.5** - Étant donné une invitation d'employé, lorsque l'administrateur la crée, alors le compte reste en attente, aucun mot de passe initial n'est choisi et un lien à usage unique est envoyé en file.
- **CA-04.6** - Étant donné un lien valide, lorsque l'employé définit un mot de passe conforme, alors l'email est vérifié, l'invitation est consommée et le compte devient actif dans le rôle attribué.
- **CA-04.7** - Étant donné une invitation en attente, expirée ou annulée, lorsque l'administrateur agit, alors seules les actions rappel, annulation ou renouvellement compatibles avec cet état sont acceptées.
- **CA-04.8** - Étant donné un autre administrateur ou une autre organisation, lorsque l'une des routes d'invitation est appelée directement, alors l'accès est refusé sans révéler le jeton.

## US-05 - Audit et statistiques globales

- **CA-05.1** - Étant donné un super administrateur, lorsqu'il ouvre le tableau global, alors les indicateurs proviennent des données réelles et couvrent organisations, utilisateurs, bornes et intégrations.
- **CA-05.2** - Étant donné des journaux d'audit, lorsque le super administrateur filtre par acteur, organisation, action ou période, alors seuls les événements correspondants sont affichés.
- **CA-05.3** - Étant donné une action sensible, lorsqu'elle est exécutée, alors son acteur, sa date, sa cible et son résultat sont enregistrés sans données secrètes.
- **CA-05.4** - Étant donné un autre rôle, lorsqu'il tente d'accéder à l'audit global, alors l'accès est refusé.

## US-06 - Clients d'une organisation

- **CA-06.1** - Étant donné un administrateur, lorsqu'il consulte les clients, alors seuls les clients ayant au moins une activité sur les bornes de son organisation sont listés.
- **CA-06.2** - Étant donné un client ayant utilisé plusieurs réseaux, lorsque l'administrateur ouvre sa fiche, alors seules les sessions et statistiques relatives à son organisation sont visibles.
- **CA-06.3** - Étant donné un client global, lorsque l'administrateur consulte sa fiche, alors il ne peut ni modifier son compte ni le rattacher définitivement à son organisation.
- **CA-06.4** - Étant donné des filtres actifs, lorsque l'administrateur exporte la liste, alors l'export respecte les mêmes filtres et le même périmètre organisationnel.

## US-07 - Gestion des bornes

- **CA-07.1** - Étant donné un opérateur, lorsqu'il crée une borne valide, alors elle est automatiquement rattachée à son unique organisation.
- **CA-07.2** - Étant donné une borne de son organisation, lorsque l'opérateur modifie ses informations techniques ou géographiques, alors les changements sont visibles dans la liste et la fiche détaillée.
- **CA-07.3** - Étant donné une borne d'une autre organisation, lorsque l'opérateur tente de la consulter, modifier ou supprimer par son identifiant, alors l'accès est refusé.
- **CA-07.4** - Étant donné une borne ayant une activité à conserver, lorsque l'opérateur la désactive, alors l'historique est préservé et aucune nouvelle session ne peut démarrer.

## US-08 - Gestion des connecteurs

- **CA-08.1** - Étant donné une borne de son organisation, lorsque l'opérateur ajoute un connecteur, alors son identifiant externe, son type, sa puissance et son état sont enregistrés.
- **CA-08.2** - Étant donné un connecteur, lorsque l'opérateur le modifie, alors l'API vérifie qu'il appartient bien à la borne indiquée dans l'URL.
- **CA-08.3** - Étant donné un connecteur utilisé par une session active, lorsque sa suppression est demandée, alors l'opération est bloquée ou différée sans perdre l'historique.
- **CA-08.4** - Étant donné un utilisateur sans permission de gestion, lorsqu'il tente de créer, modifier ou supprimer un connecteur, alors l'accès est refusé.

## US-09 - Liste et disponibilité des bornes

- **CA-09.1** - Étant donné un opérateur, lorsqu'il ouvre la liste, alors seules les bornes de son organisation sont affichées avec état calculé, dernière activité et état de connexion.
- **CA-09.2** - Étant donné des filtres de recherche ou d'état, lorsqu'ils sont appliqués, alors la liste, la pagination et les totaux sont cohérents.
- **CA-09.3** - Étant donné un événement OCPP ou un dépassement de délai, lorsque la projection de disponibilité change, alors la liste reflète ce changement sans modification manuelle de la base.
- **CA-09.4** - Étant donné une information devenue trop ancienne, lorsque la liste est consultée, alors son caractère obsolète est identifiable.

## US-10 - Carte opérateur et création géographique

- **CA-10.1** - Étant donné un opérateur, lorsqu'il ouvre la carte, alors seules les bornes de son organisation sont positionnées avec un marqueur correspondant à leur état.
- **CA-10.2** - Étant donné un marqueur, lorsque l'opérateur le sélectionne, alors un résumé et un accès à la fiche de la borne sont proposés.
- **CA-10.3** - Étant donné un emplacement valide sélectionné sur la carte, lorsque l'opérateur confirme la création, alors les coordonnées et l'adresse de la nouvelle borne sont enregistrées.
- **CA-10.4** - Étant donné une erreur du service cartographique, lorsque la carte ne peut pas charger, alors l'interface affiche une erreur récupérable sans perdre le reste de la page.

## US-11 - Consultation cartographique du technicien

- **CA-11.1** - Étant donné un technicien, lorsqu'il consulte les bornes ou la carte, alors seules les bornes de son organisation sont visibles.
- **CA-11.2** - Étant donné une borne visible, lorsque le technicien ouvre sa fiche, alors les informations techniques nécessaires au diagnostic sont accessibles en lecture seule.
- **CA-11.3** - Étant donné un technicien, lorsqu'il tente d'ajouter, modifier ou supprimer une borne depuis l'interface ou l'API, alors l'action est refusée.

## US-12 - Recherche cartographique du client

- **CA-12.1** - Étant donné un client, lorsqu'il ouvre la carte, alors les bornes publiques de toutes les organisations actives sont visibles.
- **CA-12.2** - Étant donné des critères de disponibilité, connecteur, puissance ou tarif, lorsque le client filtre, alors seuls les résultats compatibles sont affichés.
- **CA-12.3** - Étant donné une organisation inactive ou une borne privée, lorsque la carte est chargée, alors cette borne n'est jamais exposée au client.
- **CA-12.4** - Étant donné une borne sélectionnée, lorsque le client consulte son résumé, alors il voit les connecteurs disponibles et le tarif effectif avant de démarrer une session.

## US-13 - Fiche détaillée d'une borne

- **CA-13.1** - Étant donné une borne autorisée, lorsque sa fiche est ouverte, alors l'aperçu et les connecteurs proviennent de l'API.
- **CA-13.2** - Étant donné des sessions, alertes, maintenances ou documents liés, lorsque l'onglet correspondant est ouvert, alors les données sont filtrées par borne et par organisation.
- **CA-13.3** - Étant donné un utilisateur en lecture seule, lorsque la fiche est affichée, alors les actions de modification ne sont ni proposées ni acceptées par l'API.
- **CA-13.4** - Étant donné une borne d'un autre périmètre, lorsque son URL est appelée directement, alors l'accès est refusé.

## US-14 - Réception des messages OCPP

- **CA-14.1** - Étant donné une borne connue et autorisée, lorsqu'elle établit une connexion OCPP 1.6 JSON, alors son identité est vérifiée avant d'accepter les messages.
- **CA-14.2** - Étant donné un message BootNotification, Heartbeat, StatusNotification ou de transaction valide, lorsqu'il est reçu, alors il est horodaté, normalisé et transmis au traitement métier.
- **CA-14.3** - Étant donné un message mal formé, dupliqué ou provenant d'une borne inconnue, lorsqu'il est reçu, alors il est rejeté ou ignoré de manière idempotente et journalisé.
- **CA-14.4** - Étant donné le simulateur OCPP retenu, lorsqu'un scénario minimal connexion-changement d'état-session est exécuté, alors l'application reçoit les événements attendus sans modification manuelle en base.
- **CA-14.5** - Étant donné un administrateur ou un opérateur autorisé, lorsqu'il demande un Soft Reset, un déverrouillage ou un changement de disponibilité, alors la commande est transmise à la borne, auditée et son résultat est actualisé sans exposer de Hard Reset.

## US-15 - Calcul des états de disponibilité

- **CA-15.1** - Étant donné une StatusNotification valide, lorsqu'elle est traitée, alors l'état projeté du connecteur et de la borne est recalculé selon la matrice métier.
- **CA-15.2** - Étant donné une transaction active confirmée par OCPP, lorsque la projection est calculée, alors le connecteur est occupé indépendamment d'une ancienne valeur stockée.
- **CA-15.3** - Étant donné une absence de Heartbeat ou une perte de connexion dépassant le seuil configuré, lorsque le contrôle périodique s'exécute, alors la borne devient non disponible et l'heure de détection est conservée.
- **CA-15.4** - Étant donné des événements en retard, répétés ou reçus dans le désordre, lorsque le calcul s'exécute, alors un événement plus ancien ne remplace pas une projection plus récente.
- **CA-15.5** - Étant donné une maintenance active ou un défaut critique, lorsque plusieurs états sont possibles, alors la priorité définie par la matrice métier est appliquée de façon déterministe.

## US-16 - Supervision en temps réel

- **CA-16.1** - Étant donné un opérateur connecté, lorsqu'un état projeté change, alors la liste, la carte et la fiche sont mises à jour sans rechargement manuel.
- **CA-16.2** - Étant donné une borne disponible, occupée, non disponible, en maintenance, déconnectée ou en défaut, lorsque son état est affiché, alors le libellé, la couleur et l'horodatage sont cohérents partout.
- **CA-16.3** - Étant donné une interruption du canal temps réel, lorsque l'interface ne reçoit plus d'événements, alors elle signale la perte de synchronisation et permet une actualisation.
- **CA-16.4** - Étant donné deux organisations, lorsqu'un événement est diffusé, alors seuls les utilisateurs autorisés de l'organisation concernée le reçoivent.

## US-17 - Tableau de bord opérateur

- **CA-17.1** - Étant donné un opérateur, lorsqu'il ouvre son tableau de bord, alors les KPI proviennent exclusivement des bornes et sessions de son organisation.
- **CA-17.2** - Étant donné une période sélectionnée, lorsque le filtre change, alors disponibilité, énergie, sessions actives et indisponibilité sont recalculées avec des définitions documentées.
- **CA-17.3** - Étant donné un événement récent, lorsque le tableau est actualisé, alors les compteurs et graphiques reflètent les données réelles et non des valeurs fictives.
- **CA-17.4** - Étant donné l'absence de données, lorsque le tableau est affiché, alors les KPI restent explicites et ne produisent ni division par zéro ni graphique trompeur.

## US-18 - Tableau de bord administrateur

- **CA-18.1** - Étant donné un administrateur, lorsqu'il ouvre son tableau de bord, alors les utilisateurs, clients, revenus, régions et bornes sont limités à son organisation.
- **CA-18.2** - Étant donné les classements, lorsque les meilleurs clients, opérateurs, techniciens ou bornes sont calculés, alors la formule et la période sont affichées.
- **CA-18.3** - Étant donné des paiements et sessions réels, lorsque le chiffre d'affaires et les indicateurs métier sont affichés, alors ils correspondent aux données agrégées de l'API.
- **CA-18.4** - Étant donné une tentative d'accès à une autre organisation, lorsque des paramètres sont manipulés, alors l'API ignore ou rejette ce périmètre.

## US-19 - Génération automatique des alertes

- **CA-19.1** - Étant donné un défaut, une surchauffe, une erreur de communication ou un timeout détecté, lorsque la règle métier est satisfaite, alors une alerte est créée automatiquement avec source, gravité et contexte technique.
- **CA-19.2** - Étant donné une alerte active pour le même incident, lorsque le même événement est reçu de nouveau, alors aucune alerte concurrente en doublon n'est créée.
- **CA-19.3** - Étant donné un retour à la normale, lorsque la règle de récupération est satisfaite, alors l'alerte est mise à jour ou résolue selon la politique définie.
- **CA-19.4** - Étant donné une nouvelle alerte, lorsque sa création est confirmée, alors les destinataires autorisés sont notifiés selon leurs préférences.

## US-20 - Suivi et assignation des alertes

- **CA-20.1** - Étant donné un opérateur, lorsqu'il filtre par gravité, statut, texte ou borne, alors la liste et les compteurs correspondent aux filtres.
- **CA-20.2** - Étant donné un technicien actif de la même organisation, lorsque l'opérateur lui assigne une alerte, alors l'affectation et l'événement d'historique sont enregistrés.
- **CA-20.3** - Étant donné un technicien d'une autre organisation, lorsque son identifiant est injecté, alors l'assignation est rejetée.
- **CA-20.4** - Étant donné un changement de statut, lorsque l'opérateur le confirme, alors la chronologie, la date de résolution et le SLA sont mis à jour de manière cohérente.

## US-21 - Travail assigné au technicien

- **CA-21.1** - Étant donné un technicien, lorsqu'il ouvre ses alertes et interventions, alors il ne voit que les éléments qui lui sont assignés.
- **CA-21.2** - Étant donné une intervention assignée, lorsque le technicien la consulte, alors le contexte de la borne, du connecteur, de l'erreur et la chronologie sont disponibles.
- **CA-21.3** - Étant donné une transition autorisée, lorsque le technicien change le statut, alors le changement et son auteur sont enregistrés.
- **CA-21.4** - Étant donné une intervention non assignée ou une tentative de réaffectation, lorsque le technicien agit, alors l'accès est refusé.

## US-22 - Rapport d'intervention

- **CA-22.1** - Étant donné une intervention assignée, lorsque le technicien enregistre diagnostic, résolution, commentaires et pièces, alors les données sont conservées avec leur auteur et leur date.
- **CA-22.2** - Étant donné des photos valides, lorsque le technicien les joint, alors elles sont stockées de manière sécurisée et associées uniquement à l'intervention concernée.
- **CA-22.3** - Étant donné des champs obligatoires manquants, lorsque la résolution est demandée, alors la clôture est refusée avec les erreurs correspondantes.
- **CA-22.4** - Étant donné une intervention résolue, lorsque le rapport est consulté, alors son contenu final et sa chronologie ne peuvent pas être altérés sans trace d'audit.
- **CA-22.5** - Étant donné une intervention active, lorsque le technicien tente de soumettre le rapport sans au moins une photo avant et une photo après, alors la clôture est refusée.
- **CA-22.6** - Étant donné une preuve photo, lorsqu'un utilisateur la consulte ou tente de la supprimer, alors l'organisation, l'affectation, l'état de l'intervention et la présence d'un rapport final sont contrôlés.
- **CA-22.7** - Étant donné un résultat nécessitant un suivi, lorsque le rapport est soumis, alors l'intervention est terminée mais l'alerte liée retourne dans la file d'affectation.

## US-23 - Maintenance préventive et corrective

- **CA-23.1** - Étant donné une borne de son organisation, lorsque l'opérateur planifie une maintenance, alors le type, la priorité, le technicien, la date et la durée sont enregistrés.
- **CA-23.2** - Étant donné une maintenance préventive récurrente, lorsque son échéance arrive, alors la prochaine intervention est créée sans doublon.
- **CA-23.3** - Étant donné une maintenance active, lorsque l'état de disponibilité est calculé, alors la maintenance est prise en compte selon la matrice métier.
- **CA-23.4** - Étant donné une maintenance replanifiée, annulée ou terminée, lorsque son statut change, alors la chronologie et les calendriers concernés sont mis à jour.
- **CA-23.5** - Étant donné une maintenance simplement planifiée, lorsque sa date approche sans démarrage du technicien, alors la borne reste disponible et aucun override de maintenance n'est appliqué.
- **CA-23.6** - Étant donné une maintenance démarrée par son technicien, lorsque la borne est gérée par OCPP, alors l'override local est appliqué immédiatement et une commande `ChangeAvailability(Inoperative)` est mise en file ; la clôture applique le comportement inverse.

## US-24 - Démarrage d'une recharge

- **CA-24.1** - Étant donné un client global, lorsqu'il sélectionne une borne d'une organisation active et un connecteur disponible, alors il peut demander le démarrage sans adhésion automatique à cette organisation.
- **CA-24.2** - Étant donné une commande OCPP acceptée, lorsque la borne confirme le démarrage, alors une session active est créée avec le tarif et l'abonnement applicables figés.
- **CA-24.3** - Étant donné un connecteur occupé, indisponible ou une session déjà active pour le client, lorsque le démarrage est demandé, alors la requête est rejetée sans créer de session partielle.
- **CA-24.4** - Étant donné deux demandes concurrentes sur le même connecteur, lorsque le service les traite, alors une seule session peut devenir active.

## US-25 - Arrêt et résumé d'une recharge

- **CA-25.1** - Étant donné une session active du client, lorsque l'arrêt est confirmé par la borne, alors la session passe une seule fois à l'état terminé.
- **CA-25.2** - Étant donné les index de compteur de début et de fin, lorsque le résumé est calculé, alors durée, énergie, réduction et coût respectent le tarif figé de la session.
- **CA-25.3** - Étant donné l'arrêt de la dernière session active d'une borne, lorsque la projection est recalculée, alors le connecteur et la borne retrouvent leur état métier correct.
- **CA-25.4** - Étant donné une session appartenant à un autre client, lorsque l'arrêt est demandé, alors l'accès est refusé.

## US-26 - Historique des sessions

- **CA-26.1** - Étant donné un client, lorsqu'il consulte l'historique, alors seules ses propres sessions, quelle que soit l'organisation, sont visibles.
- **CA-26.2** - Étant donné un employé d'organisation, lorsqu'il consulte l'historique, alors seules les sessions réalisées sur son organisation sont visibles.
- **CA-26.3** - Étant donné des filtres de statut, paiement, période ou recherche, lorsqu'ils sont appliqués, alors la liste et les totaux utilisent le même périmètre.
- **CA-26.4** - Étant donné une session autorisée, lorsque son détail est ouvert, alors durée, énergie, coût, borne, connecteur, tarif et paiement sont consultables.

## US-27 - Véhicules électriques du client

- **CA-27.1** - Étant donné un client, lorsqu'il ajoute ou modifie un véhicule valide, alors ce véhicule est associé uniquement à son compte.
- **CA-27.2** - Étant donné les types de connecteurs du véhicule, lorsque le client recherche une borne, alors l'interface peut signaler les connecteurs compatibles.
- **CA-27.3** - Étant donné le véhicule d'un autre client, lorsque son identifiant est utilisé, alors toute lecture ou modification est refusée.
- **CA-27.4** - Étant donné un véhicule référencé par l'historique, lorsque le client le supprime, alors les sessions passées restent cohérentes.

## US-28 - Identification RFID ou QR code

- **CA-28.1** - Étant donné une carte RFID ou un QR code valide et actif, lorsque le jeton est présenté à une borne compatible, alors le client est identifié sans exposer ses données privées.
- **CA-28.2** - Étant donné un jeton inconnu, expiré, révoqué ou déjà utilisé de manière incompatible, lorsque le démarrage est demandé, alors l'autorisation est refusée.
- **CA-28.3** - Étant donné une autorisation réussie, lorsque la recharge démarre, alors la session est rattachée au bon client et au bon connecteur.
- **CA-28.4** - Étant donné un jeton enregistré, lorsque les données sont stockées ou journalisées, alors sa valeur brute n'est pas conservée en clair.

## US-29 - Paiement et facture

- **CA-29.1** - Étant donné une session terminée et impayée du client, lorsque le paiement est demandé, alors le montant facturé correspond au total figé de la session.
- **CA-29.2** - Étant donné un paiement réussi ou refusé, lorsque le prestataire répond, alors le paiement et la session reçoivent un état cohérent et consultable.
- **CA-29.3** - Étant donné la même clé d'idempotence, lorsque la demande est répétée, alors aucun double débit ni double revenu n'est enregistré.
- **CA-29.4** - Étant donné un paiement réussi, lorsque le client demande sa facture, alors un document numéroté contenant les mentions requises peut être téléchargé.

## US-30 - Adaptateur de paiement extensible

- **CA-30.1** - Étant donné un pilote configuré, lorsque le service de paiement est résolu, alors il implémente le contrat commun sans dépendance du domaine à un prestataire précis.
- **CA-30.2** - Étant donné l'adaptateur simulé, lorsque les scénarios succès et refus sont exécutés, alors des résultats déterministes et testables sont produits.
- **CA-30.3** - Étant donné un futur prestataire réel, lorsque son adaptateur est ajouté, alors le reste du workflow de paiement ne nécessite pas de modification métier.
- **CA-30.4** - Étant donné une notification asynchrone du prestataire, lorsqu'elle est reçue, alors sa signature, son idempotence et la transition d'état sont vérifiées.
- **CA-30.5** - Étant donné des clés de paiement, lorsqu'elles sont configurées, alors elles proviennent de variables d'environnement et ne sont jamais retournées au frontend.

## US-31 - Tarifs et affectations

- **CA-31.1** - Étant donné un administrateur, lorsqu'il gère un tarif, alors celui-ci appartient obligatoirement à son organisation.
- **CA-31.2** - Étant donné plusieurs tarifs actifs, lorsque le tarif effectif est résolu, alors la priorité connecteur, borne puis tarif par défaut de l'organisation est respectée.
- **CA-31.3** - Étant donné une plage horaire, une période de validité ou des frais d'inactivité, lorsque le prix est simulé, alors chaque composante apparaît dans le détail du calcul.
- **CA-31.4** - Étant donné le démarrage d'une session, lorsque le tarif est résolu, alors ses valeurs sont copiées dans la session afin que les modifications futures ne changent pas l'historique.
- **CA-31.5** - Étant donné une borne ou un connecteur d'une autre organisation, lorsque l'affectation est demandée, alors elle est rejetée.

## US-32 - Plans et abonnements multi-organisations

- **CA-32.1** - Étant donné un client, lorsqu'il consulte le catalogue, alors les plans actifs de toutes les organisations actives sont affichés avec leur prix et leurs avantages.
- **CA-32.2** - Étant donné un plan valide, lorsque le client s'abonne, alors il ne possède qu'un abonnement courant pour cette organisation mais peut rester abonné à d'autres organisations.
- **CA-32.3** - Étant donné une recharge, lorsque le client possède un abonnement courant auprès de l'organisation de la borne, alors seule la réduction de ce plan est appliquée.
- **CA-32.4** - Étant donné un abonnement appartenant à un autre client, lorsque sa modification ou annulation est demandée, alors l'accès est refusé.

## US-33 - Rapports opérationnels

- **CA-33.1** - Étant donné un opérateur et une période, lorsqu'il génère un rapport, alors disponibilité, utilisation, revenus, énergie et classement des bornes proviennent de son organisation.
- **CA-33.2** - Étant donné les mêmes données et filtres, lorsque le rapport est régénéré, alors les indicateurs restent reproductibles et leurs formules sont documentées.
- **CA-33.3** - Étant donné une période sans données, lorsque le rapport est généré, alors le document affiche des valeurs nulles explicites sans erreur.
- **CA-33.4** - Étant donné un utilisateur d'une autre organisation, lorsque l'identifiant du rapport est utilisé, alors son contenu n'est pas accessible.

## US-34 - Exports autorisés

- **CA-34.1** - Étant donné un utilisateur autorisé, lorsqu'il exporte une vue filtrée, alors le fichier respecte les filtres, colonnes et périmètre visibles.
- **CA-34.2** - Étant donné un format PDF, Excel ou CSV supporté, lorsque l'export est demandé, alors le type de fichier, l'encodage et les valeurs sont valides.
- **CA-34.3** - Étant donné un volume important, lorsque l'export dépasse le seuil synchrone, alors il est traité en tâche de fond et son résultat est notifié.
- **CA-34.4** - Étant donné un utilisateur sans permission d'export, lorsque l'endpoint est appelé directement, alors l'accès est refusé.

## US-35 - Paramètres locaux de l'organisation

- **CA-35.1** - Étant donné un administrateur, lorsqu'il consulte les paramètres, alors il voit uniquement les valeurs de son organisation.
- **CA-35.2** - Étant donné une TVA, une langue, un fuseau ou des préférences valides, lorsque les paramètres sont enregistrés, alors ils sont appliqués aux traitements concernés.
- **CA-35.3** - Étant donné un logo valide, lorsque l'administrateur l'envoie, alors son format et sa taille sont contrôlés avant stockage.
- **CA-35.4** - Étant donné une tentative de modifier une autre organisation ou un paramètre global, lorsque la requête est envoyée, alors elle est refusée et auditée.

## US-36 - Intégrations globales

- **CA-36.1** - Étant donné un super administrateur, lorsqu'il consulte les intégrations, alors il voit leur état de santé sans voir leurs secrets.
- **CA-36.2** - Étant donné une configuration de paiement, notification, cartographie, OAuth2 ou OCPP, lorsqu'elle change, alors les valeurs sensibles restent dans l'environnement ou un coffre de secrets.
- **CA-36.3** - Étant donné une intégration, lorsque le super administrateur lance un test, alors un résultat explicite et audité est retourné sans modifier les données métier.
- **CA-36.4** - Étant donné un autre rôle, lorsqu'il tente d'accéder aux configurations globales, alors l'accès est refusé.

## US-37 - Documents des bornes

- **CA-37.1** - Étant donné une borne de son organisation, lorsque l'administrateur ajoute un document valide, alors le fichier et ses métadonnées sont associés à cette borne.
- **CA-37.2** - Étant donné un document, lorsque sa nouvelle version est envoyée, alors l'historique des versions et l'auteur sont conservés.
- **CA-37.3** - Étant donné un utilisateur autorisé, lorsque le document est téléchargé, alors l'accès est contrôlé avant de servir le fichier.
- **CA-37.4** - Étant donné un fichier interdit, surdimensionné ou provenant d'une autre organisation, lorsque l'envoi est tenté, alors il est rejeté.

## US-38 - Mises à jour firmware

- **CA-38.1** - Étant donné une borne compatible et un firmware signé, lorsque l'administrateur planifie une mise à jour, alors la version cible et la fenêtre sont vérifiées.
- **CA-38.2** - Étant donné une commande de mise à jour OCPP, lorsque la borne progresse, alors les statuts téléchargement, installation, succès ou échec sont enregistrés.
- **CA-38.3** - Étant donné un firmware incompatible ou non vérifié, lorsque son déploiement est demandé, alors l'opération est refusée.
- **CA-38.4** - Étant donné un échec, lorsque la politique le permet, alors une reprise ou un retour arrière contrôlé est proposé et audité.

## US-39 - Présentation et demande de démonstration

- **CA-39.1** - Étant donné un visiteur, lorsqu'il consulte la page publique, alors la présentation et le formulaire sont accessibles sans authentification et restent utilisables sur mobile.
- **CA-39.2** - Étant donné un formulaire valide, lorsque la demande est envoyée, alors elle est persistée avec le statut nouveau et une confirmation est affichée.
- **CA-39.3** - Étant donné une nouvelle demande, lorsqu'elle est enregistrée, alors les destinataires configurés reçoivent une notification et peuvent en assurer le suivi.
- **CA-39.4** - Étant donné un formulaire invalide ou un volume abusif, lorsque l'envoi est tenté, alors les validations, la limitation de débit et la protection anti-spam s'appliquent.

## US-40 - Notifications et préférences

- **CA-40.1** - Étant donné un événement notifiable, lorsque la règle correspondante est déclenchée, alors une notification in-app est créée pour chaque destinataire autorisé.
- **CA-40.2** - Étant donné un canal email activé et autorisé par les préférences, lorsque la notification est distribuée, alors l'email est mis en file et son résultat est traçable.
- **CA-40.3** - Étant donné un utilisateur, lorsqu'il modifie ses préférences, alors il peut désactiver les notifications non obligatoires sans modifier celles d'un autre compte.
- **CA-40.4** - Étant donné une erreur temporaire d'envoi, lorsque la tâche échoue, alors la stratégie de nouvelle tentative s'applique sans créer de doublons.
- **CA-40.5** - Étant donné deux organisations, lorsqu'une alerte est notifiée, alors aucun utilisateur d'une autre organisation ne reçoit son contenu.
