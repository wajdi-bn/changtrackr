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
backmatter/      Conclusion
chapters/        Cinq chapitres du rapport
frontmatter/     Couverture, remerciements, acronymes et introduction
bibliography.bib Bibliographie
metadata.tex     Métadonnées du stage et du document
preamble.tex     Mise en page et commandes communes
main.tex         Point d'entrée
```

Les chapitres 1 à 3 sont entièrement rédigés. Le troisième chapitre documente
l'architecture globale et locale, les séquences métier, les modèles de domaine,
la sécurité et l'isolation multi-tenant. Les blocs "Périmètre prévu" des chapitres
suivants seront remplacés progressivement par le contenu validé.
