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
 * relire manuellement dans HTTP_AUTHORIZATION (voir .htaccess du dossier, qui
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
$liveEquipementsPath = $dataDir . '/equipements.json';
$defaultEquipementsPath = $dataDir . '/equipements.default.json';
$uploadsDir = __DIR__ . '/../uploads/equipements';
$uploadsUrl = '/uploads/equipements';

const DOMAINES = [
	'remorque' => 'La remorque',
	'boite' => 'La Boîte à Bivouac',
	'toit' => 'Les barres de toit',
];

// Formats acceptés. Le type réel est déduit du contenu du fichier — jamais du
// nom ni de l'en-tête envoyé par le navigateur.
const IMAGE_TYPES = [
	IMAGETYPE_JPEG => 'jpg',
	IMAGETYPE_PNG => 'png',
	IMAGETYPE_WEBP => 'webp',
];

// Marques MP4 reconnues (octets 8 à 12, juste après « ftyp »). Les fichiers
// QuickTime (« qt ») sont refusés : servis en .mp4 ils ne se lisent pas partout.
const MP4_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'dash', 'mmp4'];

/**
 * Limite réellement applicable : la plus petite des deux directives PHP.
 * Inutile d'annoncer 64 Mo si l'hébergeur en refuse 8.
 */
function limiteTelechargement(): int
{
	$vers_octets = static function (string $valeur): int {
		$valeur = trim($valeur);
		$unite = strtolower(substr($valeur, -1));
		$nombre = (int) $valeur;
		return match ($unite) {
			'g' => $nombre * 1024 * 1024 * 1024,
			'm' => $nombre * 1024 * 1024,
			'k' => $nombre * 1024,
			default => $nombre,
		};
	};

	$limites = array_filter([
		$vers_octets((string) ini_get('upload_max_filesize')),
		$vers_octets((string) ini_get('post_max_size')),
	]);

	return $limites === [] ? 8 * 1024 * 1024 : min($limites);
}

function formaterOctets(int $octets): string
{
	return $octets >= 1024 * 1024
		? round($octets / (1024 * 1024)) . ' Mo'
		: round($octets / 1024) . ' Ko';
}

/**
 * @return array<int, array<string, mixed>>
 */
function loadEquipements(string $livePath, string $defaultPath): array
{
	$path = is_file($livePath) ? $livePath : $defaultPath;
	if (!is_file($path)) {
		return [];
	}

	$decoded = json_decode((string) file_get_contents($path), true);
	if (!is_array($decoded)) {
		return [];
	}

	return array_map('normaliserMedias', $decoded);
}

/**
 * Les premières versions ne stockaient qu'une photo, dans un champ « image ».
 * On la convertit en liste de médias pour que l'ancien contenu reste lisible.
 */
function normaliserMedias(array $item): array
{
	if (isset($item['medias']) && is_array($item['medias'])) {
		$item['medias'] = array_values(array_filter(
			$item['medias'],
			static fn($media): bool => is_array($media) && ($media['url'] ?? '') !== '',
		));
		unset($item['image']);
		return $item;
	}

	$item['medias'] = ($item['image'] ?? '') !== ''
		? [['type' => 'image', 'url' => (string) $item['image']]]
		: [];
	unset($item['image']);

	return $item;
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

/**
 * Identifie un fichier d'après son contenu, jamais d'après son nom.
 * L'extension fileinfo n'étant pas garantie chez l'hébergeur, les vidéos sont
 * reconnues à leur signature binaire.
 *
 * @return array{0: string, 1: string}|null [type, extension]
 */
function identifierMedia(string $chemin): ?array
{
	$infos = @getimagesize($chemin);
	if ($infos !== false && isset(IMAGE_TYPES[$infos[2]])) {
		return ['image', IMAGE_TYPES[$infos[2]]];
	}

	$poignee = @fopen($chemin, 'rb');
	if ($poignee === false) {
		return null;
	}
	$entete = (string) fread($poignee, 16);
	fclose($poignee);

	if (strlen($entete) < 12) {
		return null;
	}

	// WebM / Matroska : en-tête EBML.
	if (str_starts_with($entete, "\x1A\x45\xDF\xA3")) {
		return ['video', 'webm'];
	}

	// MP4 : « ftyp » en octets 4 à 8, puis la marque du format.
	if (substr($entete, 4, 4) === 'ftyp' && in_array(substr($entete, 8, 4), MP4_BRANDS, true)) {
		return ['video', 'mp4'];
	}

	return null;
}

/**
 * Range les fichiers reçus et renvoie les médias créés.
 * PHP présente les envois multiples sous forme de tableaux parallèles : on les
 * remet en lignes avant de les traiter.
 *
 * @return array{0: array<int, array{type: string, url: string}>, 1: array<int, string>} [médias, erreurs]
 */
function rangerMediasEnvoyes(array $champ, string $uploadsDir, string $uploadsUrl): array
{
	if (!isset($champ['name'])) {
		return [[], []];
	}

	$noms = (array) $champ['name'];
	$medias = [];
	$erreurs = [];

	foreach (array_keys($noms) as $i) {
		$erreur = (int) (is_array($champ['error']) ? $champ['error'][$i] : $champ['error']);
		if ($erreur === UPLOAD_ERR_NO_FILE) {
			continue;
		}

		$nomOrigine = (string) (is_array($champ['name']) ? $champ['name'][$i] : $champ['name']);
		$temporaire = (string) (is_array($champ['tmp_name']) ? $champ['tmp_name'][$i] : $champ['tmp_name']);
		$taille = (int) (is_array($champ['size']) ? $champ['size'][$i] : $champ['size']);

		if ($erreur === UPLOAD_ERR_INI_SIZE || $erreur === UPLOAD_ERR_FORM_SIZE) {
			$erreurs[] = sprintf('« %s » dépasse la limite de %s.', $nomOrigine, formaterOctets(limiteTelechargement()));
			continue;
		}

		if ($erreur !== UPLOAD_ERR_OK) {
			$erreurs[] = sprintf('« %s » n\'a pas pu être reçu (code %d).', $nomOrigine, $erreur);
			continue;
		}

		if (!is_uploaded_file($temporaire)) {
			$erreurs[] = sprintf('Envoi invalide pour « %s ».', $nomOrigine);
			continue;
		}

		if ($taille > limiteTelechargement()) {
			$erreurs[] = sprintf('« %s » dépasse la limite de %s.', $nomOrigine, formaterOctets(limiteTelechargement()));
			continue;
		}

		$identite = identifierMedia($temporaire);
		if ($identite === null) {
			$erreurs[] = sprintf('« %s » : format non reconnu. Utilisez JPEG, PNG, WebP, MP4 ou WebM.', $nomOrigine);
			continue;
		}

		[$type, $extension] = $identite;

		if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0755, true)) {
			$erreurs[] = "Le dossier des médias n'a pas pu être créé.";
			break;
		}

		if (!is_writable($uploadsDir)) {
			$erreurs[] = "Le dossier des médias n'est pas accessible en écriture.";
			break;
		}

		$nomFichier = bin2hex(random_bytes(8)) . '.' . $extension;
		if (!move_uploaded_file($temporaire, $uploadsDir . '/' . $nomFichier)) {
			$erreurs[] = sprintf('« %s » n\'a pas pu être enregistré.', $nomOrigine);
			continue;
		}

		$medias[] = ['type' => $type, 'url' => $uploadsUrl . '/' . $nomFichier];
	}

	return [$medias, $erreurs];
}

/**
 * Supprime un fichier devenu inutile. Verrouillé sur le dossier des médias pour
 * qu'une valeur trafiquée ne puisse pas viser un autre fichier du serveur.
 */
function supprimerFichierMedia(?string $url, string $uploadsDir, string $uploadsUrl): void
{
	if ($url === null || $url === '' || !str_starts_with($url, $uploadsUrl . '/')) {
		return;
	}

	$chemin = $uploadsDir . '/' . basename($url);
	if (is_file($chemin)) {
		@unlink($chemin);
	}
}

/**
 * @param array<int, array{type: string, url: string}> $medias
 */
function supprimerTousLesFichiers(array $medias, string $uploadsDir, string $uploadsUrl): void
{
	foreach ($medias as $media) {
		supprimerFichierMedia($media['url'] ?? null, $uploadsDir, $uploadsUrl);
	}
}

$flashMessage = null;
$flashError = null;

// Un envoi dépassant post_max_size arrive avec $_POST vide : sans ce garde-fou,
// l'échec se présenterait comme une session expirée, ce qui égare complètement.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
	$flashError = sprintf(
		'Envoi trop volumineux : le serveur accepte %s au maximum par envoi. Ajoutez les médias en plusieurs fois, ou allégez la vidéo.',
		formaterOctets(limiteTelechargement()),
	);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string) ($_POST['action'] ?? '');

	if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
		$flashError = 'Session expirée, merci de réessayer.';
	} elseif ($action === 'ajouter') {
		$domaine = (string) ($_POST['domaine'] ?? '');
		$titre = trim((string) ($_POST['titre'] ?? ''));
		$prix = trim((string) ($_POST['prix'] ?? ''));
		$description = trim((string) ($_POST['description'] ?? ''));

		if (!isset(DOMAINES[$domaine]) || $titre === '' || $description === '') {
			$flashError = 'Le champ d\'application, le titre et la description sont obligatoires.';
		} else {
			[$medias, $erreurs] = rangerMediasEnvoyes($_FILES['medias'] ?? [], $uploadsDir, $uploadsUrl);

			$equipements = loadEquipements($liveEquipementsPath, $defaultEquipementsPath);
			$equipements[] = [
				'id' => bin2hex(random_bytes(8)),
				'domaine' => $domaine,
				'titre' => $titre,
				'prix' => $prix,
				'description' => $description,
				'medias' => $medias,
			];

			if (!saveJsonFile($dataDir, $liveEquipementsPath, $equipements)) {
				supprimerTousLesFichiers($medias, $uploadsDir, $uploadsUrl);
				$flashError = "Impossible d'enregistrer : le dossier data est introuvable ou non accessible en écriture.";
			} else {
				$suffixe = $erreurs === [] ? '' : '&partiel=' . rawurlencode(implode(' ', $erreurs));
				header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?') . '?saved=1' . $suffixe);
				exit;
			}
		}
	} elseif (in_array($action, ['modifier', 'supprimer', 'supprimer_media', 'couverture'], true)) {
		$id = (string) ($_POST['id'] ?? '');
		$equipements = loadEquipements($liveEquipementsPath, $defaultEquipementsPath);
		$misAJour = [];
		$trouve = false;
		$mediasAjoutes = [];
		$erreursMedias = [];

		foreach ($equipements as $item) {
			if (($item['id'] ?? '') !== $id) {
				$misAJour[] = $item;
				continue;
			}

			$trouve = true;
			$medias = $item['medias'] ?? [];

			if ($action === 'supprimer') {
				// Élément omis du tableau reconstruit : suppression définitive.
				supprimerTousLesFichiers($medias, $uploadsDir, $uploadsUrl);
				continue;
			}

			if ($action === 'supprimer_media') {
				$cible = (string) ($_POST['media_url'] ?? '');
				$restants = array_values(array_filter(
					$medias,
					static fn(array $media): bool => ($media['url'] ?? '') !== $cible,
				));
				if (count($restants) !== count($medias)) {
					supprimerFichierMedia($cible, $uploadsDir, $uploadsUrl);
				}
				$item['medias'] = $restants;
				$misAJour[] = $item;
				continue;
			}

			if ($action === 'couverture') {
				$cible = (string) ($_POST['media_url'] ?? '');
				usort($medias, static fn(array $a, array $b): int =>
					(int) (($b['url'] ?? '') === $cible) <=> (int) (($a['url'] ?? '') === $cible));
				$item['medias'] = array_values($medias);
				$misAJour[] = $item;
				continue;
			}

			// action « modifier »
			$titre = trim((string) ($_POST['titre'] ?? ''));
			$description = trim((string) ($_POST['description'] ?? ''));
			$domaine = (string) ($_POST['domaine'] ?? $item['domaine'] ?? '');

			if (!isset(DOMAINES[$domaine]) || $titre === '' || $description === '') {
				$flashError = 'Le champ d\'application, le titre et la description sont obligatoires.';
				$misAJour[] = $item;
				continue;
			}

			[$nouveaux, $erreursMedias] = rangerMediasEnvoyes($_FILES['medias'] ?? [], $uploadsDir, $uploadsUrl);
			$mediasAjoutes = $nouveaux;

			$misAJour[] = [
				'id' => $id,
				'domaine' => $domaine,
				'titre' => $titre,
				'prix' => trim((string) ($_POST['prix'] ?? '')),
				'description' => $description,
				'medias' => array_merge($medias, $nouveaux),
			];
		}

		if (!$trouve) {
			$flashError = 'Équipement introuvable.';
		} elseif ($flashError === null) {
			if (!saveJsonFile($dataDir, $liveEquipementsPath, $misAJour)) {
				supprimerTousLesFichiers($mediasAjoutes, $uploadsDir, $uploadsUrl);
				$flashError = "Impossible d'enregistrer : le dossier data est introuvable ou non accessible en écriture.";
			} else {
				$suffixe = $erreursMedias === [] ? '' : '&partiel=' . rawurlencode(implode(' ', $erreursMedias));
				header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?') . '?saved=1' . $suffixe);
				exit;
			}
		}
	}
}

if (($_GET['saved'] ?? '') === '1') {
	$flashMessage = 'Enregistré.';
}
if (($_GET['partiel'] ?? '') !== '') {
	$flashError = 'Enregistré, mais : ' . (string) $_GET['partiel'];
}

$equipements = loadEquipements($liveEquipementsPath, $defaultEquipementsPath);
$parDomaine = [];
foreach (array_keys(DOMAINES) as $cle) {
	$parDomaine[$cle] = array_values(array_filter(
		$equipements,
		static fn(array $item): bool => ($item['domaine'] ?? '') === $cle,
	));
}
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Équipements — Administration · La Boîte à Bivouac</title>
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
					<a href="/admin/equipements.php" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#0a1610;background:#e8b428;">
						<span aria-hidden="true">🧰</span> Équipements
					</a>
					<a href="/admin/faq.php" style="display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;color:#e8e0c8;">
						<span aria-hidden="true">❓</span> FAQ
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

			<main style="margin-left:220px;flex:1;padding:2rem;">
				<header style="display:flex;align-items:baseline;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">
					<h1 style="font-size:1.4rem;font-weight:700;margin:0;">Équipements</h1>
					<a href="/equipements" target="_blank" rel="noopener" style="font-size:.85rem;color:#b85438;">
						Voir la page publique ↗
					</a>
				</header>

				<?php if ($flashMessage !== null): ?>
					<p style="background:#e3f0e3;border:1px solid #b6d7b6;color:#1e3a28;padding:.75rem 1rem;border-radius:.5rem;margin:0 0 1.25rem;">
						<?= e($flashMessage) ?>
					</p>
				<?php endif; ?>

				<?php if ($flashError !== null): ?>
					<p style="background:#fbe6e6;border:1px solid #e6b8b8;color:#8b1c1c;padding:.75rem 1rem;border-radius:.5rem;margin:0 0 1.25rem;">
						<?= e($flashError) ?>
					</p>
				<?php endif; ?>

				<!-- ── Ajout d'un équipement ─────────────────────────────── -->
				<section style="background:#fff;border:1px solid #e4e0d8;border-radius:.75rem;padding:1.5rem;margin-bottom:2rem;">
					<h2 style="font-size:1.05rem;font-weight:700;margin:0 0 1rem;">Ajouter un équipement</h2>

					<form method="post" enctype="multipart/form-data">
						<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
						<input type="hidden" name="action" value="ajouter" />

						<div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:1rem;">
							<label style="display:block;">
								<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Champ d'application</span>
								<select name="domaine" required style="width:100%;padding:.55rem .65rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.95rem;background:#fff;">
									<?php foreach (DOMAINES as $cle => $libelle): ?>
										<option value="<?= e($cle) ?>"><?= e($libelle) ?></option>
									<?php endforeach; ?>
								</select>
							</label>

							<label style="display:block;">
								<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Titre</span>
								<input type="text" name="titre" required maxlength="120" placeholder="Tente de toit 3 places"
									style="width:100%;padding:.55rem .65rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.95rem;" />
							</label>

							<label style="display:block;">
								<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Prix</span>
								<input type="text" name="prix" maxlength="60" placeholder="1 200 € ou À partir de 900 €"
									style="width:100%;padding:.55rem .65rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.95rem;" />
								<span style="display:block;font-size:.75rem;color:#7f8a77;margin-top:.3rem;">Texte libre. Laissez vide pour ne rien afficher.</span>
							</label>
						</div>

						<label style="display:block;margin-bottom:1rem;">
							<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Description</span>
							<textarea name="description" required rows="3" maxlength="800"
								style="width:100%;padding:.55rem .65rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.95rem;font-family:inherit;"></textarea>
						</label>

						<label style="display:block;margin-bottom:1.25rem;">
							<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Photos et vidéos</span>
							<input type="file" name="medias[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" style="font-size:.9rem;" />
							<span style="display:block;font-size:.75rem;color:#7f8a77;margin-top:.3rem;">
								Plusieurs fichiers possibles. JPEG, PNG, WebP, MP4 ou WebM, <?= formaterOctets(limiteTelechargement()) ?> maximum par envoi.
								La première photo ou vidéo sert de couverture.
							</span>
						</label>

						<button type="submit" style="background:#b85438;color:#f9f4e8;border:none;border-radius:9999px;padding:.6rem 1.4rem;font-weight:600;font-size:.9rem;cursor:pointer;">
							Ajouter
						</button>
					</form>
				</section>

				<!-- ── Équipements existants, groupés par champ d'application ── -->
				<?php foreach (DOMAINES as $cle => $libelle): ?>
					<section style="margin-bottom:2rem;">
						<h2 style="font-size:1.05rem;font-weight:700;margin:0 0 .75rem;">
							<?= e($libelle) ?>
							<span style="font-weight:500;color:#7f8a77;font-size:.85rem;">
								— <?= count($parDomaine[$cle]) ?> équipement<?= count($parDomaine[$cle]) > 1 ? 's' : '' ?>
							</span>
						</h2>

						<?php if ($parDomaine[$cle] === []): ?>
							<p style="border:1px dashed #c0c8c8;border-radius:.75rem;padding:1.25rem;margin:0;color:#7f8a77;font-size:.9rem;">
								Aucun équipement pour l'instant. La page publique affiche « Ça arrive prochainement » pour ce champ.
							</p>
						<?php else: ?>
							<?php foreach ($parDomaine[$cle] as $item): ?>
								<?php $id = (string) ($item['id'] ?? ''); $medias = $item['medias'] ?? []; ?>
								<div style="background:#fff;border:1px solid #e4e0d8;border-radius:.75rem;padding:1.25rem;margin-bottom:1rem;">

									<!-- Médias : chacun a ses propres boutons, dans son propre formulaire.
									     Les formulaires ne peuvent pas s'imbriquer, ils sont donc placés
									     avant celui qui porte les champs texte. -->
									<p style="font-size:.8rem;font-weight:600;margin:0 0 .5rem;">
										Médias
										<span style="font-weight:500;color:#7f8a77;">
											— <?= count($medias) ?> fichier<?= count($medias) > 1 ? 's' : '' ?><?= $medias === [] ? '' : ', le premier sert de couverture' ?>
										</span>
									</p>

									<?php if ($medias === []): ?>
										<p style="border:1px dashed #c0c8c8;border-radius:.5rem;padding:.9rem;margin:0 0 1rem;color:#a8b0a8;font-size:.82rem;">
											Aucun média. L'équipement s'affiche avec une simple icône.
										</p>
									<?php else: ?>
										<div style="display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;">
											<?php foreach ($medias as $rang => $media): ?>
												<?php $url = (string) ($media['url'] ?? ''); $type = (string) ($media['type'] ?? 'image'); ?>
												<form method="post" style="width:150px;border:1px solid <?= $rang === 0 ? '#b88a18' : '#e4e0d8' ?>;border-radius:.5rem;padding:.4rem;">
													<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
													<input type="hidden" name="id" value="<?= e($id) ?>" />
													<input type="hidden" name="media_url" value="<?= e($url) ?>" />

													<?php if ($type === 'video'): ?>
														<video src="<?= e($url) ?>" preload="metadata" muted playsinline
															style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:.35rem;background:#122218;"></video>
													<?php else: ?>
														<img src="<?= e($url) ?>" alt=""
															style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:.35rem;" />
													<?php endif; ?>

													<p style="margin:.35rem 0 .4rem;font-size:.7rem;color:#7f8a77;">
														<?= $type === 'video' ? 'Vidéo' : 'Photo' ?><?= $rang === 0 ? ' · couverture' : '' ?>
													</p>

													<div style="display:flex;gap:.3rem;">
														<?php if ($rang !== 0): ?>
															<button type="submit" name="action" value="couverture"
																style="flex:1;background:#f4f2ee;border:1px solid #c0c8c8;border-radius:.35rem;padding:.25rem;font-size:.68rem;cursor:pointer;">
																Couverture
															</button>
														<?php endif; ?>
														<button type="submit" name="action" value="supprimer_media"
															onclick="return confirm('Supprimer définitivement ce fichier ?');"
															style="flex:1;background:transparent;border:1px solid #e6b8b8;color:#8b1c1c;border-radius:.35rem;padding:.25rem;font-size:.68rem;cursor:pointer;">
															Supprimer
														</button>
													</div>
												</form>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>

									<form method="post" enctype="multipart/form-data">
										<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
										<input type="hidden" name="id" value="<?= e($id) ?>" />

										<label style="display:block;margin-bottom:1rem;">
											<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Ajouter des photos ou des vidéos</span>
											<input type="file" name="medias[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm" style="font-size:.82rem;" />
										</label>

										<div style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:.75rem;">
											<label style="display:block;">
												<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Titre</span>
												<input type="text" name="titre" required maxlength="120" value="<?= e((string) ($item['titre'] ?? '')) ?>"
													style="width:100%;padding:.5rem .6rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.92rem;" />
											</label>

											<label style="display:block;">
												<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Prix</span>
												<input type="text" name="prix" maxlength="60" value="<?= e((string) ($item['prix'] ?? '')) ?>"
													style="width:100%;padding:.5rem .6rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.92rem;" />
											</label>

											<label style="display:block;">
												<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Champ d'application</span>
												<select name="domaine" style="width:100%;padding:.5rem .6rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.92rem;background:#fff;">
													<?php foreach (DOMAINES as $optCle => $optLibelle): ?>
														<option value="<?= e($optCle) ?>" <?= ($item['domaine'] ?? '') === $optCle ? 'selected' : '' ?>>
															<?= e($optLibelle) ?>
														</option>
													<?php endforeach; ?>
												</select>
											</label>
										</div>

										<label style="display:block;margin-bottom:1rem;">
											<span style="display:block;font-size:.8rem;font-weight:600;margin-bottom:.35rem;">Description</span>
											<textarea name="description" required rows="3" maxlength="800"
												style="width:100%;padding:.5rem .6rem;border:1px solid #c0c8c8;border-radius:.5rem;font-size:.92rem;font-family:inherit;"><?= e((string) ($item['description'] ?? '')) ?></textarea>
										</label>

										<div style="display:flex;gap:.6rem;flex-wrap:wrap;">
											<button type="submit" name="action" value="modifier"
												style="background:#1e3a28;color:#f9f4e8;border:none;border-radius:9999px;padding:.5rem 1.2rem;font-weight:600;font-size:.85rem;cursor:pointer;">
												Enregistrer
											</button>
											<button type="submit" name="action" value="supprimer"
												onclick="return confirm('Supprimer définitivement cet équipement et tous ses médias ?');"
												style="background:transparent;color:#8b1c1c;border:1px solid #e6b8b8;border-radius:9999px;padding:.5rem 1.2rem;font-weight:600;font-size:.85rem;cursor:pointer;">
												Supprimer l'équipement
											</button>
										</div>
									</form>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</main>
		</div>
	</body>
</html>
