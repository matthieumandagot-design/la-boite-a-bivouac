<?php
declare(strict_types=1);

// Empêche l'indexation par les moteurs de recherche, en plus de robots.txt.
header('X-Robots-Tag: noindex, nofollow', true);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
	// config.php est généré au déploiement (voir scripts/write-admin-config.mjs) et n'existe pas en local.
	http_response_code(503);
	echo 'Configuration admin manquante.';
	exit;
}

require $configPath;

function denyAccess(): void
{
	header('WWW-Authenticate: Basic realm="Administration"');
	http_response_code(401);
	echo 'Accès refusé.';
	exit;
}

/**
 * Sur PHP en CGI/FastCGI (courant en hébergement mutualisé), l'en-tête
 * Authorization n'est pas exposé via PHP_AUTH_USER/PHP_AUTH_PW : il faut le
 * relire manuellement dans HTTP_AUTHORIZATION (voir .htaccess ci-contre qui
 * force Apache à le transmettre).
 */
function basicAuthCredentials(): ?array
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

[$user, $pass] = basicAuthCredentials() ?? [null, null];

if ($user === null || $pass === null) {
	denyAccess();
}

// hash_equals pour une comparaison de l'identifiant à temps constant, comme password_verify pour le mot de passe.
if (!hash_equals(ADMIN_USERNAME, $user) || !password_verify($pass, ADMIN_PASSWORD_HASH)) {
	denyAccess();
}
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Admin — La Boîte à Bivouac</title>
	</head>
	<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5ecd8;color:#122218;font-family:system-ui,sans-serif;">
		<p>Espace admin — à venir.</p>
	</body>
</html>
