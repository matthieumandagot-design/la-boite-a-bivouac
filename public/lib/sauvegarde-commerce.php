<?php
declare(strict_types=1);

/**
 * Sauvegarde de la base du module commercial (clients, commandes, catalogue).
 *
 * À lancer par une tâche planifiée OVH, en PHP 8.3 et en mode CLI :
 *     laboiteabivouac/lib/sauvegarde-commerce.php
 *
 * Pourquoi pas une simple copie du fichier : la base tourne en WAL (voir
 * db.php). Copier commerce.sqlite pendant qu'une écriture est en cours donne
 * un fichier incohérent, car les transactions récentes vivent encore dans
 * commerce.sqlite-wal. VACUUM INTO demande à SQLite d'écrire lui-même une
 * copie propre, complète et compactée, sans bloquer les lecteurs. Disponible
 * depuis SQLite 3.27.0 ; l'hébergement est en 3.27.2 (vérifié le 25/09/2026),
 * tout juste — d'où le repli plus bas, au cas où.
 *
 * Les sauvegardes sont écrites dans le dossier parent du site, hors du dossier
 * web et hors de la synchronisation FTP : ni téléchargeables par un visiteur,
 * ni écrasées par un déploiement.
 */

// Ce dossier est servi par le web. Une sauvegarde déclenchée par un visiteur
// n'aurait aucun sens, et la liste des fichiers encore moins : hors CLI, ce
// script n'existe pas.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

// Les dates de ce script sont lues par un humain : nom de fichier et journal.
// En UTC, une sauvegarde lancée à 1 h du matin l'été porterait la date de la
// veille. Le reste du module continue d'horodater ses données en UTC.
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/db.php';

// lib/ -> laboiteabivouac/ -> le dossier du compte, qui n'est pas publié.
const SAUV_DOSSIER = __DIR__ . '/../../sauvegardes-commerce';
const SAUV_JOURS = 30;        // durée de conservation des sauvegardes quotidiennes
const SAUV_JOURS_MENSUELLE = 365; // celles du 1er du mois sont gardées un an
const SAUV_PLANCHER = 7;      // nombre minimal de fichiers conservés, quoi qu'il arrive

function sauvTaille(int $octets): string
{
	return $octets >= 1048576
		? number_format($octets / 1048576, 1, ',', ' ') . ' Mo'
		: number_format($octets / 1024, 1, ',', ' ') . ' Ko';
}

function sauvJournal(string $ligne): void
{
	$horodatage = date('Y-m-d H:i:s');
	echo $ligne, "\n";
	@file_put_contents(SAUV_DOSSIER . '/journal.log', "$horodatage  $ligne\n", FILE_APPEND | LOCK_EX);
}

/**
 * Sort en échec avec un code non nul : la tâche planifiée OVH le signale, et
 * le message part sur STDERR pour apparaître dans le rapport d'exécution.
 */
function sauvEchec(string $motif): never
{
	$ligne = 'ÉCHEC — ' . $motif;
	fwrite(STDERR, $ligne . "\n");
	@file_put_contents(SAUV_DOSSIER . '/journal.log', date('Y-m-d H:i:s') . "  $ligne\n", FILE_APPEND | LOCK_EX);
	exit(1);
}

// ---------------------------------------------------------------- préalables

if (!is_file(BD_FICHIER)) {
	// Avant la première utilisation du module, il n'y a rien à sauvegarder.
	// Ce n'est pas une erreur : la tâche planifiée ne doit pas alerter pour ça.
	echo "Aucune base à sauvegarder (" . BD_FICHIER . " n'existe pas encore).\n";
	exit(0);
}

if (!is_dir(SAUV_DOSSIER) && !@mkdir(SAUV_DOSSIER, 0700, true) && !is_dir(SAUV_DOSSIER)) {
	sauvEchec('dossier de sauvegarde impossible à créer : ' . SAUV_DOSSIER);
}

$nom = 'commerce-' . date('Y-m-d') . '.sqlite.gz';
$cible = SAUV_DOSSIER . '/' . $nom;
$temp = SAUV_DOSSIER . '/.en-cours-' . getmypid() . '.sqlite';
@unlink($temp); // VACUUM INTO refuse d'écrire sur un fichier existant

// ------------------------------------------------------- copie cohérente

try {
	$pdo = bdCommerce();
	$version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();

	if (version_compare($version, '3.27.0', '>=')) {
		// Les guillemets doublés protègent un chemin qui en contiendrait ;
		// VACUUM INTO n'accepte pas de paramètre lié sur toutes les versions.
		$pdo->exec("VACUUM INTO '" . str_replace("'", "''", $temp) . "'");
		$methode = 'VACUUM INTO';
	} else {
		// Repli pour une version antérieure à 3.27 : on replie le WAL dans le
		// fichier principal, puis on copie en tenant un verrou d'écriture pour
		// qu'aucune transaction ne s'intercale pendant la copie.
		$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
		$pdo->beginTransaction();
		$pdo->exec('CREATE TABLE IF NOT EXISTS _verrou_sauvegarde (x INTEGER)'); // force un verrou d'écriture
		$copie = @copy(BD_FICHIER, $temp);
		$pdo->rollBack();
		if (!$copie) {
			sauvEchec('copie de repli impossible (SQLite ' . $version . ')');
		}
		$methode = 'copie verrouillée (SQLite ' . $version . ')';
	}
} catch (Throwable $e) {
	@unlink($temp);
	sauvEchec('lecture de la base impossible : ' . $e->getMessage());
}

if (!is_file($temp) || filesize($temp) === 0) {
	@unlink($temp);
	sauvEchec('la copie est vide');
}

// ------------------------------------------- la copie est-elle exploitable ?

try {
	$copie = new PDO('sqlite:' . $temp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$integrite = (string) $copie->query('PRAGMA integrity_check')->fetchColumn();
	if ($integrite !== 'ok') {
		sauvEchec('copie corrompue : ' . $integrite);
	}
	// Un fichier peut être intègre et vide de sens : on vérifie que le schéma
	// attendu est là et lisible.
	$tables = (int) $copie->query(
		"SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name IN ('customers', 'orders', 'order_items')"
	)->fetchColumn();
	$commandes = (int) $copie->query('SELECT count(*) FROM orders')->fetchColumn();
	$clients = (int) $copie->query('SELECT count(*) FROM customers')->fetchColumn();
	$copie = null;
	if ($tables !== 3) {
		sauvEchec('schéma incomplet dans la copie (' . $tables . ' tables sur 3)');
	}
} catch (Throwable $e) {
	@unlink($temp);
	sauvEchec('copie illisible : ' . $e->getMessage());
}

// ------------------------------------------------------------- compression

$empreinte = hash_file('sha256', $temp);
$tailleBrute = (int) filesize($temp);

$entree = @fopen($temp, 'rb');
$sortie = @gzopen($cible, 'wb9');
if ($entree === false || $sortie === false) {
	@unlink($temp);
	sauvEchec('écriture impossible dans ' . SAUV_DOSSIER);
}
while (!feof($entree)) {
	$bloc = fread($entree, 1 << 20);
	if ($bloc === false || ($bloc !== '' && gzwrite($sortie, $bloc) === 0)) {
		fclose($entree);
		gzclose($sortie);
		@unlink($temp);
		@unlink($cible);
		sauvEchec('écriture interrompue (disque plein ?)');
	}
}
fclose($entree);
gzclose($sortie);
@unlink($temp);

// Relecture complète de l'archive : une sauvegarde qu'on n'a pas relue n'est
// pas une sauvegarde. On recalcule l'empreinte à partir du fichier compressé.
$controle = hash_init('sha256');
$lecture = @gzopen($cible, 'rb');
if ($lecture === false) {
	sauvEchec('archive illisible juste après écriture : ' . $nom);
}
while (!gzeof($lecture)) {
	$bloc = gzread($lecture, 1 << 20);
	if ($bloc === false) {
		gzclose($lecture);
		sauvEchec('archive tronquée : ' . $nom);
	}
	hash_update($controle, $bloc);
}
gzclose($lecture);
if (!hash_equals($empreinte, hash_final($controle))) {
	@unlink($cible);
	sauvEchec('l\'archive relue ne correspond pas à la copie (' . $nom . ')');
}

@chmod($cible, 0600);

// ------------------------------------------------------------------ rotation

$fichiers = glob(SAUV_DOSSIER . '/commerce-*.sqlite.gz') ?: [];
rsort($fichiers); // les noms sont datés : l'ordre alphabétique inverse est l'ordre chronologique inverse
$aujourdhui = new DateTimeImmutable('today');
$supprimes = 0;

foreach ($fichiers as $rang => $fichier) {
	if ($rang < SAUV_PLANCHER) {
		continue; // filet de sécurité : on ne descend jamais sous 7 sauvegardes
	}
	if (!preg_match('/commerce-(\d{4}-\d{2}-(\d{2}))\.sqlite\.gz$/', basename($fichier), $m)) {
		continue; // fichier étranger : on n'y touche pas
	}
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
	if ($date === false) {
		continue;
	}
	$age = (int) $aujourdhui->diff($date)->days;
	// Les sauvegardes du 1er du mois tiennent un an, les autres un mois.
	$limite = $m[2] === '01' ? SAUV_JOURS_MENSUELLE : SAUV_JOURS;
	if ($age > $limite && @unlink($fichier)) {
		$supprimes++;
	}
}

$restants = count(glob(SAUV_DOSSIER . '/commerce-*.sqlite.gz') ?: []);

sauvJournal(sprintf(
	'OK  %s  %s (base %s, %s)  %d client(s), %d commande(s)  %d conservée(s)%s',
	$nom,
	sauvTaille((int) filesize($cible)),
	sauvTaille($tailleBrute),
	$methode,
	$clients,
	$commandes,
	$restants,
	$supprimes > 0 ? ", $supprimes supprimée(s)" : ''
));

exit(0);
