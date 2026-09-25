<?php
declare(strict_types=1);

/**
 * SONDE TEMPORAIRE — À SUPPRIMER APRÈS LECTURE.
 *
 * Vérifie ce que l'hébergement mutualisé OVH permet réellement, avant de
 * choisir le socle de stockage du module commercial (voir
 * docs/SPEC-module-commercial.md §5.4 : SQLite via PDO, sinon MySQL OVH).
 *
 * Le nom du fichier contient un jeton aléatoire : l'URL n'est pas devinable,
 * et la sonde ne révèle qu'un diagnostic technique, aucune donnée.
 * Elle est retirée dès la mesure faite.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store');

$resultat = [
	'php' => PHP_VERSION,
	'pdo_charge' => extension_loaded('pdo'),
	'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
	'pdo_sqlite' => extension_loaded('pdo_sqlite'),
	'sqlite3_ext' => extension_loaded('sqlite3'),
	'pdo_mysql' => extension_loaded('pdo_mysql'),
];

// Version de la bibliothèque SQLite : en dessous de 3.8.3 les CTE et certaines
// contraintes manquent ; au-dessus de 3.24 on dispose de UPSERT, utile pour
// rendre les migrations et le webhook idempotents.
if ($resultat['pdo_sqlite']) {
	try {
		$memoire = new PDO('sqlite::memory:');
		$resultat['sqlite_version'] = $memoire->query('SELECT sqlite_version()')->fetchColumn();
		$memoire->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, montant INTEGER NOT NULL)');
		$memoire->exec("INSERT INTO t (montant) VALUES (1990)");
		$resultat['sqlite_ecriture_memoire'] = $memoire->query('SELECT montant FROM t')->fetchColumn() === 1990;
		$resultat['sqlite_foreign_keys'] = (bool) $memoire->query('PRAGMA foreign_keys')->fetchColumn();
	} catch (Throwable $e) {
		$resultat['sqlite_erreur'] = $e->getMessage();
	}
}

// Écriture réelle sur disque : un pilote chargé ne garantit pas que
// l'hébergement laisse créer un fichier de base hors du dossier web servi.
$dossierTest = __DIR__ . '/_sonde-tmp';
try {
	if (!is_dir($dossierTest)) {
		mkdir($dossierTest, 0750, true);
	}
	$resultat['dossier_creable'] = is_dir($dossierTest);

	if ($resultat['pdo_sqlite'] && $resultat['dossier_creable']) {
		$fichier = $dossierTest . '/essai.sqlite';
		$db = new PDO('sqlite:' . $fichier);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->exec('CREATE TABLE IF NOT EXISTS essai (id INTEGER PRIMARY KEY)');
		$db->exec('INSERT INTO essai DEFAULT VALUES');
		// WAL réduit les blocages en écriture concurrente ; tous les systèmes
		// de fichiers mutualisés ne l'acceptent pas, d'où le test.
		$wal = $db->query('PRAGMA journal_mode=WAL')->fetchColumn();
		$resultat['sqlite_fichier_ecrit'] = is_file($fichier) && filesize($fichier) > 0;
		$resultat['sqlite_journal_mode'] = $wal;
		$db = null;
		@unlink($fichier);
		@unlink($fichier . '-wal');
		@unlink($fichier . '-shm');
	}
} catch (Throwable $e) {
	$resultat['erreur_disque'] = $e->getMessage();
} finally {
	@rmdir($dossierTest);
}

$resultat['fonctions_utiles'] = [
	'curl' => function_exists('curl_init'),
	'hash_hmac' => function_exists('hash_hmac'),
	'password_hash' => function_exists('password_hash'),
	'flock' => function_exists('flock'),
	'random_bytes' => function_exists('random_bytes'),
];

echo json_encode($resultat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
