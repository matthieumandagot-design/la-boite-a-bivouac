-- Connexion par Google ou Facebook — docs/SPEC-module-commercial.md §6.1
--
-- Une identité sociale est un lien entre un compte client et un compte chez un
-- fournisseur. Un même client peut en avoir plusieurs ; une même identité ne
-- peut appartenir qu'à un seul client.

CREATE TABLE IF NOT EXISTS social_identities (
	id           INTEGER PRIMARY KEY,
	customer_id  INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
	fournisseur  TEXT    NOT NULL CHECK (fournisseur IN ('google', 'facebook')),
	-- Identifiant stable chez le fournisseur : « sub » chez Google, « id » chez
	-- Facebook. C'est LUI qui identifie la personne, jamais l'adresse email :
	-- une adresse se change, se recycle, et n'appartient pas au fournisseur.
	sujet        TEXT    NOT NULL,
	-- Adresse au moment de la liaison, pour information seulement. Ne sert
	-- jamais à retrouver le compte lors des connexions suivantes.
	email        TEXT    NOT NULL DEFAULT '',
	created_at   TEXT    NOT NULL,
	derniere_connexion TEXT,
	-- Deux comptes clients ne peuvent pas revendiquer la même identité sociale.
	UNIQUE (fournisseur, sujet)
);

CREATE INDEX IF NOT EXISTS idx_identites_customer ON social_identities(customer_id);

-- Un compte créé par Google ou Facebook n'a pas de mot de passe. La colonne
-- password_hash reste non nulle — elle reçoit un hash d'une valeur aléatoire
-- jetée, qu'aucun mot de passe ne peut vérifier — mais l'interface doit savoir
-- qu'il n'y en a pas : pour proposer d'en définir un, et pour ne pas demander
-- un mot de passe inexistant au moment de supprimer le compte.
ALTER TABLE customers ADD COLUMN password_defini INTEGER NOT NULL DEFAULT 1;
