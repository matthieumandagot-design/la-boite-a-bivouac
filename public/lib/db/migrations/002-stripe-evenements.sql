-- Événements Stripe déjà traités — docs/SPEC-module-commercial.md §6.5
--
-- Stripe réémet un événement tant qu'il n'a pas reçu de réponse 2xx, et peut le
-- livrer deux fois même après une réponse réussie : c'est une garantie « au
-- moins une fois », jamais « exactement une fois ». Sans trace de ce qui a déjà
-- été traité, un même paiement enverrait deux emails de confirmation au client.
--
-- L'identifiant vient de Stripe (evt_…) et sert de clé primaire : l'insertion
-- échoue d'elle-même sur un doublon, et c'est cet échec qui rend le traitement
-- idempotent. Pas de vérification préalable suivie d'une écriture, qui
-- laisserait passer deux livraisons simultanées entre les deux.

CREATE TABLE IF NOT EXISTS stripe_events (
	id          TEXT    PRIMARY KEY,
	type        TEXT    NOT NULL,
	-- Indicatif : l'événement reste tracé même si la commande est supprimée un
	-- jour, sinon le doublon redeviendrait possible.
	order_id    INTEGER REFERENCES orders(id) ON DELETE SET NULL,
	recu_a      TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_stripe_events_order ON stripe_events(order_id);
