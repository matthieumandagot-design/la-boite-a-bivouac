<?php
declare(strict_types=1);

// Les avertissements PHP ne doivent jamais polluer la réponse JSON ni exposer
// de chemins serveur : on les laisse aller au log plutôt qu'à la sortie.
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

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$honeypot = trim((string) ($_POST['website'] ?? ''));

// Champ honeypot : un humain ne le remplit jamais. S'il est rempli, on
// ignore silencieusement la requête sans jamais envoyer d'email.
if ($honeypot !== '') {
	respond(200, ['success' => true]);
}

$errors = [];

if ($name === '') {
	$errors[] = 'Le nom est requis.';
}

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
	$errors[] = 'Une adresse email valide est requise.';
}

if ($message === '') {
	$errors[] = 'Le message est requis.';
}

if ($errors !== []) {
	respond(422, ['success' => false, 'error' => implode(' ', $errors)]);
}

// Neutralise tout retour à la ligne pour empêcher l'injection d'en-têtes mail.
$sanitizeHeader = static fn(string $value): string => str_replace(["\r", "\n"], '', $value);

$safeName = $sanitizeHeader($name);
$safeEmail = $sanitizeHeader($email);

$to = 'contact@laboiteabivouac.fr';
// Encodage MIME manuel (RFC 2047) pour ne pas dépendre de l'extension mbstring,
// pas toujours activée par défaut chez tous les hébergeurs.
$subject = '=?UTF-8?B?' . base64_encode("Nouveau message depuis le site - {$safeName}") . '?=';

$body = "Nouveau message reçu depuis le formulaire de contact du site.\n\n"
	. "Nom : {$name}\n"
	. "Email : {$email}\n\n"
	. "Message :\n{$message}\n";

$headers = [
	'From: La Boite a Bivouac <contact@laboiteabivouac.fr>',
	"Reply-To: {$safeEmail}",
	'Content-Type: text/plain; charset=UTF-8',
	'X-Mailer: PHP/' . phpversion(),
];

$sent = mail($to, $subject, $body, implode("\r\n", $headers));

if (!$sent) {
	respond(500, [
		'success' => false,
		'error' => "L'envoi du message a échoué. Merci de réessayer plus tard ou de nous écrire directement à {$to}.",
	]);
}

respond(200, ['success' => true]);
