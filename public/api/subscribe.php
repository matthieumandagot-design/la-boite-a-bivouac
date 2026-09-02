<?php
declare(strict_types=1);

// Les avertissements PHP ne doivent jamais polluer la réponse JSON ni exposer
// de chemins serveur : on les laisse aller au log plutôt qu'à la sortie.
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

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
	// config.php est généré au déploiement (voir scripts/write-api-config.mjs) et n'existe pas en local.
	respond(503, ['success' => false, 'error' => 'Configuration indisponible.']);
}

require $configPath;
require __DIR__ . '/lib/subscribers-store.php';
require __DIR__ . '/lib/smtp.php';
require __DIR__ . '/../lib/rate-limit.php';

// Limite les inscriptions en masse depuis une même adresse IP (script
// abusif ajoutant de nombreuses adresses d'un coup) : au-delà, on bloque
// avant même de valider l'email ou de toucher au fichier des abonnés.
if (isRateLimited('subscribe:' . clientIp(), 10, 600)) {
	respond(429, ['success' => false, 'error' => 'Trop de tentatives. Merci de réessayer dans quelques minutes.']);
}

// Accepte { email } en JSON ou en form-data.
$rawBody = (string) file_get_contents('php://input');
$decoded = json_decode($rawBody, true);
$email = trim((string) ($decoded['email'] ?? $_POST['email'] ?? ''));
$honeypot = trim((string) ($decoded['website'] ?? $_POST['website'] ?? ''));

// Champ honeypot : un humain ne le remplit jamais. S'il est rempli, on
// ignore silencieusement la requête sans jamais l'enregistrer.
if ($honeypot !== '') {
	respond(200, ['success' => true]);
}

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
	respond(422, ['success' => false, 'error' => 'Merci de saisir une adresse email valide.']);
}

$email = strtolower($email);
['path' => $path, 'dataDir' => $dataDir] = subscribersPaths();

try {
	$outcome = withSubscribersLock($dataDir, $path, function (array $subscribers) use ($email) {
		foreach ($subscribers as $index => $entry) {
			if (strtolower((string) ($entry['email'] ?? '')) === $email) {
				if (($entry['status'] ?? 'active') === 'unsubscribed') {
					$subscribers[$index]['status'] = 'active';
					return [$subscribers, 'reactivated'];
				}

				return [$subscribers, 'exists'];
			}
		}

		$subscribers[] = [
			'id' => bin2hex(random_bytes(8)),
			'email' => $email,
			'createdAt' => gmdate('c'),
			'status' => 'active',
		];

		return [$subscribers, 'created'];
	});
} catch (RuntimeException) {
	respond(500, ['success' => false, 'error' => "Impossible d'enregistrer votre inscription pour le moment. Merci de réessayer plus tard."]);
}

if ($outcome === 'exists') {
	respond(409, ['success' => false, 'error' => 'Cette adresse est déjà inscrite.']);
}

$token = unsubscribeToken($email);
$unsubscribeUrl = 'https://laboiteabivouac.fr/api/unsubscribe.php?email=' . urlencode($email) . '&token=' . $token;

$body = "Vous êtes inscrit aux actualités de La Boîte à Bivouac. Vous serez alerté dès qu'une news est publiée.\n\n"
	. "Pour vous désinscrire : {$unsubscribeUrl}\n";

try {
	$smtp = new SimpleSmtp(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
	$smtp->connect();
	$smtp->sendMail($email, 'Bienvenue — La Boîte à Bivouac', $body, $unsubscribeUrl);
	$smtp->quit();
} catch (SmtpException) {
	// L'inscription est déjà enregistrée : un échec d'envoi de l'email de
	// bienvenue ne doit pas faire échouer l'inscription elle-même.
}

respond(200, ['success' => true]);
