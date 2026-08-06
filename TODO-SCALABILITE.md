# TODO - Scalabilite et industrialisation

Ce document recense les refontes de scalabilite volontairement reportees apres
la stabilisation fonctionnelle du MVP. Les corrections rapides et les defauts
d'integrite identifies dans les rapports d'audit restent traites dans les blocs
prioritaires du projet.

## Principes non negociables

- Conserver l'isolation des donnees entre organisations.
- Conserver la signature HMAC, l'idempotence et la protection anti-rejeu des
  echanges OCPP et des webhooks.
- Ne pas exposer de secrets dans le depot, les images de conteneurs ou les logs.
- Mesurer les performances avant et apres chaque refonte avec des scenarios
  reproductibles.

## 1. Remplacer le polling OCPP par du push ou du long-poll controle

**Source :** rapport performance, constat #2.

**Etat actuel :** les outils de simulation et de controle interrogent
periodiquement l'etat des commandes. Cette strategie convient au MVP, mais le
nombre de requetes augmente avec le nombre de bornes et de sessions actives.

**Pourquoi cette refonte est reportee :** elle modifie le contrat entre Laravel,
le gateway OCPP, Reverb et les simulateurs. Elle doit etre realisee apres la
stabilisation des evenements metier et de la topologie de deploiement.

**Travail prevu :**

- publier les changements d'etat OCPP comme des evenements persistants ;
- propager les mises a jour au frontend par Reverb/WebSocket ;
- conserver un mecanisme de rattrapage borne et idempotent apres reconnexion ;
- definir une strategie de backpressure et d'expiration des abonnements ;
- comparer push, long-poll et polling degrade sous charge.

**Prerequis :** Redis securise, conteneurisation de la pile temps reel,
observabilite du gateway et scenarios de charge reproductibles.

**Critere de reprise :** la charge de polling devient significative ou le parc
de demonstration depasse la capacite validee par les tests de charge.

**Criteres d'acceptation :**

- une transition OCPP est visible par le client concerne sans polling regulier ;
- aucune transition n'est perdue apres une reconnexion temporaire ;
- les evenements restent scopes par organisation et utilisateur autorise ;
- les tests de charge demontrent une baisse mesurable des requetes HTTP.

## 2. Optimiser les images en WebP ou AVIF

**Source :** rapport performance, constat #4.

**Etat actuel :** plusieurs images PNG/JPEG sont lourdes et certaines pages
chargent plus de donnees visuelles que necessaire. Le favicon sera corrige dans
les quick wins, independamment de cette refonte.

**Pourquoi cette refonte est reportee :** une conversion automatique sans audit
visuel peut degrader les logos, les transparences et les captures utilisees dans
les livrables. Elle doit suivre le rangement des ressources et la stabilisation
des interfaces.

**Travail prevu :**

- inventorier les images reellement utilisees et supprimer les doublons ;
- produire des variantes WebP/AVIF et des tailles responsives ;
- conserver les originaux uniquement lorsque la fidelite l'exige ;
- utiliser `srcset`, le lazy loading et des dimensions explicites ;
- verifier visuellement chaque page sur desktop et mobile.

**Prerequis :** arborescence des ressources stabilisee, inventaire des usages et
captures de reference des ecrans principaux.

**Critere de reprise :** le bloc de rangement des ressources est termine.

**Criteres d'acceptation :**

- aucune regression visuelle des logos et illustrations ;
- reduction mesurable du poids total des images chargees sur la landing page ;
- absence de decalage de mise en page cause par les medias ;
- fallback disponible pour les navigateurs non compatibles si necessaire.

## 3. Rendre Reverb et le gateway OCPP multi-processus

**Source :** rapport performance, constats #17 et #18.

**Etat actuel :** Reverb et le gateway OCPP sont adaptes a une instance de
demonstration. Une multiplication non coordonnee des processus risquerait de
dupliquer les connexions, commandes ou evenements.

**Pourquoi cette refonte est reportee :** elle depend du deploiement cible, du
load balancer, du stockage partage et du protocole de distribution des bornes.
La realiser avant la conteneurisation et les mesures de charge introduirait une
complexite sans preuve de besoin.

**Travail prevu :**

- rendre les instances Reverb stateless avec coordination Redis ;
- choisir une strategie d'affinite ou de partitionnement des bornes OCPP ;
- garantir qu'une borne n'est pilotee que par un proprietaire logique a la fois ;
- distribuer les commandes avec accusés persistants et idempotents ;
- ajouter des controles de sante, metriques et redemarrages progressifs ;
- tester la perte d'une instance pendant une recharge.

**Prerequis :** pile conteneurisee, Redis securise, supervision, tests de charge
et politique de deploiement documentee.

**Critere de reprise :** une instance ne respecte plus les objectifs de connexions
simultanees, latence ou disponibilite definis pendant l'industrialisation.

**Criteres d'acceptation :**

- ajout et retrait d'une instance sans perte de session active ;
- aucune commande OCPP executee deux fois ;
- reprise automatique apres panne d'une instance ;
- isolation multi-tenant et controles HMAC/anti-rejeu inchanges ;
- capacite horizontale demontree par des tests de charge documentes.

## Ordre de realisation propose

1. Stabiliser les workflows financiers, OCPP et frontend.
2. Securiser Redis et basculer les drivers Laravel valides.
3. Conteneuriser les services et mettre en place l'observabilite.
4. Mesurer la capacite d'une instance avec des tests de charge.
5. Optimiser les images apres le rangement des ressources.
6. Remplacer le polling lorsque les contrats d'evenements sont stabilises.
7. Activer le scaling multi-processus seulement si les mesures le justifient.
