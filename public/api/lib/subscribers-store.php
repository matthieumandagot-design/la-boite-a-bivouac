<?php
declare(strict_types=1);

/** @return array{path: string, dataDir: string} */
function subscribersPaths(): array
{
	$dataDir = __DIR__ . '/../../data';
	return ['path' => $dataDir . '/subscribers.json', 'dataDir' => $dataDir];
}

/**
 * Lecture seule sous verrou partagé. Retourne un tableau vide si le fichier
 * n'existe pas encore (aucun abonné inscrit pour l'instant).
 *
 * @return array<int, array{id: string, email: string, createdAt: string, status: string}>
 */
function loadSubscribers(string $path): array
{
	if (!is_file($path)) {
		return [];
	}

	$handle = fopen($path, 'r');
	if ($handle === false) {
		return [];
	}

	flock($handle, LOCK_SH);
	$contents = stream_get_contents($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	$decoded = json_decode((string) $contents, true);
	return is_array($decoded) ? $decoded : [];
}

/**
 * Exécute $mutator sous verrou exclusif pour un cycle lecture-modification-écriture
 * atomique (même pattern que public/ask-question.php), afin d'éviter toute perte
 * de données en cas d'inscriptions concurrentes.
 *
 * $mutator reçoit la liste actuelle des abonnés et doit retourner
 * [nouvelle liste, valeur de retour].
 *
 * @param callable(array): array{0: array, 1: mixed} $mutator
 * @return mixed la valeur de retour choisie par $mutator
 */
function withSubscribersLock(string $dataDir, string $path, callable $mutator)
{
	if (!is_dir($dataDir)) {
		@mkdir($dataDir, 0755, true);
	}

	$handle = fopen($path, 'c+');
	if ($handle === false || !flock($handle, LOCK_EX)) {
		throw new RuntimeException('lock_failed');
	}

	$contents = stream_get_contents($handle);
	$subscribers = json_decode((string) $contents, true);
	if (!is_array($subscribers)) {
		$subscribers = [];
	}

	[$updated, $result] = $mutator($subscribers);

	$encoded = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	ftruncate($handle, 0);
	rewind($handle);
	fwrite($handle, (string) $encoded);
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	return $result;
}

function unsubscribeToken(string $email): string
{
	return hash_hmac('sha256', strtolower($email), UNSUBSCRIBE_SALT);
}
