<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow');

function renderPage(string $message): void
{
	?>
	<!doctype html>
	<html lang="fr">
		<head>
			<meta charset="utf-8" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<meta name="robots" content="noindex, nofollow" />
			<title>Désinscription — La Boîte à Bivouac</title>
		</head>
		<body style="margin:0;background:#f5ecd8;color:#122218;font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:2rem;">
			<main style="max-width:480px;">
				<p style="text-transform:uppercase;letter-spacing:.05em;font-size:.75rem;font-weight:600;color:#b88a18;margin:0 0 .5rem;">
					La Boîte à Bivouac
				</p>
				<h1 style="font-size:1.5rem;font-weight:700;margin:0 0 1.5rem;">
					<?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
				</h1>
				<a href="https://laboiteabivouac.fr" style="color:#b85438;font-weight:600;">Retour au site</a>
			</main>
		</body>
	</html>
	<?php
	exit;
}

$email = trim((string) ($_GET['email'] ?? ''));
$token = (string) ($_GET['token'] ?? '');

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
	renderPage('Configuration indisponible.');
}

require $configPath;
require __DIR__ . '/lib/subscribers-store.php';

if ($email === '' || $token === '' || !hash_equals(unsubscribeToken($email), $token)) {
	renderPage('Lien de désinscription invalide.');
}

$email = strtolower($email);
['path' => $path, 'dataDir' => $dataDir] = subscribersPaths();

$found = withSubscribersLock($dataDir, $path, function (array $subscribers) use ($email) {
	$found = false;
	foreach ($subscribers as $index => $entry) {
		if (strtolower((string) ($entry['email'] ?? '')) === $email) {
			$subscribers[$index]['status'] = 'unsubscribed';
			$found = true;
		}
	}
	return [$subscribers, $found];
});

renderPage($found ? 'Vous avez été désinscrit avec succès.' : 'Adresse introuvable.');
