-- Vérification de l'adresse email à l'inscription
-- docs/SPEC-module-commercial.md §6.1, point noté « P1 »
--
-- Une adresse non vérifiée est une commande qu'on ne peut pas confirmer : ni
-- accusé de réception, ni suivi, ni contact en cas de problème sur une boîte
-- fabriquée sur mesure. La vérification conditionne donc le passage de
-- commande, pas la connexion — voir le commentaire de lib/verification-email.php.
--
-- NULL signifie « pas encore vérifiée ». Aucun compte n'existe en production au
-- moment de cette migration : personne n'est rétroactivement bloqué.

ALTER TABLE customers ADD COLUMN email_verifie_a TEXT;

-- Même mécanique que password_resets, et pour les mêmes raisons : le jeton
-- n'est jamais stocké en clair, une fuite de la base ne doit pas permettre de
-- valider des adresses à la place de leurs titulaires.
CREATE TABLE IF NOT EXISTS email_verifications (
	id          INTEGER PRIMARY KEY,
	customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
	token_hash  TEXT    NOT NULL UNIQUE,
	expires_at  TEXT    NOT NULL,
	used_at     TEXT,
	created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_verifications_customer ON email_verifications(customer_id);
