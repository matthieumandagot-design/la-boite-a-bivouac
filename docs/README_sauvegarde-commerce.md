# Sauvegarde de la base du module commercial

`commerce.sqlite` contient les comptes clients, les commandes et l'instantané figé de chaque
configuration commandée : les cotes exactes, au prix exact facturé. C'est à la fois la pièce
comptable et l'ordre de fabrication. Rien d'autre ne les détient — Stripe ne connaît que des
montants et des e-mails, pas des cotes.

Le fichier est exclu de la synchronisation FTP : un déploiement ne l'écrase pas. C'est une
protection, pas une copie.

## Ce que fait le script

`public/lib/sauvegarde-commerce.php`, lancé une fois par jour :

1. demande à SQLite d'écrire une copie propre (`VACUUM INTO`) — une simple copie du fichier
   serait incohérente, la base tournant en WAL ;
2. relit cette copie : `PRAGMA integrity_check`, présence des tables `customers`, `orders` et
   `order_items`, comptage des lignes ;
3. la compresse en `commerce-AAAA-MM-JJ.sqlite.gz`, puis **relit l'archive entière** et compare
   son empreinte SHA-256 à celle de la copie — une sauvegarde qu'on n'a pas relue n'en est pas une ;
4. fait le ménage : les quotidiennes sont gardées 30 jours, celles du 1er du mois 365 jours,
   et jamais moins de 7 fichiers quoi qu'il arrive ;
5. écrit une ligne dans `journal.log` et sort en code non nul si quoi que ce soit a échoué.

Les fichiers vont dans `sauvegardes-commerce/`, **à côté** de `laboiteabivouac/` : hors du dossier
web, donc ni téléchargeables ni touchés par un déploiement. Hors ligne de commande, le script
répond 404 et ne fait rien.

## Tâche planifiée OVH

Manager OVH → **Web Cloud** → **Hébergements** → votre hébergement → onglet **Tâches planifiées**
→ *Ajouter une tâche* :

| Champ | Valeur |
|---|---|
| Langage | PHP 8.3 |
| Commande | `laboiteabivouac/lib/sauvegarde-commerce.php` |
| Mode d'exécution | CLI |
| Fréquence | une fois par jour, à une heure creuse (3 h du matin) |
| Journalisation | recevoir un e-mail en cas d'erreur |

L'état de la dernière sauvegarde s'affiche dans `/admin/base.php`, carte **Sauvegarde** : la date
passe en jaune si elle date de plus de deux jours, signe que la tâche ne tourne plus.

## Restaurer

```
gunzip -c sauvegardes-commerce/commerce-2026-09-29.sqlite.gz > commerce.sqlite
```

Puis, par FTP, remplacer `laboiteabivouac/data/private/commerce.sqlite` par ce fichier, et
**supprimer `commerce.sqlite-wal` et `commerce.sqlite-shm`** s'ils existent : laissés en place, ils
appartiennent à l'ancienne base et SQLite tenterait de les appliquer à la nouvelle.

Vérifier ensuite dans `/admin/base.php` que les tables et les migrations sont bien là.
