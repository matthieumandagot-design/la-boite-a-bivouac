<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
	http_response_code(503);
	echo json_encode(['success' => false, 'error' => 'Configuration indisponible.'], JSON_UNESCAPED_UNICODE);
	exit;
}

require $configPath;
require __DIR__ . '/lib/admin-auth.php';
require __DIR__ . '/lib/subscribers-store.php';

requireAdminAuth();

['path' => $path] = subscribersPaths();
$subscribers = loadSubscribers($path);

$output = array_map(static fn(array $s): array => [
	'id' => $s['id'] ?? '',
	'email' => $s['email'] ?? '',
	'created_at' => $s['createdAt'] ?? '',
	'status' => $s['status'] ?? 'active',
], $subscribers);

// Plus récent en premier.
usort($output, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

echo json_encode($output, JSON_UNESCAPED_UNICODE);
