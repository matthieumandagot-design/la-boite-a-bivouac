<?php
declare(strict_types=1);

/**
 * Réglages du module commercial (voir docs/SPEC-module-commercial.md §5.1).
 *
 * Deux fichiers, même mécanique que la FAQ et le catalogue d'équipements :
 *
 *   data/settings.default.json  versionné dans le dépôt, sert de repli
 *   data/settings.json          écrit en production, exclu de la synchro FTP
 *
 * Le fichier live n'existe donc pas tant que personne n'a touché à un réglage :
 * la lecture retombe silencieusement sur le fichier par défaut, qui laisse le
 * module désactivé. C'est voulu — l'état sûr est l'état par défaut.
 */

const SETTINGS_DIR = __DIR__ . '/../data';
const SETTINGS_LIVE = SETTINGS_DIR . '/settings.json';
const SETTINGS_DEFAULT = SETTINGS_DIR . '/settings.default.json';

/**
 * Valeurs de repli si les deux fichiers manquent ou sont illisibles. Codées ici
 * plutôt que seulement dans le JSON : un fichier corrompu ne doit jamais
 * pouvoir activer la boutique par accident.
 *
 * @return array{commerce_enabled: bool, payment_mode: string, updated_at: ?string}
 */
function settingsFallback(): array
{
	return [
		'commerce_enabled' => false,
		'payment_mode' => 'test',
		'updated_at' => null,
	];
}

/**
 * Normalise ce qui sort d'un fichier : on ne fait confiance ni au type ni à la
 * présence des clés. Toute valeur douteuse retombe sur le repli.
 *
 * @param mixed $brut
 * @return array{commerce_enabled: bool, payment_mode: string, updated_at: ?string}
 */
function normaliseSettings($brut): array
{
	$defaut = settingsFallback();
	if (!is_array($brut)) {
		return $defaut;
	}

	// Seul le booléen vrai active le module. Ni "true", ni 1, ni "oui" :
	// une valeur mal typée doit laisser la boutique fermée.
	$actif = array_key_exists('commerce_enabled', $brut) && $brut['commerce_enabled'] === true;

	$mode = $brut['payment_mode'] ?? null;
	if ($mode !== 'live') {
		// Tout ce qui n'est pas explicitement "live" est traité comme "test" :
		// on ne bascule jamais en paiement réel par accident.
		$mode = 'test';
	}

	$maj = $brut['updated_at'] ?? null;
	if (!is_string($maj) || $maj === '') {
		$maj = null;
	}

	return [
		'commerce_enabled' => $actif,
		'payment_mode' => $mode,
		'updated_at' => $maj,
	];
}

/** Lit un fichier JSON sous verrou partagé. Retourne null si absent ou illisible. */
function lireJsonVerrouille(string $chemin)
{
	if (!is_file($chemin)) {
		return null;
	}

	$handle = fopen($chemin, 'r');
	if ($handle === false) {
		return null;
	}

	flock($handle, LOCK_SH);
	$contenu = stream_get_contents($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	return json_decode((string) $contenu, true);
}

/**
 * Réglages effectifs : le fichier live s'il existe, sinon celui par défaut,
 * sinon les valeurs codées en dur.
 *
 * @return array{commerce_enabled: bool, payment_mode: string, updated_at: ?string}
 */
function lireSettings(): array
{
	$live = lireJsonVerrouille(SETTINGS_LIVE);
	if (is_array($live)) {
		return normaliseSettings($live);
	}

	return normaliseSettings(lireJsonVerrouille(SETTINGS_DEFAULT));
}

/**
 * Écrit les réglages sous verrou exclusif. Horodate la modification.
 *
 * @throws RuntimeException si le verrou ou l'écriture échoue
 */
function ecrireSettings(bool $commerceActif, string $modePaiement): array
{
	if ($modePaiement !== 'live') {
		$modePaiement = 'test';
	}

	if (!is_dir(SETTINGS_DIR)) {
		@mkdir(SETTINGS_DIR, 0755, true);
	}

	$handle = fopen(SETTINGS_LIVE, 'c+');
	if ($handle === false || !flock($handle, LOCK_EX)) {
		throw new RuntimeException('Impossible de verrouiller le fichier de réglages.');
	}

	$valeurs = [
		'commerce_enabled' => $commerceActif,
		'payment_mode' => $modePaiement,
		'updated_at' => gmdate('c'),
	];

	$encode = json_encode($valeurs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	ftruncate($handle, 0);
	rewind($handle);
	fwrite($handle, (string) $encode . "\n");
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	return $valeurs;
}

/** Raccourci de lecture : le module commercial est-il ouvert au public ? */
function commerceActif(): bool
{
	return lireSettings()['commerce_enabled'];
}
