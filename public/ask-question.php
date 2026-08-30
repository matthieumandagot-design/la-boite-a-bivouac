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

$question = trim((string) ($_POST['question'] ?? ''));
$honeypot = trim((string) ($_POST['website'] ?? ''));

// Champ honeypot : un humain ne le remplit jamais. S'il est rempli, on
// ignore silencieusement la requête sans jamais l'enregistrer.
if ($honeypot !== '') {
	respond(200, ['success' => true]);
}

if ($question === '') {
	respond(422, ['success' => false, 'error' => 'Merci de saisir votre question.']);
}

// strlen (et non mb_strlen) : l'extension mbstring n'est pas toujours activée
// chez tous les hébergeurs (voir contact.php). La limite porte donc sur les
// octets UTF-8, une marge suffisante pour la limite fonctionnelle visée.
if (strlen($question) > 1000) {
	respond(422, ['success' => false, 'error' => 'Votre question est trop longue (1000 caractères maximum).']);
}

$dataDir = __DIR__ . '/data';
$questionsPath = $dataDir . '/questions.json';

if (!is_dir($dataDir) && !@mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
	respond(500, [
		'success' => false,
		'error' => "Impossible d'enregistrer votre question pour le moment. Merci de réessayer plus tard.",
	]);
}

// Ouverture en 'c+' + flock pour un cycle lecture-modification-écriture sûr
// même si plusieurs questions sont envoyées en même temps.
$handle = fopen($questionsPath, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
	respond(500, [
		'success' => false,
		'error' => "Impossible d'enregistrer votre question pour le moment. Merci de réessayer plus tard.",
	]);
}

$contents = stream_get_contents($handle);
$questions = json_decode((string) $contents, true);
if (!is_array($questions)) {
	$questions = [];
}

$questions[] = [
	'id' => bin2hex(random_bytes(8)),
	'question' => $question,
	'submittedAt' => gmdate('c'),
	'status' => 'pending',
	'answer' => null,
	'answeredAt' => null,
];

$encoded = json_encode($questions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ftruncate($handle, 0);
rewind($handle);
fwrite($handle, (string) $encoded);
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

respond(200, ['success' => true]);
