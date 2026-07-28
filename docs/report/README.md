# Rapport ChargeTrackr

Ce dossier contient la source LaTeX du rapport de stage ChargeTrackr. Sa structure
reprend les cinq chapitres du rapport de référence fourni, avec un préambule nettoyé
et un contenu adapté au projet.

## Compilation

Depuis ce dossier :

```powershell
latexmk -pdf -interaction=nonstopmode -halt-on-error -outdir=build main.tex
```

Le PDF compilé se trouve dans `build/main.pdf`. La version structurelle validée est
également copiée dans `output/pdf/charge-trackr-report-structure.pdf`.

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

Cette première version est un squelette structurel. Les blocs "Périmètre prévu"
seront remplacés progressivement par le contenu validé.

