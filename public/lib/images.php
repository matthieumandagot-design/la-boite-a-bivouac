<?php
declare(strict_types=1);

/**
 * Réception et recompression d'une photo envoyée depuis l'administration.
 *
 * Reprend la mécanique éprouvée de public/admin/equipements.php, qui garde sa
 * propre copie : c'est une page en service, et la rouvrir pour un remaniement
 * purement structurel ne vaut pas le risque. Même règle que pour la barre
 * latérale (voir public/admin/nav.php) — la dette est connue, et le jour où
 * equipements.php sera de toute façon modifiée, elle viendra ici.
 *
 * Principe : toute défaillance (GD absent, mémoire insuffisante, format
 * exotique) conserve l'original. Une photo lourde vaut mieux qu'une photo
 * perdue.
 *
 * Le type est déterminé par getimagesize(), jamais par l'extension ni par le
 * type MIME annoncé par le navigateur : les deux sont fournis par le client, et
 * une archive renommée en .jpg passerait sans cela.
 */

/** Plus grand côté conservé, en pixels. */
const IMG_COTE_MAX = 1600;
const IMG_QUALITE = 82;
/** Au-delà, on refuse avant même de décoder. */
const IMG_POIDS_MAX = 8 * 1024 * 1024;

/** Extension canonique d'après le contenu réel du fichier, ou null. */
function imgTypeReel(string $chemin): ?string
{
	$infos = @getimagesize($chemin);
	if ($infos === false) {
		return null;
	}

	return match ($infos[2] ?? null) {
		IMAGETYPE_JPEG => 'jpg',
		IMAGETYPE_PNG => 'png',
		IMAGETYPE_WEBP => 'webp',
		default => null,
	};
}

function imgCompressionDisponible(): bool
{
	return extension_loaded('gd')
		&& function_exists('imagecreatetruecolor')
		&& (function_exists('imagewebp') || function_exists('imagejpeg'));
}

/** WebP quand GD sait l'écrire — environ 30 % plus léger à qualité égale. */
function imgFormatSortie(): string
{
	return function_exists('imagewebp') ? 'webp' : 'jpg';
}

/** Convertit une valeur de php.ini (« 8M », « 1G ») en octets. */
function imgIniOctets(string $valeur): int
{
	$valeur = trim($valeur);
	$nombre = (int) $valeur;

	return match (strtolower(substr($valeur, -1))) {
		'g' => $nombre * 1024 * 1024 * 1024,
		'm' => $nombre * 1024 * 1024,
		'k' => $nombre * 1024,
		default => $nombre,
	};
}

/**
 * GD décompresse l'image entière en mémoire, quatre octets par pixel, et il
 * faut de la place pour la copie réduite. Au-delà du budget restant, mieux vaut
 * renoncer que faire tomber la page.
 */
function imgMemoireSuffisante(int $largeur, int $hauteur): bool
{
	$limite = imgIniOctets((string) ini_get('memory_limit'));
	if ($limite <= 0) {
		return true; // -1 : pas de limite déclarée
	}

	$besoin = ($largeur * $hauteur + IMG_COTE_MAX * IMG_COTE_MAX) * 4;

	return ($limite - memory_get_usage(true)) > (int) ($besoin * 1.4);
}

/** @return \GdImage|null */
function imgCharger(string $chemin, string $extension)
{
	$image = match ($extension) {
		'jpg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($chemin) : false,
		'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($chemin) : false,
		'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($chemin) : false,
		default => false,
	};

	return $image === false ? null : $image;
}

/**
 * Recompresse et écrit dans le dossier des médias.
 *
 * @return string|null nom du fichier créé, ou null s'il faut garder l'original
 */
function imgRecompresser(string $source, string $extension, string $dossier): ?string
{
	if (!imgCompressionDisponible()) {
		return null;
	}

	$infos = @getimagesize($source);
	if ($infos === false || (int) $infos[0] < 1 || (int) $infos[1] < 1) {
		return null;
	}
	if (!imgMemoireSuffisante((int) $infos[0], (int) $infos[1])) {
		return null;
	}

	$image = imgCharger($source, $extension);
	if ($image === null) {
		return null;
	}

	$largeur = imagesx($image);
	$hauteur = imagesy($image);
	$facteur = min(1, IMG_COTE_MAX / max($largeur, $hauteur));

	if ($facteur < 1) {
		$cible = @imagecreatetruecolor(max(1, (int) round($largeur * $facteur)), max(1, (int) round($hauteur * $facteur)));
		if ($cible === false) {
			imagedestroy($image);

			return null;
		}
		imagealphablending($cible, false);
		imagesavealpha($cible, true);
		imagecopyresampled($cible, $image, 0, 0, 0, 0, imagesx($cible), imagesy($cible), $largeur, $hauteur);
		imagedestroy($image);
		$image = $cible;
	}

	$sortie = imgFormatSortie();

	if ($sortie === 'jpg') {
		// Le JPEG ne gère pas la transparence : on la pose sur du blanc, sans
		// quoi GD la rendrait en noir.
		$fond = @imagecreatetruecolor(imagesx($image), imagesy($image));
		if ($fond === false) {
			imagedestroy($image);

			return null;
		}
		imagealphablending($fond, true);
		imagefilledrectangle($fond, 0, 0, imagesx($fond) - 1, imagesy($fond) - 1, (int) imagecolorallocate($fond, 255, 255, 255));
		imagecopy($fond, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
		imagedestroy($image);
		$image = $fond;
	} else {
		imagealphablending($image, false);
		imagesavealpha($image, true);
	}

	$nom = bin2hex(random_bytes(8)) . '.' . $sortie;
	$destination = $dossier . '/' . $nom;

	$ecrit = $sortie === 'webp'
		? @imagewebp($image, $destination, IMG_QUALITE)
		: @imagejpeg($image, $destination, IMG_QUALITE);
	imagedestroy($image);

	if ($ecrit !== true || !is_file($destination)) {
		@unlink($destination);

		return null;
	}

	// Une image déjà légère et à la bonne taille n'a rien à gagner : si le
	// réencodage l'alourdit, on garde l'original.
	if (filesize($destination) >= filesize($source)) {
		@unlink($destination);

		return null;
	}

	return $nom;
}

/**
 * Range un fichier reçu dans $dossier et retourne son URL publique.
 *
 * @param array<string, mixed> $fichier une entrée de $_FILES
 * @return array{url: ?string, erreur: ?string}
 */
function imgRanger(array $fichier, string $dossier, string $urlBase): array
{
	$code = $fichier['error'] ?? UPLOAD_ERR_NO_FILE;
	if ($code === UPLOAD_ERR_NO_FILE) {
		return ['url' => null, 'erreur' => null]; // pas de photo envoyée : ce n'est pas une erreur
	}
	if ($code !== UPLOAD_ERR_OK) {
		return ['url' => null, 'erreur' => 'Envoi interrompu (fichier trop volumineux ?).'];
	}

	$temporaire = (string) ($fichier['tmp_name'] ?? '');
	if ($temporaire === '' || !is_uploaded_file($temporaire)) {
		return ['url' => null, 'erreur' => 'Fichier non reçu.'];
	}
	if ((int) ($fichier['size'] ?? 0) > IMG_POIDS_MAX) {
		return ['url' => null, 'erreur' => 'Photo trop lourde (8 Mo maximum).'];
	}

	$extension = imgTypeReel($temporaire);
	if ($extension === null) {
		return ['url' => null, 'erreur' => 'Format non reconnu : JPEG, PNG ou WebP attendus.'];
	}

	if (!is_dir($dossier) && !@mkdir($dossier, 0755, true)) {
		return ['url' => null, 'erreur' => 'Dossier de destination indisponible.'];
	}
	if (!is_writable($dossier)) {
		return ['url' => null, 'erreur' => 'Dossier de destination en lecture seule.'];
	}

	$optimise = imgRecompresser($temporaire, $extension, $dossier);
	if ($optimise !== null) {
		return ['url' => $urlBase . '/' . $optimise, 'erreur' => null];
	}

	// Repli : l'original, sous un nom tiré au hasard. Jamais le nom fourni par
	// le client, qui pourrait viser un autre dossier ou écraser un fichier.
	$nom = bin2hex(random_bytes(8)) . '.' . $extension;
	if (!move_uploaded_file($temporaire, $dossier . '/' . $nom)) {
		return ['url' => null, 'erreur' => 'Enregistrement impossible.'];
	}

	return ['url' => $urlBase . '/' . $nom, 'erreur' => null];
}

/** Supprime un média rangé par imgRanger(), et rien d'autre. */
function imgSupprimer(?string $url, string $dossier, string $urlBase): void
{
	if ($url === null || $url === '' || !str_starts_with($url, $urlBase . '/')) {
		return;
	}

	// basename() coupe court à tout ../ : on ne peut viser que ce dossier.
	$chemin = $dossier . '/' . basename($url);
	if (is_file($chemin)) {
		@unlink($chemin);
	}
}
