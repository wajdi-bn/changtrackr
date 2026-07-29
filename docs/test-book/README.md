# Cahier de tests ChargeTrackr

Ce dossier contient la source LaTeX et le PDF contrôlé du cahier de tests de
ChargeTrackr.

## Compilation

Depuis ce dossier :

```powershell
latexmk -pdf -interaction=nonstopmode -halt-on-error -outdir=build main.tex
```

Le PDF généré se trouve dans `build/main.pdf`. La version livrable est copiée
dans `output/pdf/charge-trackr-cahier-tests.pdf`.

## Mise à jour

Après une évolution fonctionnelle :

1. actualiser la matrice de traçabilité ;
2. ajouter ou modifier les cas de test concernés ;
3. exécuter les suites automatisées ;
4. consigner les résultats de recette manuelle ;
5. compiler puis contrôler visuellement le PDF.
