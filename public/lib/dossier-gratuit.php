<?php
declare(strict_types=1);

/**
 * Dossier de fabrication offert (modèle libre, 26/10/2026).
 *
 * La Boîte à Bivouac n'est plus vendue : le configurateur sert désormais à
 * recevoir gratuitement, par email, le dossier que l'atelier de soudure aurait
 * reçu pour une commande — modèle FreeCAD, STEP, plan A3, débit, aperçus.
 *
 * PARCOURS EN DEUX TEMPS
 *   1. demande : le visiteur donne ses cotes et son adresse. On ne lance rien :
 *      on lui envoie un lien de confirmation, valable 24 h.
 *   2. confirmation : depuis ce lien, il clique sur un bouton de la page
 *      /dossier/. C'est seulement là que GitHub Actions est sollicité.
 *
 * Pourquoi un lien plutôt qu'un envoi direct : le dossier pèse plusieurs Mo et
 * part de contact@laboiteabivouac.fr. Sans confirmation, n'importe qui pourrait
 * faire envoyer ces pièces jointes à une adresse qui n'a rien demandé — et la
 * réputation du domaine en pâtirait. Un bouton sur la page, et non le simple
 * clic sur le lien : les antivirus de messagerie ouvrent les liens tout seuls,
 * un GET qui déclencherait la génération serait confirmé par un robot.
 *
 * SANS BASE DE DONNÉES
 * La demande voyage dans le lien lui-même, signée (HMAC-SHA256) : rien n'est
 * stocké côté serveur avant la confirmation, et rien après, sinon l'empreinte
 * du jeton consommé dans data/rate-limits.json, le temps de sa validité.
 *
 * L'ADRESSE NE PART PAS EN CLAIR CHEZ GITHUB
 * Le générateur tourne sur GitHub Actions, qui journalise ce qu'il reçoit.
 * L'adresse y est donc transmise chiffrée (AES-256-CBC puis HMAC-SHA256,
 * « chiffrer puis authentifier »), avec une clé connue du seul serveur OVH et
 * du secret GitHub ATELIER_CLE_EMAIL ; le workflow la déchiffre et la masque
 * dans ses journaux. Voir atelier/dechiffrer_destinataire.py.
 */

require_once __DIR__ . '/remorques.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/../api/dossier-atelier.php';
require_once __DIR__ . '/emails-commande.php';

/** Validité du lien de confirmation. Bornée à 24 h par la purge de rate-limit.php. */
const DG_VALIDITE = 24 * 3600;
const DG_DEMANDES_PAR_IP_HEURE = 5;
const DG_DEMANDES_PAR_EMAIL_JOUR = 3;
const DG_CONFIRMATIONS_PAR_IP_HEURE = 6;

/** Erreur destinée à être montrée telle quelle au visiteur. */
final class DossierGratuitRefus extends RuntimeException
{
	/** @param string[] $details */
	public function __construct(string $message, public readonly int $statut = 422, public readonly array $details = [])
	{
		parent::__construct($message);
	}
}

/** Clés dérivées : une pour signer les liens, deux pour chiffrer l'adresse. */
function dgCles(): array
{
	$c = da_config();
	$brute = base64_decode((string) ($c['CLE_EMAIL'] ?? ''), true);
	if ($brute === false || strlen($brute) < 32) {
		// Sans cette clé, la génération partirait sans destinataire : on refuse
		// dès la demande plutôt que de laisser le visiteur attendre pour rien.
		throw new RuntimeException('CLE_EMAIL absente ou trop courte dans config-dossier-atelier.php');
	}

	return [
		'signature' => hash_hmac('sha256', 'bab-dossier-gratuit-lien', (string) $c['ADMIN_KEY'], true),
		'chiffrement' => hash_hmac('sha256', 'bab-email-chiffrement', $brute, true),
		'authentification' => hash_hmac('sha256', 'bab-email-authentification', $brute, true),
	];
}

function dgB64(string $octets): string
{
	return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
}

function dgDeB64(string $texte): ?string
{
	$octets = base64_decode(strtr($texte, '-_', '+/'), true);

	return $octets === false ? null : $octets;
}

/**
 * Chiffre l'adresse pour GitHub. Format : base64url( iv(16) | chiffré | hmac(32) ).
 * Le HMAC couvre l'IV et le chiffré : un octet modifié en route est détecté
 * avant tout déchiffrement.
 */
function dgChiffrerEmail(string $email): string
{
	$k = dgCles();
	$iv = random_bytes(16);
	$chiffre = openssl_encrypt($email, 'aes-256-cbc', $k['chiffrement'], OPENSSL_RAW_DATA, $iv);
	if ($chiffre === false) {
		throw new RuntimeException('Chiffrement indisponible (openssl).');
	}
	$mac = hash_hmac('sha256', $iv . $chiffre, $k['authentification'], true);

	return dgB64($iv . $chiffre . $mac);
}

/** Pendant de dgChiffrerEmail, utilisé par les tests. */
function dgDechiffrerEmail(string $jeton): ?string
{
	$k = dgCles();
	$o = dgDeB64($jeton);
	if ($o === null || strlen($o) < 16 + 16 + 32) {
		return null;
	}
	$iv = substr($o, 0, 16);
	$mac = substr($o, -32);
	$chiffre = substr($o, 16, -32);
	if (!hash_equals(hash_hmac('sha256', $iv . $chiffre, $k['authentification'], true), $mac)) {
		return null;
	}
	$clair = openssl_decrypt($chiffre, 'aes-256-cbc', $k['chiffrement'], OPENSSL_RAW_DATA, $iv);

	return $clair === false ? null : $clair;
}

/** Entier lu dans une entrée du navigateur, ou null. */
function dgEntier($v): ?int
{
	if (is_int($v)) {
		return $v;
	}
	if (is_float($v) && floor($v) === $v) {
		return (int) $v;
	}
	if (is_string($v) && preg_match('/^\d{1,4}$/', trim($v)) === 1) {
		return (int) trim($v);
	}

	return null;
}

/**
 * Recalcule la Boîte à partir des CHOIX du visiteur — remorque du catalogue ou
 * cotes saisies, hauteur — exactement comme le configurateur l'a affichée.
 * Même règle que lib/panier.php, sans la partie prix.
 *
 * @return array{generateur: array, libelle: string, dimensions: array}
 */
function dgConfiguration(array $entree): array
{
	$cle = is_string($entree['remorque'] ?? null) ? trim($entree['remorque']) : '';
	$remorque = $cle === '' ? null : remorqueParCle($cle);
	if ($cle !== '' && $remorque === null) {
		throw new DossierGratuitRefus('Remorque inconnue : rechargez le configurateur.');
	}

	if ($remorque !== null) {
		$longueur = (int) $remorque['longueur'];
		$largeur = (int) $remorque['largeur'];
		$ridelle = $remorque['ridelle'] === null ? null : (int) $remorque['ridelle'];
		$libelle = $remorque['marque'] . ' ' . $remorque['modele'];
	} else {
		$longueur = dgEntier($entree['longueur'] ?? null) ?? 0;
		$largeur = dgEntier($entree['largeur'] ?? null) ?? 0;
		$ridelle = dgEntier($entree['ridelle'] ?? null);
		$libelle = 'Cotes saisies';
		if ($longueur < 1 || $largeur < 1 || $longueur > 1000 || $largeur > 1000) {
			throw new DossierGratuitRefus('Cotes intérieures manquantes ou invalides.');
		}
	}

	$hauteur = dgEntier($entree['hauteur'] ?? null) ?? RQ_HAUTEUR_DEFAUT_CM;
	$dimensions = rqCalculeBoite($longueur, $largeur, (float) $hauteur, $ridelle);
	if ($dimensions['horsGabarit'] !== []) {
		throw new DossierGratuitRefus(
			'Cette configuration sort du gabarit : ' . implode(', ', $dimensions['horsGabarit']) . '.'
		);
	}

	// Clés attendues par le générateur FreeCAD (api/dossier-atelier.php).
	$generateur = [
		'longueur_int_cm' => $longueur,
		'largeur_int_cm' => $largeur,
		'hauteur_ridelle_cm' => $ridelle ?? RQ_RIDELLE_DEFAUT_CM,
		'hauteur_boite_cm' => $dimensions['hauteur'],
		'ridelle_estimee' => $ridelle === null,
		'marque' => $remorque['marque'] ?? '',
		'modele' => $remorque['modele'] ?? '',
	];

	// Le générateur a ses propres limites, plus serrées que celles du
	// configurateur : on les vérifie dès la demande, avec la vraie raison.
	[, $erreurs] = da_valider($generateur + ['reference' => 'VERIF']);
	if ($erreurs !== []) {
		throw new DossierGratuitRefus(
			'Le dossier automatique ne couvre pas encore cette configuration. Écrivez-nous : nous regarderons votre cas.',
			422,
			$erreurs
		);
	}

	return ['generateur' => $generateur, 'libelle' => $libelle, 'dimensions' => $dimensions];
}

/** Adresse email normalisée, ou refus. */
function dgEmail($brut): string
{
	$email = strtolower(trim((string) $brut));
	if ($email === '' || strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
		throw new DossierGratuitRefus('Adresse email invalide.');
	}

	return $email;
}

/** Jeton de confirmation signé : base64url(json) . "." . base64url(hmac). */
function dgSigner(array $charge): string
{
	$corps = dgB64((string) json_encode($charge, JSON_UNESCAPED_UNICODE));

	return $corps . '.' . dgB64(hash_hmac('sha256', $corps, dgCles()['signature'], true));
}

/** Charge utile d'un jeton intact et non expiré, ou refus. */
function dgVerifier(string $jeton): array
{
	$parties = explode('.', $jeton);
	if (count($parties) !== 2 || strlen($jeton) > 2000) {
		throw new DossierGratuitRefus('Lien de confirmation incomplet : copiez-le en entier depuis l\'email.', 400);
	}
	[$corps, $signature] = $parties;
	$attendue = dgB64(hash_hmac('sha256', $corps, dgCles()['signature'], true));
	if (!hash_equals($attendue, $signature)) {
		throw new DossierGratuitRefus('Lien de confirmation invalide.', 400);
	}
	$charge = json_decode((string) dgDeB64($corps), true);
	if (!is_array($charge) || !isset($charge['e'], $charge['c'], $charge['x'])) {
		throw new DossierGratuitRefus('Lien de confirmation invalide.', 400);
	}
	if ((int) $charge['x'] < time()) {
		throw new DossierGratuitRefus('Ce lien a expiré : refaites la demande depuis le configurateur.', 410);
	}

	return $charge;
}

/** Description lisible de la Boîte, pour les emails et la page. */
function dgResume(array $charge): string
{
	$g = $charge['c'];
	$d = $charge['d'] ?? [];

	return sprintf(
		'%s — caisse %s × %s cm, Boîte de %s × %s × %s cm',
		(string) ($charge['l'] ?? ''),
		$g['longueur_int_cm'],
		$g['largeur_int_cm'],
		$d['longueur'] ?? '?',
		$d['largeur'] ?? '?',
		$g['hauteur_boite_cm']
	);
}

/**
 * Étape 1 : vérifie la demande et envoie le lien de confirmation.
 *
 * @return array{ok: true, message: string}
 */
function dgDemander(array $entree): array
{
	if (trim((string) ($entree['site_web'] ?? '')) !== '') {
		// Champ piège rempli : un robot. On répond comme si tout allait bien.
		return ['ok' => true, 'message' => 'Demande enregistrée.'];
	}
	if (($entree['accord'] ?? false) !== true) {
		throw new DossierGratuitRefus('Merci de cocher la case d\'acceptation des conditions d\'utilisation des plans.');
	}

	$email = dgEmail($entree['email'] ?? '');
	$config = dgConfiguration(is_array($entree['configuration'] ?? null) ? $entree['configuration'] : []);

	if (isRateLimited('dossier-demande:' . clientIp(), DG_DEMANDES_PAR_IP_HEURE, 3600)) {
		throw new DossierGratuitRefus('Trop de demandes depuis votre connexion : réessayez dans une heure.', 429);
	}
	if (isRateLimited('dossier-email:' . hash('sha256', $email), DG_DEMANDES_PAR_EMAIL_JOUR, 86400)) {
		throw new DossierGratuitRefus('Plusieurs demandes ont déjà été faites pour cette adresse aujourd\'hui : réessayez demain.', 429);
	}

	$charge = [
		'e' => $email,
		'c' => $config['generateur'],
		'd' => $config['dimensions'],
		'l' => $config['libelle'],
		'x' => time() + DG_VALIDITE,
		'n' => dgB64(random_bytes(9)),
	];
	$lien = EMAIL_SITE . '/dossier/?t=' . dgSigner($charge);

	if (!dgEnvoyerConfirmation($email, $lien, dgResume($charge))) {
		throw new DossierGratuitRefus('L\'email de confirmation n\'a pas pu partir. Réessayez dans quelques minutes ou écrivez-nous.', 503);
	}

	return ['ok' => true, 'message' => 'Email envoyé.'];
}

/**
 * Étape 2 : consomme le jeton et lance la génération.
 *
 * @return array{ok: true, message: string, reference: string}
 */
function dgConfirmer(string $jeton): array
{
	$charge = dgVerifier($jeton);

	if (isRateLimited('dossier-confirmation:' . clientIp(), DG_CONFIRMATIONS_PAR_IP_HEURE, 3600)) {
		throw new DossierGratuitRefus('Trop de tentatives : réessayez dans une heure.', 429);
	}
	// Usage unique : une fois la génération lancée, le lien ne relance plus
	// rien pendant toute sa validité. Le jeton n'est marqué qu'APRÈS un
	// lancement réussi : si GitHub refuse, le visiteur peut réessayer.
	$cleJeton = 'dossier-jeton:' . hash('sha256', $jeton);
	if (dgJetonUtilise($cleJeton)) {
		throw new DossierGratuitRefus('Ce dossier est déjà en cours de préparation : il arrive par email dans un quart d\'heure environ. Pensez à regarder vos indésirables.', 409);
	}

	$reference = 'LIBRE-' . gmdate('ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
	$retour = declencher_dossier_atelier($charge['c'] + [
		'reference' => $reference,
		'destinataire_chiffre' => dgChiffrerEmail((string) $charge['e']),
	]);

	if (!$retour['ok']) {
		error_log('[dossier-gratuit] ' . $reference . ' : ' . $retour['message'] . ' ' . implode(' ; ', $retour['erreurs']));
		throw new DossierGratuitRefus(
			str_starts_with($retour['message'], 'Trop de demandes')
				? 'Beaucoup de dossiers sont en préparation en ce moment : réessayez ce lien dans une heure.'
				: 'La génération n\'a pas pu être lancée. Réessayez plus tard ou écrivez-nous.',
			503
		);
	}

	isRateLimited($cleJeton, 1, DG_VALIDITE);

	return ['ok' => true, 'message' => 'Génération lancée.', 'reference' => $reference];
}

/**
 * Le jeton a-t-il déjà servi ? Lecture seule du fichier de rate-limit.php,
 * qui purge ses entrées au bout de 24 h, la validité d'un lien.
 */
function dgJetonUtilise(string $cle): bool
{
	$chemin = __DIR__ . '/../data/rate-limits.json';
	$store = is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;

	return is_array($store) && !empty($store[$cle]);
}

/** Email de confirmation, à la marque du site. */
function dgEnvoyerConfirmation(string $email, string $lien, string $resume): bool
{
	$terre = EMAIL_TERRE;
	$discret = EMAIL_DISCRET;
	$contenu = '<p style="margin:0 0 14px;font-size:20px;font-weight:700;">Votre dossier de fabrication est prêt à partir</p>'
		. '<p style="margin:0 0 14px;">Vous avez demandé le dossier de fabrication de La Boîte à Bivouac pour cette configuration&nbsp;:</p>'
		. '<p style="margin:0 0 14px;padding:12px 14px;background:' . EMAIL_CREME . ';border-radius:10px;">' . emailH($resume) . '</p>'
		. '<p style="margin:0;">Confirmez votre adresse pour lancer la génération. Le dossier arrive ensuite par email, en 10 à 15 minutes.</p>'
		. emailBouton($lien, 'Confirmer et recevoir mon dossier')
		. '<p style="margin:14px 0 0;font-size:13px;color:' . $discret . ';">Le lien est valable 24 heures. Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement ce message&nbsp;: rien ne sera envoyé.</p>'
		. '<p style="margin:10px 0 0;font-size:13px;color:' . $terre . ';">Le bouton ne marche pas&nbsp;? Copiez ce lien dans votre navigateur&nbsp;:<br><span style="word-break:break-all;">' . emailH($lien) . '</span></p>';

	$html = emailGabarit('Confirmez votre adresse pour recevoir votre dossier de fabrication.', $contenu);
	$texte = "Votre dossier de fabrication La Boîte à Bivouac\n\n"
		. "Configuration : $resume\n\n"
		. "Confirmez votre adresse pour lancer la génération (lien valable 24 heures) :\n$lien\n\n"
		. "Le dossier arrive ensuite par email, en 10 à 15 minutes.\n"
		. "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : rien ne sera envoyé.\n\n"
		. EMAIL_SITE . "\n";

	return emailEnvoyer($email, 'Confirmez votre demande de dossier de fabrication', $html, 'dossier-gratuit', $texte);
}
