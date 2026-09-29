-- Suivi de la génération du dossier atelier
--
-- Le dossier est produit par FreeCAD sur GitHub Actions, déclenché à la
-- confirmation du paiement. La génération est asynchrone : la commande n'attend
-- pas, et le résultat arrive par e-mail dix à quinze minutes plus tard. Ce que
-- l'on garde ici, c'est donc l'issue du DÉCLENCHEMENT — accepté par GitHub ou
-- refusé, et pourquoi — pas celle de la génération elle-même.
--
-- Par ligne et non par commande : une commande peut contenir plusieurs Boîtes,
-- chacune avec ses cotes, donc chacune avec son dossier. Une ligne accessoire
-- ne déclenche rien et garde la colonne vide.

ALTER TABLE order_items ADD COLUMN dossier_atelier TEXT;
