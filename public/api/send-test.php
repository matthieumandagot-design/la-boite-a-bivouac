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
require __DIR__ . '/lib/smtp.php';

requireAdminAuth();

$decoded = json_decode((string) file_get_contents('php://input'), true);
$subject = trim((string) ($decoded['subject'] ?? ''));
$message = trim((string) ($decoded['message'] ?? ''));

if ($subject === '' || $message === '') {
	respond(422, ['success' => false, 'error' => 'Le sujet et le message sont requis.']);
}

// Envoyé à l'adresse du site elle-même : permet de valider le rendu final
// (sujet, mise en forme, lien de désinscription) avant l'envoi réel aux abonnés.
$testEmail = SMTP_FROM;
$token = unsubscribeToken($testEmail);
$unsubscribeUrl = 'https://laboiteabivouac.fr/api/unsubscribe.php?email=' . urlencode($testEmail) . '&token=' . $token;
$body = $message . "\n\n---\nPour vous désinscrire : {$unsubscribeUrl}\n";

try {
	$smtp = new SimpleSmtp(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
	$smtp->connect();
	$smtp->sendMail($testEmail, '[Test] ' . $subject, $body);
	$smtp->quit();
} catch (SmtpException) {
	respond(502, ['success' => false, 'error' => "Échec de l'envoi du test. Merci de réessayer plus tard."]);
}

respond(200, ['success' => true]);
