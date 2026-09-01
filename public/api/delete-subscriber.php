<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): void
{
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	respond(405, ['success' => false, 'error' => 'Méthode non autorisée.']);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
	respond(503, ['success' => false, 'error' => 'Configuration indisponible.']);
}

require $configPath;
require __DIR__ . '/lib/admin-auth.php';
require __DIR__ . '/lib/subscribers-store.php';

requireAdminAuth();

$decoded = json_decode((string) file_get_contents('php://input'), true);
$id = trim((string) ($decoded['id'] ?? ''));

if ($id === '') {
	respond(422, ['success' => false, 'error' => 'Identifiant manquant.']);
}

['path' => $path, 'dataDir' => $dataDir] = subscribersPaths();

$found = withSubscribersLock($dataDir, $path, function (array $subscribers) use ($id) {
	$filtered = array_values(array_filter(
		$subscribers,
		static fn(array $s): bool => ($s['id'] ?? '') !== $id,
	));

	return [$filtered, count($filtered) !== count($subscribers)];
});

if (!$found) {
	respond(404, ['success' => false, 'error' => 'Abonné introuvable.']);
}

respond(200, ['success' => true]);
