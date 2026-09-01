<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(0);

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
require __DIR__ . '/lib/smtp.php';

requireAdminAuth();

$decoded = json_decode((string) file_get_contents('php://input'), true);
$subject = trim((string) ($decoded['subject'] ?? ''));
$message = trim((string) ($decoded['message'] ?? ''));

if ($subject === '' || $message === '') {
	respond(422, ['success' => false, 'error' => 'Le sujet et le message sont requis.']);
}

['path' => $path] = subscribersPaths();
$subscribers = loadSubscribers($path);
$active = array_values(array_filter($subscribers, static fn(array $s): bool => ($s['status'] ?? '') === 'active'));

if ($active === []) {
	respond(200, ['success' => true, 'sent' => 0]);
}

try {
	$smtp = new SimpleSmtp(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
	$smtp->connect();
} catch (SmtpException) {
	respond(502, ['success' => false, 'error' => "Connexion au serveur d'envoi impossible. Merci de réessayer plus tard."]);
}

// Envoi individuel à chaque abonné (voir consigne) : un email par destinataire
// plutôt qu'un envoi groupé, pour limiter le risque d'être marqué comme spam.
$sent = 0;
foreach ($active as $subscriber) {
	$email = (string) $subscriber['email'];
	$token = unsubscribeToken($email);
	$unsubscribeUrl = 'https://laboiteabivouac.fr/api/unsubscribe.php?email=' . urlencode($email) . '&token=' . $token;
	$body = $message . "\n\n---\nPour vous désinscrire : {$unsubscribeUrl}\n";

	try {
		$smtp->sendMail($email, $subject, $body, $unsubscribeUrl);
		$sent++;
	} catch (SmtpException) {
		// Un échec isolé (ex. destinataire refusé) ne doit pas interrompre
		// l'envoi aux abonnés suivants : on tente de reconnecter et on continue.
		try {
			$smtp->connect();
		} catch (SmtpException) {
			break;
		}
	}
}

$smtp->quit();

respond(200, ['success' => true, 'sent' => $sent]);
