<?php
declare(strict_types=1);

/**
 * Limitation de débit par IP, pour freiner les envois répétés (formulaire de
 * contact) et les inscriptions en masse (newsletter) — en complément des
 * champs honeypot déjà en place. Stockage dans un fichier JSON partagé avec
 * verrou exclusif, même pattern que public/api/lib/subscribers-store.php.
 *
 * Basé sur REMOTE_ADDR, jamais sur des en-têtes comme X-Forwarded-For : ces
 * en-têtes sont fournis par le client et un script abusif pourrait en
 * changer la valeur à chaque requête pour contourner la limite.
 */

function clientIp(): string
{
	return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Retourne true si la limite est dépassée pour cette clé (typiquement
 * "endpoint:ip"). Enregistre la tentative courante uniquement si elle n'est
 * pas déjà bloquée, pour ne pas prolonger indéfiniment la fenêtre de blocage
 * tant qu'un client continue de réessayer.
 */
function isRateLimited(string $key, int $maxAttempts, int $windowSeconds): bool
{
	$dataDir = __DIR__ . '/../data';
	$path = $dataDir . '/rate-limits.json';

	if (!is_dir($dataDir)) {
		@mkdir($dataDir, 0755, true);
	}

	$handle = fopen($path, 'c+');
	if ($handle === false || !flock($handle, LOCK_EX)) {
		// En cas d'échec du verrou (disque plein, permissions...), on laisse
		// passer plutôt que de casser une fonctionnalité publique du site.
		return false;
	}

	$contents = stream_get_contents($handle);
	$store = json_decode((string) $contents, true);
	if (!is_array($store)) {
		$store = [];
	}

	$now = time();
	$isTimestamp = static fn($value): bool => is_int($value);

	$attempts = array_values(array_filter(
		is_array($store[$key] ?? null) ? $store[$key] : [],
		static fn($timestamp) => $isTimestamp($timestamp) && $timestamp > $now - $windowSeconds,
	));

	$limited = count($attempts) >= $maxAttempts;

	if (!$limited) {
		$attempts[] = $now;
	}
	$store[$key] = $attempts;

	// Purge les entrées de plus de 24h, toutes clés confondues, pour éviter
	// que le fichier ne grossisse indéfiniment.
	foreach ($store as $storedKey => $timestamps) {
		$fresh = array_values(array_filter(
			is_array($timestamps) ? $timestamps : [],
			static fn($timestamp) => $isTimestamp($timestamp) && $timestamp > $now - 86400,
		));

		if ($fresh === []) {
			unset($store[$storedKey]);
		} else {
			$store[$storedKey] = $fresh;
		}
	}

	$encoded = json_encode($store, JSON_UNESCAPED_UNICODE);
	ftruncate($handle, 0);
	rewind($handle);
	fwrite($handle, (string) $encoded);
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	return $limited;
}
