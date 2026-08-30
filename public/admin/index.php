<?php
declare(strict_types=1);

session_start();

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

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$dataDir = __DIR__ . '/../data';
$liveFaqPath = $dataDir . '/faq.json';
$defaultFaqPath = $dataDir . '/faq.default.json';

/**
 * @return array<int, array{question: string, answer: string}>
 */
function loadFaqItems(string $liveFaqPath, string $defaultFaqPath): array
{
	$path = is_file($liveFaqPath) ? $liveFaqPath : $defaultFaqPath;
	if (!is_file($path)) {
		return [];
	}

	$decoded = json_decode((string) file_get_contents($path), true);
	return is_array($decoded) ? $decoded : [];
}

function e(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$flashMessage = null;
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_faq') {
	if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
		$flashError = 'Session expirée, merci de réessayer.';
	} else {
		$submittedQuestions = $_POST['question'] ?? [];
		$submittedAnswers = $_POST['answer'] ?? [];
		$submittedDeletions = $_POST['delete'] ?? [];

		$updatedItems = [];
		foreach ($submittedQuestions as $index => $question) {
			if (isset($submittedDeletions[$index])) {
				continue;
			}

			$question = trim((string) $question);
			$answer = trim((string) ($submittedAnswers[$index] ?? ''));
			if ($question === '' || $answer === '') {
				continue;
			}

			$updatedItems[] = ['question' => $question, 'answer' => $answer];
		}

		$newQuestion = trim((string) ($_POST['new_question'] ?? ''));
		$newAnswer = trim((string) ($_POST['new_answer'] ?? ''));
		if ($newQuestion !== '' && $newAnswer !== '') {
			$updatedItems[] = ['question' => $newQuestion, 'answer' => $newAnswer];
		}

		if (!is_dir($dataDir)) {
			@mkdir($dataDir, 0755, true);
		}

		$encoded = json_encode($updatedItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ($encoded === false || !is_dir($dataDir) || !is_writable($dataDir)) {
			$flashError = "Impossible d'enregistrer : le dossier data est introuvable ou non accessible en écriture.";
		} elseif (file_put_contents($liveFaqPath, $encoded, LOCK_EX) === false) {
			$flashError = "Échec de l'écriture du fichier.";
		} else {
			header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?') . '?saved=1');
			exit;
		}
	}
}

if (($_GET['saved'] ?? '') === '1') {
	$flashMessage = 'FAQ mise à jour.';
}

$faqItems = loadFaqItems($liveFaqPath, $defaultFaqPath);
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Admin — La Boîte à Bivouac</title>
	</head>
	<body style="margin:0;background:#f5ecd8;color:#122218;font-family:system-ui,sans-serif;">
		<main style="max-width:720px;margin:0 auto;padding:2.5rem 1.5rem 5rem;">
			<p style="text-transform:uppercase;letter-spacing:.05em;font-size:.75rem;font-weight:600;color:#b88a18;margin:0;">Admin</p>
			<h1 style="font-size:1.75rem;font-weight:700;margin:.25rem 0 2rem;">FAQ — Questions fréquentes</h1>

			<?php if ($flashMessage !== null): ?>
				<p style="background:#e3f3e5;border:1px solid #2e7d32;color:#1b5e20;padding:.75rem 1rem;border-radius:.5rem;margin:0 0 1.5rem;">
					<?= e($flashMessage) ?>
				</p>
			<?php endif; ?>
			<?php if ($flashError !== null): ?>
				<p style="background:#fbe4e4;border:1px solid #c62828;color:#8b1c1c;padding:.75rem 1rem;border-radius:.5rem;margin:0 0 1.5rem;">
					<?= e($flashError) ?>
				</p>
			<?php endif; ?>

			<form method="post">
				<input type="hidden" name="action" value="save_faq" />
				<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

				<?php foreach ($faqItems as $index => $item): ?>
					<fieldset style="border:1px solid #c0c8c8;border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem;">
						<legend style="font-weight:700;padding:0 .5rem;">Question <?= (int) $index + 1 ?></legend>

						<label style="display:block;font-weight:600;font-size:.875rem;margin-bottom:.35rem;">Question</label>
						<textarea
							name="question[<?= (int) $index ?>]"
							rows="2"
							style="width:100%;box-sizing:border-box;padding:.6rem;margin-bottom:1rem;font-family:inherit;font-size:1rem;border:1px solid #c0c8c8;border-radius:.375rem;"
						><?= e((string) ($item['question'] ?? '')) ?></textarea>

						<label style="display:block;font-weight:600;font-size:.875rem;margin-bottom:.35rem;">Réponse</label>
						<textarea
							name="answer[<?= (int) $index ?>]"
							rows="4"
							style="width:100%;box-sizing:border-box;padding:.6rem;margin-bottom:1rem;font-family:inherit;font-size:1rem;border:1px solid #c0c8c8;border-radius:.375rem;"
						><?= e((string) ($item['answer'] ?? '')) ?></textarea>

						<label style="display:flex;align-items:center;gap:.5rem;color:#8b1c1c;font-size:.9rem;">
							<input type="checkbox" name="delete[<?= (int) $index ?>]" value="1" />
							Supprimer cette question
						</label>
					</fieldset>
				<?php endforeach; ?>

				<fieldset style="border:2px dashed #b88a18;border-radius:.75rem;padding:1.25rem;margin-bottom:1.5rem;">
					<legend style="font-weight:700;padding:0 .5rem;">Ajouter une question</legend>

					<label style="display:block;font-weight:600;font-size:.875rem;margin-bottom:.35rem;">Question</label>
					<textarea
						name="new_question"
						rows="2"
						style="width:100%;box-sizing:border-box;padding:.6rem;margin-bottom:1rem;font-family:inherit;font-size:1rem;border:1px solid #c0c8c8;border-radius:.375rem;"
					></textarea>

					<label style="display:block;font-weight:600;font-size:.875rem;margin-bottom:.35rem;">Réponse</label>
					<textarea
						name="new_answer"
						rows="4"
						style="width:100%;box-sizing:border-box;padding:.6rem;font-family:inherit;font-size:1rem;border:1px solid #c0c8c8;border-radius:.375rem;"
					></textarea>
				</fieldset>

				<button
					type="submit"
					style="background:#122218;color:#f5ecd8;border:none;border-radius:9999px;padding:.85rem 1.75rem;font-weight:600;font-size:1rem;cursor:pointer;"
				>
					Enregistrer les modifications
				</button>
			</form>
		</main>
	</body>
</html>
