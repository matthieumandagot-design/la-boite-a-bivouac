-- Socle du module commercial — docs/SPEC-module-commercial.md §5.4
--
-- Conventions de ce schéma :
--   * Montants en CENTIMES, toujours des entiers. Jamais de flottant pour de
--     l'argent : 0.1 + 0.2 ne vaut pas 0.3 en binaire.
--   * Horodatages en TEXT ISO-8601 UTC (gmdate('c')). SQLite n'a pas de type
--     date ; le format ISO se trie correctement en ordre alphabétique.
--   * Booléens en INTEGER 0/1, avec CHECK. SQLite 3.27 ne connaît pas les
--     tables STRICT (3.37+), donc les types ne sont pas imposés par le moteur :
--     les contraintes CHECK font ce travail explicitement.

CREATE TABLE IF NOT EXISTS customers (
	id            INTEGER PRIMARY KEY,
	email         TEXT    NOT NULL UNIQUE,
	password_hash TEXT    NOT NULL,
	prenom        TEXT    NOT NULL DEFAULT '',
	nom           TEXT    NOT NULL DEFAULT '',
	created_at    TEXT    NOT NULL,
	updated_at    TEXT,
	-- Suppression de compte : le client est anonymisé mais la ligne subsiste,
	-- car les commandes qui la référencent doivent être conservées pour les
	-- obligations comptables (§6.1).
	anonymise_a   TEXT
);

CREATE TABLE IF NOT EXISTS addresses (
	id          INTEGER PRIMARY KEY,
	customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
	libelle     TEXT    NOT NULL DEFAULT '',
	ligne1      TEXT    NOT NULL,
	ligne2      TEXT    NOT NULL DEFAULT '',
	code_postal TEXT    NOT NULL,
	ville       TEXT    NOT NULL,
	pays        TEXT    NOT NULL DEFAULT 'FR',
	telephone   TEXT    NOT NULL DEFAULT '',
	created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_addresses_customer ON addresses(customer_id);

CREATE TABLE IF NOT EXISTS password_resets (
	id          INTEGER PRIMARY KEY,
	customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
	-- Le jeton n'est jamais stocké en clair : une fuite de la base ne doit pas
	-- permettre de réinitialiser des mots de passe (§6.1).
	token_hash  TEXT    NOT NULL UNIQUE,
	expires_at  TEXT    NOT NULL,
	used_at     TEXT,
	created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_resets_customer ON password_resets(customer_id);

CREATE TABLE IF NOT EXISTS products (
	id          INTEGER PRIMARY KEY,
	type        TEXT    NOT NULL CHECK (type IN ('boite', 'accessoire')),
	nom         TEXT    NOT NULL,
	description TEXT    NOT NULL DEFAULT '',
	prix_cents  INTEGER NOT NULL CHECK (prix_cents >= 0),
	photo       TEXT    NOT NULL DEFAULT '',
	visible     INTEGER NOT NULL DEFAULT 0 CHECK (visible IN (0, 1)),
	actif       INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
	created_at  TEXT    NOT NULL,
	updated_at  TEXT
);

CREATE TABLE IF NOT EXISTS orders (
	id          INTEGER PRIMARY KEY,
	-- Format BAB-AAAA-NNNN (§6.4). Unique : sert aussi de clé d'idempotence
	-- côté Stripe, un même numéro ne doit pas créer deux paiements.
	numero      TEXT    NOT NULL UNIQUE,
	customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
	statut      TEXT    NOT NULL CHECK (statut IN (
		'en_attente_paiement', 'payee', 'en_fabrication',
		'prete', 'expediee', 'livree', 'annulee', 'remboursee'
	)),
	total_cents INTEGER NOT NULL CHECK (total_cents >= 0),
	-- Version des CGV acceptée au moment de la commande : elles peuvent changer
	-- après coup, la commande doit garder celles qui lui étaient applicables.
	cgv_version TEXT    NOT NULL DEFAULT '',
	-- Mode du paiement : distingue une commande de test d'une vraie vente, et
	-- alimente le badge « TEST » de l'admin (§6.5).
	payment_mode TEXT   NOT NULL DEFAULT 'test' CHECK (payment_mode IN ('test', 'live')),
	stripe_session_id        TEXT,
	stripe_payment_intent_id TEXT,
	adresse_livraison TEXT NOT NULL DEFAULT '',
	note_interne      TEXT NOT NULL DEFAULT '',
	created_at  TEXT    NOT NULL,
	updated_at  TEXT,
	paid_at     TEXT
);

CREATE INDEX IF NOT EXISTS idx_orders_customer ON orders(customer_id);
CREATE INDEX IF NOT EXISTS idx_orders_statut   ON orders(statut);
CREATE INDEX IF NOT EXISTS idx_orders_session  ON orders(stripe_session_id);

CREATE TABLE IF NOT EXISTS order_items (
	id         INTEGER PRIMARY KEY,
	order_id   INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
	-- Référence indicative seulement : le produit peut être modifié ou retiré
	-- après la commande. ON DELETE SET NULL, jamais CASCADE — supprimer un
	-- article du catalogue ne doit pas amputer une commande passée.
	product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
	-- Instantané immuable (§6.4) : libellé et prix tels qu'ils étaient au
	-- moment de la commande, indépendants du catalogue d'aujourd'hui.
	libelle              TEXT    NOT NULL,
	prix_unitaire_cents  INTEGER NOT NULL CHECK (prix_unitaire_cents >= 0),
	quantite             INTEGER NOT NULL CHECK (quantite > 0),
	total_cents          INTEGER NOT NULL CHECK (total_cents >= 0),
	-- Configuration de la boîte en JSON : remorque retenue ou cotes saisies,
	-- options, et dimensions calculées par le serveur.
	configuration        TEXT    NOT NULL DEFAULT '',
	created_at           TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_items_order ON order_items(order_id);
