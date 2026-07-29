# Rapport ChargeTrackr

Ce dossier contient la source LaTeX du rapport de stage ChargeTrackr. Sa structure
reprend les cinq chapitres du rapport de référence fourni, avec un préambule nettoyé
et un contenu adapté au projet.

## Compilation

Depuis ce dossier :

```powershell
latexmk -pdf -interaction=nonstopmode -halt-on-error -outdir=build main.tex
```

Le PDF compilé se trouve dans `build/main.pdf`. La dernière version contrôlée est
également copiée dans `output/pdf/charge-trackr-rapport-stage.pdf`.

## Organisation

```text
assets/          Ressources graphiques
appendices/      Annexes et matrices de traçabilité
backmatter/      Conclusion
chapters/        Cinq chapitres du rapport
frontmatter/     Couverture, remerciements, acronymes et introduction
bibliography.bib Bibliographie
metadata.tex     Métadonnées du stage et du document
preamble.tex     Mise en page et commandes communes
main.tex         Point d'entrée
```

Les cinq chapitres, la conclusion générale et l'annexe de couverture du cahier
des charges sont rédigés. Le troisième chapitre documente l'architecture globale
et locale, les séquences métier, les modèles de domaine, la sécurité et
l'isolation multi-tenant. L'annexe A relie les 18 modules initiaux, les choix
techniques, les messages OCPP, les exigences non fonctionnelles, les interfaces
et les livrables à leur état réel dans le MVP.
