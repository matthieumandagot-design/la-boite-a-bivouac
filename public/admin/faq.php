<?php
declare(strict_types=1);

// Session partagée avec le module commercial : c'est sa trace qui permet au
// site public de reconnaître un admin et d'afficher le mode aperçu, le Basic
// Auth n'étant pas transmis hors de /admin. Remplace session_start() pour
// poser au passage un cookie Secure/HttpOnly/SameSite.
require_once __DIR__ . '/../lib/admin-session.php';
demarrerSessionAdmin();

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

// Authentification confirmée : on en garde la trace pour le mode aperçu.
marquerSessionAdmin();

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$dataDir = __DIR__ . '/../data';
$liveFaqPath = $dataDir . '/faq.json';
$defaultFaqPath = $dataDir . '/faq.default.json';
$questionsPath = $dataDir . '/questions.json';

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

/**
 * @return array<int, array{id: string, question: string, submittedAt: string, status: string, answer: ?string, answeredAt: ?string}>
 */
function loadQuestions(string $path): array
{
	if (!is_file($path)) {
		return [];
	}

	$decoded = json_decode((string) file_get_contents($path), true);
	return is_array($decoded) ? $decoded : [];
}

function saveJsonFile(string $dataDir, string $path, array $data): bool
{
	if (!is_dir($dataDir)) {
		@mkdir($dataDir, 0755, true);
	}

	if (!is_dir($dataDir) || !is_writable($dataDir)) {
		return false;
	}

	$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		return false;
	}

	return file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function e(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatSubmittedAt(string $isoDate): string
{
	$timestamp = strtotime($isoDate);
	return $timestamp === false ? $isoDate : date('d/m/Y à H:i', $timestamp);
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

		if (!saveJsonFile($dataDir, $liveFaqPath, $updatedItems)) {
			$flashError = "Impossible d'enregistrer : le dossier data est introuvable ou non accessible en écriture.";
		} else {
			header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?') . '?saved=1');
			exit;
		}
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'handle_question') {
	if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
		$flashError = 'Session expirée, merci de réessayer.';
	} else {
		$questionId = (string) ($_POST['question_id'] ?? '');
		$do = (string) ($_POST['do'] ?? '');
		$questions = loadQuestions($questionsPath);

		$questionFound = false;
		$updatedQuestions = [];

		foreach ($questions as $entry) {
			if (($entry['id'] ?? '') !== $questionId) {
				$updatedQuestions[] = $entry;
				continue;
			}

			$questionFound = true;

			if ($do === 'delete') {
				// Question omise du tableau reconstruit : suppression définitive.
				continue;
			}

			if ($do === 'publish') {
				$publishedQuestion = trim((string) ($_POST['question_text'] ?? $entry['question'] ?? ''));
				$answer = trim((string) ($_POST['answer'] ?? ''));

				if ($publishedQuestion === '' || $answer === '') {
					$flashError = "La question et la réponse sont requises pour publier dans la FAQ.";
					$updatedQuestions[] = $entry;
					continue;
				}

				$faqItems = loadFaqItems($liveFaqPath, $defaultFaqPath);
				$faqItems[] = ['question' => $publishedQuestion, 'answer' => $answer];

				if (!saveJsonFile($dataDir, $liveFaqPath, $faqItems)) {
					$flashError = "Impossible de publier dans la FAQ : le dossier data est introuvable ou non accessible en écriture.";
					$updatedQuestions[] = $entry;
					continue;
				}

				$entry['status'] = 'answered';
				$entry['question'] = $publishedQuestion;
				$entry['answer'] = $answer;
				$entry['answeredAt'] = gmdate('c');
			}

			$updatedQuestions[] = $entry;
		}

		if ($questionFound && $flashError === null) {
			if (!saveJsonFile($dataDir, $questionsPath, $updatedQuestions)) {
				$flashError = "Impossible d'enregistrer : le dossier data est introuvable ou non accessible en écriture.";
			} else {
				header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?') . '?saved=1');
				exit;
			}
		}
	}
}

if (($_GET['saved'] ?? '') === '1') {
	$flashMessage = 'Enregistré.';
}

$faqItems = loadFaqItems($liveFaqPath, $defaultFaqPath);
$allQuestions = loadQuestions($questionsPath);
$pendingQuestions = array_filter($allQuestions, static fn(array $q): bool => ($q['status'] ?? 'pending') !== 'answered');
$answeredQuestions = array_filter($allQuestions, static fn(array $q): bool => ($q['status'] ?? 'pending') === 'answered');
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>FAQ — Administration · La Boîte à Bivouac</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link
			href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
			rel="stylesheet"
		/>
	</head>
	<body style="margin:0;font-family:'Inter',system-ui,sans-serif;background:#f4f2ee;color:#122218;">
		<div style="min-height:100vh;display:flex;">
			<aside style="width:220px;flex-shrink:0;background:#122218;color:#e8e0c8;display:flex;flex-direction:column;position:fixed;top:0;bottom:0;left:0;">
				<div style="padding:1.5rem 1.25rem;border-bottom:1px solid rgba(232,224,200,0.12);">
					<p style="text-transform:uppercase;letter-spacing:.08em;font-size:.65rem;font-weight:700;color:#e8b428;margin:0 0 .2rem;">
						La Boîte à Bivouac
					</p>
					<p style="font-weight:700;font-size:1rem;margin:0;">Administration</p>
				</div>
				<nav style="flex:1;overflow-y:auto;padding:1rem 0.75rem;">
					<a href="/admin" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#e8e0c8;">
						<span aria-hidden="true">🏠</span> Tableau de bord
					</a>
					<a href="/admin/subscribers" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#e8e0c8;">
						<span aria-hidden="true">📧</span> Abonnés
					</a>
					<a href="/admin/send-news" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#e8e0c8;">
						<span aria-hidden="true">📨</span> Envoyer une news
					</a>
					<a href="/admin/equipements.php" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#e8e0c8;">
						<span aria-hidden="true">🧰</span> Équipements
					</a>
					<a href="/admin/faq.php" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#0a1610;background:#e8b428;">
						<span aria-hidden="true">❓</span> FAQ
					</a>
					<a href="/admin/parametres.php" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#e8e0c8;">
						<span aria-hidden="true">⚙️</span> Paramètres
					</a>
					<a href="/admin/base.php" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#e8e0c8;">
						<span aria-hidden="true">🗄️</span> Base de données
					</a>
					<a href="/" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-top:.75rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#babba8;border-top:1px solid rgba(232,224,200,0.12);padding-top:1rem;">
						<span aria-hidden="true">🌐</span> Voir le site
					</a>

					<p style="text-transform:uppercase;letter-spacing:.1em;font-size:.65rem;font-weight:700;color:#7f8a77;margin:1.25rem 0 .35rem;padding:0 .75rem;">
						Admin
					</p>
					<a href="https://cloud.umami.is/analytics/eu/websites" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📊</span> Umami
					</a>
					<a href="https://search.google.com/search-console?hl=fr&amp;resource_id=https://laboiteabivouac.fr/" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">🔍</span> Search Console
					</a>
					<a href="https://www.google.com/search?q=La+Bo%C3%AEte+%C3%A0+Bivouac&amp;stick=H4sIAAAAAAAA_-NgU1I1qEhMSkk1TDJONU4zMDYyszS1MqgwMjW1TLY0TjQ2T05OSzYyWMQq4pOo4JR_eF1JqsLhBQpOmWX5pYnJAE6QNhdAAAAA&amp;hl=fr&amp;mat=CZ21dska8KCrElYBa0lj_zMH55jHjvES16LWm6uTi6l3nowGSeXXeYK8C2mZEzADs4zLYTBuJTBDpu9rjVR4AstE7uSgyyshKQxIZew91anM46AH6SPo0ADhovME3I66iQ&amp;authuser=0&amp;ved=2ahUKEwiQt9va1-yWAxUR5AIHHRojKggQ-MgIegQIFxAb" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📍</span> Fiche Google
					</a>
					<a href="https://app.brevo.com/" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📧</span> Brevo
					</a>
					<a href="https://manager.eu.ovhcloud.com/" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">☁️</span> OVH Manager
					</a>
					<a href="https://app.notion.com/p/La-Bo-te-Bivouac-Pilotage-3ac440dc05e5811a891fe579c98c9c56" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📓</span> Notion
					</a>
					<a href="https://zimbra1.mail.ovh.net/modern/email/Inbox/conversation/-258" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">✉️</span> Webmail OVH
					</a>
					<a href="https://calendar.google.com/calendar/u/0/r?pli=1" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📅</span> Google Agenda
					</a>
					<a href="https://mail.google.com/mail/u/0/#inbox" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📬</span> Gmail
					</a>

					<p style="text-transform:uppercase;letter-spacing:.1em;font-size:.65rem;font-weight:700;color:#7f8a77;margin:1.25rem 0 .35rem;padding:0 .75rem;">
						Réseaux
					</p>
					<a href="https://www.tiktok.com/@laboiteabivouac8" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">🎵</span> TikTok
					</a>
					<a href="https://www.youtube.com/@LaBo%C3%AEte%C3%A0Bivouac" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">▶️</span> YouTube
					</a>
					<a href="https://www.linkedin.com/company/136036436/admin/dashboard/" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">💼</span> LinkedIn
					</a>
					<a href="https://www.facebook.com/laboiteabivouac?checkpoint_src=any" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">👍</span> Facebook
					</a>
					<a href="https://www.instagram.com/laboiteabivouac/" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📷</span> Instagram
					</a>
					<a href="https://www.threads.com/@laboiteabivouac" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">🧵</span> Threads
					</a>
					<a href="https://fr.pinterest.com/laboiteabivouac/" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
						<span aria-hidden="true">📌</span> Pinterest
					</a>
				</nav>
				<div style="padding:1rem 1.25rem;border-top:1px solid rgba(232,224,200,0.12);font-size:.75rem;color:#babba8;">
					Fermez l'onglet pour vous déconnecter (authentification navigateur).
				</div>
			</aside>

			<div style="margin-left:220px;flex:1;display:flex;flex-direction:column;min-height:100vh;">
				<header style="background:#fff;border-bottom:1px solid #e4e0d8;padding:1rem 2rem;">
					<h1 style="font-size:1.1rem;font-weight:700;color:#122218;margin:0;">FAQ</h1>
				</header>
				<main style="flex:1;padding:2rem;">
					<div style="max-width:720px;">

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

			<section style="margin-bottom:3rem;">
				<h2 style="font-size:1.25rem;font-weight:700;margin:0 0 .35rem;">
					Questions des visiteurs
					<?php if ($pendingQuestions !== []): ?>
						<span style="display:inline-flex;align-items:center;justify-content:center;min-width:1.5rem;height:1.5rem;padding:0 .4rem;border-radius:9999px;background:#b85438;color:#f5ecd8;font-size:.8rem;font-weight:700;vertical-align:middle;"><?= count($pendingQuestions) ?></span>
					<?php endif; ?>
				</h2>
				<p style="color:#546754;font-size:.9rem;margin:0 0 1.5rem;">
					Posées depuis le formulaire de la page FAQ. Publier une réponse l'ajoute directement à la FAQ publique.
				</p>

				<?php if ($pendingQuestions === []): ?>
					<p style="color:#7f8a77;font-style:italic;">Aucune question en attente.</p>
				<?php endif; ?>

				<?php foreach ($pendingQuestions as $entry): ?>
					<form method="post" style="border:1px solid #c0c8c8;border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem;background:#fdfaf2;">
						<input type="hidden" name="action" value="handle_question" />
						<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
						<input type="hidden" name="question_id" value="<?= e((string) $entry['id']) ?>" />

						<p style="font-size:.8rem;color:#7f8a77;margin:0 0 .75rem;">
							Posée le <?= e(formatSubmittedAt((string) ($entry['submittedAt'] ?? ''))) ?>
						</p>

						<label style="display:block;font-weight:600;font-size:.875rem;margin-bottom:.35rem;">Question</label>
						<textarea
							name="question_text"
							rows="2"
							style="width:100%;box-sizing:border-box;padding:.6rem;margin-bottom:1rem;font-family:inherit;font-size:1rem;border:1px solid #c0c8c8;border-radius:.375rem;"
						><?= e((string) ($entry['question'] ?? '')) ?></textarea>

						<label style="display:block;font-weight:600;font-size:.875rem;margin-bottom:.35rem;">Votre réponse</label>
						<textarea
							name="answer"
							rows="4"
							placeholder="Rédigez la réponse à publier dans la FAQ…"
							style="width:100%;box-sizing:border-box;padding:.6rem;margin-bottom:1rem;font-family:inherit;font-size:1rem;border:1px solid #c0c8c8;border-radius:.375rem;"
						></textarea>

						<div style="display:flex;gap:.75rem;flex-wrap:wrap;">
							<button
								type="submit"
								name="do"
								value="publish"
								style="background:#122218;color:#f5ecd8;border:none;border-radius:9999px;padding:.6rem 1.25rem;font-weight:600;font-size:.9rem;cursor:pointer;"
							>
								Publier dans la FAQ
							</button>
							<button
								type="submit"
								name="do"
								value="delete"
								onclick="return confirm('Supprimer définitivement cette question ?');"
								style="background:transparent;color:#8b1c1c;border:1px solid #8b1c1c;border-radius:9999px;padding:.6rem 1.25rem;font-weight:600;font-size:.9rem;cursor:pointer;"
							>
								Supprimer
							</button>
						</div>
					</form>
				<?php endforeach; ?>

				<?php if ($answeredQuestions !== []): ?>
					<details style="margin-top:1.5rem;">
						<summary style="cursor:pointer;font-weight:600;color:#546754;">
							Questions déjà traitées (<?= count($answeredQuestions) ?>)
						</summary>
						<?php foreach ($answeredQuestions as $entry): ?>
							<div style="border:1px solid #e4e0d8;border-radius:.75rem;padding:1rem 1.25rem;margin-top:1rem;">
								<p style="font-size:.8rem;color:#7f8a77;margin:0 0 .5rem;">
									Posée le <?= e(formatSubmittedAt((string) ($entry['submittedAt'] ?? ''))) ?>
								</p>
								<p style="font-weight:600;margin:0 0 .5rem;"><?= e((string) $entry['question']) ?></p>
								<p style="color:#546754;margin:0 0 1rem;"><?= e((string) ($entry['answer'] ?? '')) ?></p>
								<form method="post">
									<input type="hidden" name="action" value="handle_question" />
									<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
									<input type="hidden" name="question_id" value="<?= e((string) $entry['id']) ?>" />
									<button
										type="submit"
										name="do"
										value="delete"
										onclick="return confirm('Supprimer cette question de la liste ?');"
										style="background:transparent;color:#8b1c1c;border:1px solid #8b1c1c;border-radius:9999px;padding:.4rem 1rem;font-weight:600;font-size:.85rem;cursor:pointer;"
									>
										Supprimer de la liste
									</button>
								</form>
							</div>
						<?php endforeach; ?>
					</details>
				<?php endif; ?>
			</section>

			<section>
				<h2 style="font-size:1.25rem;font-weight:700;margin:0 0 1.5rem;">FAQ publiée</h2>

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
			</section>
					</div>
				</main>
			</div>
		</div>
	</body>
</html>
