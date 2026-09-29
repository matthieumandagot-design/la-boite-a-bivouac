<?php
declare(strict_types=1);

/**
 * Connexion par Google ou Facebook (OAuth 2.0 / OpenID Connect).
 *
 * En curl, sans SDK ni Composer, comme le reste du module : l'hébergement est
 * mutualisé et nous n'appelons que trois URL par fournisseur.
 *
 * CE QUI IDENTIFIE UNE PERSONNE, C'EST LE « SUJET », PAS SON EMAIL
 * Chaque fournisseur donne un identifiant stable et propre à notre application
 * (`sub` chez Google, `id` chez Facebook). C'est lui qu'on enregistre. Une
 * adresse email se change, se recycle, et n'appartient pas au fournisseur : s'en
 * servir de clé reviendrait à confier nos comptes à la boîte mail de quelqu'un
 * d'autre.
 *
 * POURQUOI ON APPELLE userinfo PLUTÔT QUE DE LIRE L'id_token
 * Google renvoie un JWT signé. Le vérifier demanderait de récupérer et de faire
 * tourner ses clés publiques, du code cryptographique de plus pour un gain nul
 * ici : un appel à userinfo avec le jeton d'accès donne les mêmes informations,
 * directement de Google, sur un canal TLS vérifié. Moins de code, moins à se
 * tromper.
 *
 * Aucune fonction ici ne journalise un secret, un code d'autorisation ou un
 * jeton d'accès.
 */

require_once __DIR__ . '/db.php';

/** URL de retour, à déclarer À L'IDENTIQUE chez Google et chez Facebook. */
const OAUTH_REDIRECTION = 'https://laboiteabivouac.fr/api/oauth-retour.php';

/** Au-delà, l'utilisateur a abandonné ou le fournisseur est en panne. */
const OAUTH_TIMEOUT = 15;

/** Durée de vie d'une tentative de connexion, en secondes. */
const OAUTH_ETAT_TTL = 900;

/** Charge les identifiants d'application, écrits au déploiement. */
function oauthChargerConfig(): void
{
	$chemin = __DIR__ . '/../api/config-social.php';
	if (is_file($chemin)) {
		require_once $chemin;
	}
}

/**
 * Description d'un fournisseur, ou null s'il n'est pas configuré.
 *
 * Absence d'identifiants = fournisseur inexistant, pas fournisseur en panne :
 * le bouton n'est même pas proposé. Échec fermé, comme partout ailleurs.
 *
 * @return array{id: string, nom: string, client_id: string, client_secret: string, autorisation: string, jeton: string, profil: string, scope: string, pkce: bool}|null
 */
function oauthFournisseur(string $nom): ?array
{
	oauthChargerConfig();

	if ($nom === 'google') {
		if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')
			|| GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
			return null;
		}

		return [
			'id' => 'google',
			'nom' => 'Google',
			'client_id' => GOOGLE_CLIENT_ID,
			'client_secret' => GOOGLE_CLIENT_SECRET,
			'autorisation' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'jeton' => 'https://oauth2.googleapis.com/token',
			'profil' => 'https://openidconnect.googleapis.com/v1/userinfo',
			'scope' => 'openid email profile',
			'pkce' => true,
		];
	}

	if ($nom === 'facebook') {
		if (!defined('FACEBOOK_APP_ID') || !defined('FACEBOOK_APP_SECRET')
			|| FACEBOOK_APP_ID === '' || FACEBOOK_APP_SECRET === '') {
			return null;
		}

		// Version d'API figée : Meta retire les anciennes au bout de deux ans, et
		// une montée de version doit être un choix, pas une surprise.
		$v = 'v21.0';

		return [
			'id' => 'facebook',
			'nom' => 'Facebook',
			'client_id' => FACEBOOK_APP_ID,
			'client_secret' => FACEBOOK_APP_SECRET,
			'autorisation' => "https://www.facebook.com/{$v}/dialog/oauth",
			'jeton' => "https://graph.facebook.com/{$v}/oauth/access_token",
			'profil' => "https://graph.facebook.com/{$v}/me?fields=id,email,first_name,last_name",
			'scope' => 'email',
			'pkce' => true,
		];
	}

	return null;
}

/** Les fournisseurs réellement utilisables, pour l'affichage des boutons. */
function oauthFournisseursDisponibles(): array
{
	$disponibles = [];
	foreach (['google', 'facebook'] as $nom) {
		$f = oauthFournisseur($nom);
		if ($f !== null) {
			$disponibles[] = ['id' => $f['id'], 'nom' => $f['nom']];
		}
	}

	return $disponibles;
}

/** Encodage base64 pour URL, sans remplissage — exigé par PKCE (RFC 7636). */
function oauthBase64Url(string $donnees): string
{
	return rtrim(strtr(base64_encode($donnees), '+/', '-_'), '=');
}

/**
 * Prépare une tentative de connexion et retourne l'URL du fournisseur.
 *
 * Deux protections stockées en session :
 *
 *   - `state` : une valeur aléatoire renvoyée telle quelle par le fournisseur.
 *     Sans elle, n'importe qui pourrait provoquer le retour d'un code
 *     d'autorisation dans le navigateur d'un tiers et lier son propre compte
 *     social à la session de la victime.
 *   - PKCE (`code_verifier`) : un secret que seul notre serveur connaît, dont
 *     l'empreinte part à l'aller. Un code d'autorisation intercepté devient
 *     inutilisable sans lui.
 */
function oauthPreparer(array $fournisseur, string $retour): string
{
	$etat = bin2hex(random_bytes(24));
	$verifieur = oauthBase64Url(random_bytes(48));

	$_SESSION['bab_oauth'] = [
		'fournisseur' => $fournisseur['id'],
		'etat' => $etat,
		'verifieur' => $verifieur,
		'retour' => $retour,
		'cree' => time(),
	];

	$parametres = [
		'client_id' => $fournisseur['client_id'],
		'redirect_uri' => OAUTH_REDIRECTION,
		'response_type' => 'code',
		'scope' => $fournisseur['scope'],
		'state' => $etat,
	];

	if ($fournisseur['pkce']) {
		$parametres['code_challenge'] = oauthBase64Url(hash('sha256', $verifieur, true));
		$parametres['code_challenge_method'] = 'S256';
	}

	if ($fournisseur['id'] === 'google') {
		// Laisse choisir le compte : sans cela, quelqu'un connecté à plusieurs
		// comptes Google est renvoyé sur le dernier utilisé, sans le voir.
		$parametres['prompt'] = 'select_account';
	}

	return $fournisseur['autorisation'] . '?' . http_build_query($parametres, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Récupère et efface la tentative en cours.
 *
 * Effacée dans tous les cas : un état laissé en session pourrait être rejoué.
 *
 * @return array{fournisseur: string, etat: string, verifieur: string, retour: string}|null
 */
function oauthConsommerEtat(): ?array
{
	$etat = $_SESSION['bab_oauth'] ?? null;
	unset($_SESSION['bab_oauth']);

	if (!is_array($etat) || !isset($etat['etat'], $etat['fournisseur'], $etat['cree'])) {
		return null;
	}
	if (time() - (int) $etat['cree'] > OAUTH_ETAT_TTL) {
		return null;
	}

	return $etat;
}

/** Requête HTTP générique vers un fournisseur. */
function oauthRequete(string $methode, string $url, array $donnees = [], ?string $porteur = null): array
{
	$ch = curl_init($methode === 'GET' && $donnees !== []
		? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($donnees, '', '&', PHP_QUERY_RFC3986)
		: $url);
	if ($ch === false) {
		return ['ok' => false, 'donnees' => [], 'erreur' => 'Appel impossible.'];
	}

	$entetes = ['Accept: application/json'];
	if ($porteur !== null) {
		$entetes[] = 'Authorization: Bearer ' . $porteur;
	}

	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $entetes,
		CURLOPT_TIMEOUT => OAUTH_TIMEOUT,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	]);

	if ($methode === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($donnees, '', '&', PHP_QUERY_RFC3986));
	}

	$reponse = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);

	if ($reponse === false) {
		return ['ok' => false, 'donnees' => [], 'erreur' => 'Fournisseur injoignable.'];
	}

	$decode = json_decode((string) $reponse, true);
	$decode = is_array($decode) ? $decode : [];

	if ($code < 200 || $code >= 300) {
		return ['ok' => false, 'donnees' => $decode, 'erreur' => 'Réponse ' . $code . ' du fournisseur.'];
	}

	return ['ok' => true, 'donnees' => $decode, 'erreur' => null];
}

/** Échange le code d'autorisation contre un jeton d'accès. */
function oauthEchangerCode(array $fournisseur, string $code, string $verifieur): ?string
{
	$parametres = [
		'client_id' => $fournisseur['client_id'],
		'client_secret' => $fournisseur['client_secret'],
		'redirect_uri' => OAUTH_REDIRECTION,
		'grant_type' => 'authorization_code',
		'code' => $code,
	];
	if ($fournisseur['pkce']) {
		$parametres['code_verifier'] = $verifieur;
	}

	$reponse = $fournisseur['id'] === 'facebook'
		// Graph attend ces paramètres en query, et ignore grant_type.
		? oauthRequete('GET', $fournisseur['jeton'], array_diff_key($parametres, ['grant_type' => null]))
		: oauthRequete('POST', $fournisseur['jeton'], $parametres);

	if (!$reponse['ok']) {
		error_log('[oauth] échange de code refusé par ' . $fournisseur['id'] . ' : ' . (string) $reponse['erreur']);

		return null;
	}

	$jeton = $reponse['donnees']['access_token'] ?? null;

	return is_string($jeton) && $jeton !== '' ? $jeton : null;
}

/**
 * Récupère le profil.
 *
 * @return array{sujet: string, email: string, email_verifie: bool, prenom: string, nom: string}|null
 */
function oauthProfil(array $fournisseur, string $jetonAcces): ?array
{
	$reponse = oauthRequete('GET', $fournisseur['profil'], [], $jetonAcces);
	if (!$reponse['ok']) {
		error_log('[oauth] profil illisible chez ' . $fournisseur['id'] . ' : ' . (string) $reponse['erreur']);

		return null;
	}

	$p = $reponse['donnees'];

	if ($fournisseur['id'] === 'google') {
		$sujet = $p['sub'] ?? null;

		return is_string($sujet) && $sujet !== '' ? [
			'sujet' => $sujet,
			'email' => is_string($p['email'] ?? null) ? strtolower(trim($p['email'])) : '',
			// Google dit explicitement si l'adresse est vérifiée. C'est cette
			// assertion, et elle seule, qui autorisera le rattachement
			// automatique à un compte existant.
			'email_verifie' => ($p['email_verified'] ?? false) === true,
			'prenom' => is_string($p['given_name'] ?? null) ? $p['given_name'] : '',
			'nom' => is_string($p['family_name'] ?? null) ? $p['family_name'] : '',
		] : null;
	}

	$sujet = $p['id'] ?? null;

	return is_string($sujet) && $sujet !== '' ? [
		'sujet' => $sujet,
		'email' => is_string($p['email'] ?? null) ? strtolower(trim($p['email'])) : '',
		// Facebook ne fournit AUCUN indicateur de vérification. On ne présume
		// donc rien : pas de rattachement automatique à un compte existant.
		'email_verifie' => false,
		'prenom' => is_string($p['first_name'] ?? null) ? $p['first_name'] : '',
		'nom' => is_string($p['last_name'] ?? null) ? $p['last_name'] : '',
	] : null;
}

// ── Liaison avec les comptes clients ────────────────────────────────────────

/** @return array<string, mixed>|null */
function identiteTrouver(string $fournisseur, string $sujet): ?array
{
	$requete = bdCommerce()->prepare(
		'SELECT * FROM social_identities WHERE fournisseur = :f AND sujet = :s LIMIT 1'
	);
	$requete->execute([':f' => $fournisseur, ':s' => $sujet]);
	$ligne = $requete->fetch();

	return $ligne === false ? null : $ligne;
}

/** Identités liées à un compte, pour l'affichage. */
function identitesDuClient(int $clientId): array
{
	$requete = bdCommerce()->prepare(
		'SELECT fournisseur, email, created_at FROM social_identities WHERE customer_id = :id ORDER BY fournisseur'
	);
	$requete->execute([':id' => $clientId]);

	return $requete->fetchAll();
}

/**
 * Lie une identité à un compte. Retourne false si elle appartient déjà à un
 * autre client — la contrainte d'unicité tranche, pas une lecture préalable.
 */
function identiteLier(int $clientId, string $fournisseur, string $sujet, string $email): bool
{
	try {
		bdCommerce()->prepare(
			'INSERT INTO social_identities (customer_id, fournisseur, sujet, email, created_at, derniere_connexion)
			 VALUES (:c, :f, :s, :e, :d, :d)'
		)->execute([
			':c' => $clientId,
			':f' => $fournisseur,
			':s' => $sujet,
			':e' => $email,
			':d' => gmdate('c'),
		]);

		return true;
	} catch (PDOException $e) {
		if ($e->getCode() === '23000') {
			return false;
		}
		throw $e;
	}
}

/** Détache une identité d'un compte. */
function identiteDelier(int $clientId, string $fournisseur): bool
{
	$requete = bdCommerce()->prepare(
		'DELETE FROM social_identities WHERE customer_id = :c AND fournisseur = :f'
	);
	$requete->execute([':c' => $clientId, ':f' => $fournisseur]);

	return $requete->rowCount() > 0;
}

/** Horodate la dernière connexion par cette identité. */
function identiteMarquerConnexion(int $identiteId): void
{
	bdCommerce()->prepare('UPDATE social_identities SET derniere_connexion = :d WHERE id = :id')
		->execute([':d' => gmdate('c'), ':id' => $identiteId]);
}

/**
 * Crée un compte à partir d'un profil social.
 *
 * Le mot de passe est un hash d'une valeur aléatoire immédiatement jetée :
 * la colonne est non nulle, et aucun mot de passe ne peut le vérifier. Le
 * drapeau password_defini dit à l'interface qu'il n'y en a pas.
 *
 * @return int|null identifiant du client, ou null si l'adresse est déjà prise
 */
function clientDepuisProfilSocial(array $profil): ?int
{
	$pdo = bdCommerce();
	$maintenant = gmdate('c');

	try {
		$pdo->prepare(
			'INSERT INTO customers (email, password_hash, prenom, nom, created_at, email_verifie_a, password_defini)
			 VALUES (:email, :hash, :prenom, :nom, :cree, :verifie, 0)'
		)->execute([
			':email' => $profil['email'],
			':hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
			':prenom' => $profil['prenom'],
			':nom' => $profil['nom'],
			':cree' => $maintenant,
			// Seule une adresse que le fournisseur affirme vérifiée l'est ici.
			// Sinon le compte suit le parcours ordinaire : email de confirmation.
			':verifie' => $profil['email_verifie'] ? $maintenant : null,
		]);
	} catch (PDOException $e) {
		if ($e->getCode() === '23000') {
			return null;
		}
		throw $e;
	}

	return (int) $pdo->lastInsertId();
}
