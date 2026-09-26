<?php
declare(strict_types=1);

/**
 * Authentification de l'administration, partagée par les pages PHP de /admin et
 * les endpoints d'administration de /api. Un seul compte :
 * ADMIN_USERNAME / ADMIN_PASSWORD_HASH, défini par le config.php appelant.
 *
 * Deux preuves acceptées : le mot de passe en Basic Auth (envoyé une seule
 * fois par le formulaire de connexion de /admin/), ou la session admin qu'il a
 * ouverte (voir lib/admin-session.php).
 */

require_once __DIR__ . '/../../lib/admin-session.php';

/** En-tête exigé sur les écritures authentifiées par la seule session. */
const ADMIN_ENTETE_REQUETE = 'HTTP_X_ADMIN_REQUETE';

/** @return array{0: string, 1: string}|null */
function adminBasicAuthCredentials(): ?array
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

/** Le mot de passe fourni en Basic Auth est-il le bon ? */
function adminIdentifiantsValides(): bool
{
	[$user, $pass] = adminBasicAuthCredentials() ?? [null, null];

	return $user !== null
		&& $pass !== null
		&& defined('ADMIN_USERNAME')
		&& defined('ADMIN_PASSWORD_HASH')
		&& hash_equals(ADMIN_USERNAME, $user)
		&& password_verify($pass, ADMIN_PASSWORD_HASH);
}

/**
 * L'administrateur est-il authentifié ? Un mot de passe valide ouvre (ou
 * rouvre) la session admin ; sinon, c'est la session qui décide.
 */
function adminAuthentifie(): bool
{
	if (adminIdentifiantsValides()) {
		marquerSessionAdmin();

		return true;
	}

	return sessionAdminOuverte();
}

/**
 * Garde des pages PHP de /admin : sans authentification, renvoie vers le
 * formulaire de connexion, qui ramène ensuite sur la page demandée.
 *
 * Pas de WWW-Authenticate : c'est lui qui faisait surgir la fenêtre de
 * connexion du navigateur. Une fois ses identifiants retenus par le
 * navigateur, aucune déconnexion n'était possible, puisqu'il les renvoyait de
 * lui-même à chaque page.
 */
function exigerAdminPage(): void
{
	if (adminAuthentifie()) {
		return;
	}

	$retour = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/');
	header('Location: /admin/?retour=' . rawurlencode($retour), true, 303);
	exit;
}

/**
 * Garde des endpoints d'administration de /api.
 *
 * Authentifiée par la seule session, une requête qui modifie quelque chose doit
 * porter l'en-tête X-Admin-Requete. Un autre site ne peut pas l'ajouter sans
 * autorisation CORS, que ce serveur ne donne jamais : c'est la protection CSRF
 * de ces endpoints, en plus du cookie SameSite=Lax. Le Basic Auth n'en a pas
 * besoin, un autre site ne connaissant pas le mot de passe.
 */
function requireAdminAuth(): void
{
	if (adminIdentifiantsValides()) {
		marquerSessionAdmin();

		return;
	}

	$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	$lecture = $methode === 'GET' || $methode === 'HEAD';

	if (sessionAdminOuverte() && ($lecture || ($_SERVER[ADMIN_ENTETE_REQUETE] ?? '') === '1')) {
		return;
	}

	// Pas de WWW-Authenticate, pour la même raison que exigerAdminPage().
	header('Content-Type: application/json; charset=utf-8');
	http_response_code(401);
	echo json_encode(['success' => false, 'error' => 'Authentification requise.'], JSON_UNESCAPED_UNICODE);
	exit;
}
