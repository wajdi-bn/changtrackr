# Manuels ChargeTrackr

Ce dossier contient les deux guides opérationnels de la plateforme :

- `user-manual` : manuel utilisateur destiné aux clients, opérateurs et techniciens ;
- `admin-manual` : manuel administrateur destiné aux administrateurs d'organisation
  et au Super Admin.

## Compilation

Depuis le dossier du manuel concerné :

```powershell
latexmk -pdf -interaction=nonstopmode -halt-on-error -outdir=build main.tex
```

Les versions livrables contrôlées sont placées dans :

```text
user-manual/output/pdf/charge-trackr-manuel-utilisateur.pdf
admin-manual/output/pdf/charge-trackr-manuel-administrateur.pdf
```

## Mise à jour

Après une évolution fonctionnelle :

1. vérifier les permissions et parcours dans le code ;
2. actualiser les procédures concernées ;
3. remplacer les captures devenues obsolètes ;
4. compiler les deux documents ;
5. rendre chaque page en image et effectuer un contrôle visuel.
