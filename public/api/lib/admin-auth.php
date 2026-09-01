<?php
declare(strict_types=1);

/**
 * Authentification HTTP Basic partagée avec /admin/faq.php (voir
 * public/admin/faq.php), réutilisant les mêmes identifiants
 * ADMIN_USERNAME / ADMIN_PASSWORD_HASH pour n'avoir qu'un seul compte admin.
 */

/** @return array{0: string, 1: string}|null */
function adminBasicAuthCredentials(): ?array
{
	if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
		return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']];
	}

	$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
	if ($header === null && function_exists('apache_request_headers')) {
		$headers = apache_request_headers();
		$header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
	}

	if ($header !== null && stripos($header, 'Basic ') === 0) {
		$decoded = base64_decode(substr($header, 6), true);
		if ($decoded !== false && str_contains($decoded, ':')) {
			return explode(':', $decoded, 2);
		}
	}

	return null;
}

function requireAdminAuth(): void
{
	[$user, $pass] = adminBasicAuthCredentials() ?? [null, null];

	$authorized = $user !== null
		&& $pass !== null
		&& defined('ADMIN_USERNAME')
		&& defined('ADMIN_PASSWORD_HASH')
		&& hash_equals(ADMIN_USERNAME, $user)
		&& password_verify($pass, ADMIN_PASSWORD_HASH);

	if (!$authorized) {
		header('WWW-Authenticate: Basic realm="Administration"');
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(401);
		echo json_encode(['success' => false, 'error' => 'Authentification requise.'], JSON_UNESCAPED_UNICODE);
		exit;
	}
}
