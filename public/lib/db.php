<?php
declare(strict_types=1);

/**
 * Accès à la base du module commercial
 * (docs/SPEC-module-commercial.md §5.4).
 *
 * SQLite via PDO, retenu après vérification sur l'hébergement OVH le
 * 25/09/2026 : PHP 8.3.31, pdo_sqlite présent, SQLite 3.27.2, écriture sur
 * disque et mode WAL acceptés. Le repli MySQL prévu par la spécification n'a
 * donc pas lieu d'être.
 *
 * Contraintes de cette version de SQLite, à garder en tête pour les migrations :
 *   - 3.27 est antérieure aux tables STRICT (3.37) : les types ne sont pas
 *     imposés par le moteur, d'où les contraintes CHECK explicites.
 *   - UPSERT (ON CONFLICT DO UPDATE) est disponible depuis 3.24 : utilisable.
 *   - ALTER TABLE DROP COLUMN n'arrive qu'en 3.35 : une migration qui retire
 *     une colonne devra recréer la table.
 *
 * Le fichier de base vit dans data/private/, interdit par .htaccess et exclu
 * de la synchro FTP : ni téléchargeable, ni écrasable par un déploiement.
 */

const BD_DOSSIER = __DIR__ . '/../data/private';
const BD_FICHIER = BD_DOSSIER . '/commerce.sqlite';
const BD_MIGRATIONS = __DIR__ . '/db/migrations';

/**
 * Connexion partagée, ouverte à la demande.
 *
 * @throws RuntimeException si le pilote manque ou si le dossier n'est pas
 *                          utilisable — jamais silencieusement.
 */
function bdCommerce(): PDO
{
	static $pdo = null;
	if ($pdo instanceof PDO) {
		return $pdo;
	}

	if (!extension_loaded('pdo_sqlite')) {
		throw new RuntimeException('pdo_sqlite absent de cet hébergement.');
	}

	if (!is_dir(BD_DOSSIER) && !@mkdir(BD_DOSSIER, 0750, true) && !is_dir(BD_DOSSIER)) {
		throw new RuntimeException('Dossier de base introuvable et impossible à créer.');
	}

	$pdo = new PDO('sqlite:' . BD_FICHIER, null, null, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		// Les requêtes préparées partent au moteur plutôt que d'être
		// interpolées par PDO : c'est la protection réelle contre l'injection.
		PDO::ATTR_EMULATE_PREPARES => false,
	]);

	// SQLite désactive l'intégrité référentielle par défaut, et ce réglage vaut
	// pour la connexion, pas pour le fichier : sans cette ligne, toutes les
	// clés étrangères des migrations seraient purement décoratives. Vérifié sur
	// l'hébergement : PRAGMA foreign_keys y renvoyait bien 0.
	$pdo->exec('PRAGMA foreign_keys = ON');

	// WAL : un lecteur ne bloque plus un rédacteur. Confirmé accepté par le
	// système de fichiers OVH lors de la vérification préalable.
	$pdo->exec('PRAGMA journal_mode = WAL');

	// Cinq secondes d'attente avant d'abandonner sur une base verrouillée,
	// plutôt qu'une erreur immédiate en cas d'écritures concurrentes.
	$pdo->exec('PRAGMA busy_timeout = 5000');

	return $pdo;
}

/** La base a-t-elle déjà été créée sur le disque ? */
function bdExiste(): bool
{
	return is_file(BD_FICHIER);
}

/** Taille du fichier de base, pour l'affichage admin. */
function bdTaille(): int
{
	return bdExiste() ? (int) filesize(BD_FICHIER) : 0;
}

/** @return list<string> noms de fichiers de migration, dans l'ordre */
function migrationsDisponibles(): array
{
	if (!is_dir(BD_MIGRATIONS)) {
		return [];
	}

	$fichiers = glob(BD_MIGRATIONS . '/*.sql') ?: [];
	$noms = array_map('basename', $fichiers);
	// Tri par nom : les migrations sont numérotées (001-, 002-…), donc l'ordre
	// alphabétique est l'ordre chronologique.
	sort($noms, SORT_STRING);

	return $noms;
}

/** Crée la table de suivi si besoin. Volontairement hors migrations : c'est elle qui les trace. */
function bdPreparerSuivi(PDO $pdo): void
{
	$pdo->exec(
		'CREATE TABLE IF NOT EXISTS schema_migrations (
			nom        TEXT PRIMARY KEY,
			applique_a TEXT NOT NULL
		)'
	);
}

/** @return list<string> migrations déjà appliquées */
function migrationsAppliquees(PDO $pdo): array
{
	bdPreparerSuivi($pdo);
	$lignes = $pdo->query('SELECT nom FROM schema_migrations ORDER BY nom')->fetchAll();

	return array_map(static fn (array $l): string => (string) $l['nom'], $lignes);
}

/**
 * Applique les migrations manquantes. Idempotent : relancer ne fait rien de
 * plus, chaque fichier étant enregistré une fois appliqué.
 *
 * Chaque migration s'exécute dans sa propre transaction : si l'une échoue,
 * elle est annulée entièrement et les précédentes restent acquises.
 *
 * @return array{appliquees: list<string>, deja: list<string>, erreur: ?string}
 */
function bdMigrer(): array
{
	$pdo = bdCommerce();
	$deja = migrationsAppliquees($pdo);
	$appliquees = [];

	foreach (migrationsDisponibles() as $nom) {
		if (in_array($nom, $deja, true)) {
			continue;
		}

		$sql = file_get_contents(BD_MIGRATIONS . '/' . $nom);
		if ($sql === false) {
			return ['appliquees' => $appliquees, 'deja' => $deja, 'erreur' => "Lecture impossible : $nom"];
		}

		try {
			$pdo->beginTransaction();
			$pdo->exec($sql);
			$stmt = $pdo->prepare('INSERT INTO schema_migrations (nom, applique_a) VALUES (?, ?)');
			$stmt->execute([$nom, gmdate('c')]);
			$pdo->commit();
			$appliquees[] = $nom;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			return [
				'appliquees' => $appliquees,
				'deja' => $deja,
				'erreur' => "$nom : " . $e->getMessage(),
			];
		}
	}

	return ['appliquees' => $appliquees, 'deja' => $deja, 'erreur' => null];
}

/** @return list<string> tables présentes, hors tables internes SQLite */
function bdTables(): array
{
	$lignes = bdCommerce()
		->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
		->fetchAll();

	return array_map(static fn (array $l): string => (string) $l['name'], $lignes);
}
