<?php
declare(strict_types=1);

/**
 * Vérification de l'adresse email (docs/SPEC-module-commercial.md §6.1, « P1 »).
 *
 * CE QUI EST BLOQUÉ, ET POURQUOI CE N'EST PAS LA CONNEXION
 * Une adresse non vérifiée empêche de **passer commande**, pas de se connecter
 * ni de remplir un panier. Trois raisons :
 *
 *   1. Une commande qu'on ne peut pas confirmer par email est ingérable : ni
 *      accusé de réception, ni suivi, ni moyen de joindre le client si la boîte
 *      sur mesure pose une question en atelier.
 *   2. Bloquer la connexion casserait la non-énumération des comptes :
 *      l'inscription répond aujourd'hui la même chose que l'adresse soit libre
 *      ou prise, et le front enchaîne sur une connexion ordinaire. Un refus à
 *      cette étape distinguerait les deux cas.
 *   3. Refuser l'accès à quelqu'un qui n'a pas reçu l'email — spam, faute de
 *      frappe, serveur lent — le laisse sans recours. Là, il se connecte, voit
 *      le bandeau, et redemande un lien.
 *
 * Le jeton suit exactement les règles de motdepasse-reset.php : 32 octets
 * aléatoires, stockés hachés en SHA-256, usage unique. Validité plus longue en
 * revanche — sept jours au lieu d'une heure : un lien de vérification traîne
 * souvent dans une boîte mail avant d'être cliqué, et rien d'urgent ne s'y joue.
 */

require_once __DIR__ . '/db.php';

/** Durée de validité d'un lien de vérification, en secondes. */
const VERIF_VALIDITE = 7 * 24 * 3600;

/** Empreinte stockée en base pour un jeton donné. */
function verifEmpreinte(string $jeton): string
{
	return hash('sha256', $jeton);
}

/** Le compte a-t-il une adresse vérifiée ? */
function emailVerifie(array $client): bool
{
	return is_string($client['email_verifie_a'] ?? null) && $client['email_verifie_a'] !== '';
}

/**
 * Crée un jeton de vérification et retourne sa valeur en clair.
 *
 * Les demandes précédentes sont invalidées : le dernier lien reçu est le seul
 * qui marche, ce à quoi tout le monde s'attend.
 */
function verifCreerJeton(int $clientId): string
{
	$pdo = bdCommerce();
	$maintenant = gmdate('c');

	$pdo->prepare('UPDATE email_verifications SET used_at = :maj WHERE customer_id = :id AND used_at IS NULL')
		->execute([':maj' => $maintenant, ':id' => $clientId]);

	$jeton = bin2hex(random_bytes(32));

	$pdo->prepare(
		'INSERT INTO email_verifications (customer_id, token_hash, expires_at, created_at)
		 VALUES (:id, :hash, :expire, :cree)'
	)->execute([
		':id' => $clientId,
		':hash' => verifEmpreinte($jeton),
		':expire' => gmdate('c', time() + VERIF_VALIDITE),
		':cree' => $maintenant,
	]);

	return $jeton;
}

/**
 * Retrouve la demande correspondant à un jeton, si elle est encore utilisable.
 *
 * @return array<string, mixed>|null
 */
function verifDemandeValide(string $jeton): ?array
{
	if (strlen($jeton) !== 64) {
		return null;
	}

	$requete = bdCommerce()->prepare(
		'SELECT * FROM email_verifications WHERE token_hash = :hash AND used_at IS NULL LIMIT 1'
	);
	$requete->execute([':hash' => verifEmpreinte($jeton)]);
	$demande = $requete->fetch();

	if ($demande === false || (string) $demande['expires_at'] < gmdate('c')) {
		return null;
	}

	return $demande;
}

/**
 * Marque l'adresse comme vérifiée et consomme le jeton.
 *
 * Les deux écritures dans une transaction : un jeton consommé sans compte
 * vérifié laisserait le client bloqué avec un lien devenu inutile.
 */
function verifConfirmer(int $demandeId, int $clientId): void
{
	$pdo = bdCommerce();
	$maintenant = gmdate('c');

	$pdo->beginTransaction();
	try {
		$pdo->prepare('UPDATE email_verifications SET used_at = :maj WHERE id = :id')
			->execute([':maj' => $maintenant, ':id' => $demandeId]);

		// COALESCE : une seconde vérification ne réécrit pas la date d'origine.
		$pdo->prepare('UPDATE customers SET email_verifie_a = COALESCE(email_verifie_a, :maj), updated_at = :maj WHERE id = :id')
			->execute([':maj' => $maintenant, ':id' => $clientId]);

		$pdo->commit();
	} catch (Throwable $e) {
		$pdo->rollBack();
		throw $e;
	}
}

/** Lien à envoyer par email. */
function verifLien(string $jeton): string
{
	return 'https://laboiteabivouac.fr/compte/?verif=' . urlencode($jeton);
}

/** Corps de l'email de vérification. */
function verifCorpsEmail(string $prenom, string $lien): string
{
	$bonjour = $prenom === '' ? 'Bonjour,' : 'Bonjour ' . htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') . ',';

	return <<<HTML
	<p>{$bonjour}</p>
	<p>Bienvenue chez La Boîte à Bivouac. Il reste une étape : confirmer que cette adresse est bien la vôtre.</p>
	<p><a href="{$lien}">Confirmer mon adresse email</a></p>
	<p>Ce lien est valable sept jours. Sans confirmation, vous pouvez déjà parcourir la boutique et remplir votre panier, mais pas passer commande — nous devons pouvoir vous joindre au sujet d'une boîte fabriquée sur mesure.</p>
	<p>Si vous n'êtes pas à l'origine de cette inscription, ignorez ce message : sans confirmation, le compte restera inutilisable.</p>
	<p>— La Boîte à Bivouac</p>
	HTML;
}

/**
 * Envoie le lien de vérification. Retourne false si l'envoi n'a pas eu lieu.
 *
 * Aucune erreur n'est renvoyée à l'appelant : la réponse d'une inscription doit
 * être identique quoi qu'il arrive, sans quoi elle révélerait si l'adresse
 * correspond à un compte existant.
 */
function verifEnvoyerLien(array $client, string $jeton): bool
{
	$config = __DIR__ . '/../api/config.php';
	if (!is_file($config)) {
		// Absent en développement local : rien ne part, et c'est normal.
		error_log('[verification-email] config.php absent, aucun envoi');

		return false;
	}

	try {
		require_once $config;
		require_once __DIR__ . '/../api/lib/smtp.php';

		$smtp = new SimpleSmtp(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
		$smtp->connect();
		$smtp->sendMail(
			(string) $client['email'],
			'Confirmez votre adresse — La Boîte à Bivouac',
			verifCorpsEmail((string) $client['prenom'], verifLien($jeton))
		);
		$smtp->quit();

		return true;
	} catch (Throwable $e) {
		error_log('[verification-email] envoi impossible : ' . $e->getMessage());

		return false;
	}
}
