<?php
declare(strict_types=1);

/**
 * Session d'un client connecté (docs/SPEC-module-commercial.md §6.1).
 *
 * Partage la session PHP de l'administration (voir session.php) mais avec des
 * clés distinctes : un administrateur qui teste la boutique en mode aperçu est
 * les deux à la fois, et l'un ne doit rien donner de l'autre. En particulier,
 * être admin ne connecte à aucun compte client.
 *
 * La session ne conserve qu'un identifiant. Tout le reste — email, nom, statut
 * du compte — est relu en base à chaque requête : un compte anonymisé ou
 * supprimé entre-temps ne doit pas rester utilisable parce que son nom dormait
 * dans un cookie de session.
 */

require_once __DIR__ . '/session.php';

/**
 * Durée de vie d'une connexion client, sans glissement.
 *
 * Sept jours : assez pour revenir finir une commande le week-end suivant, assez
 * court pour qu'une session oubliée sur un ordinateur partagé se referme seule.
 */
const CLIENT_SESSION_TTL = 7 * 24 * 3600;

/**
 * Ouvre la session d'un client après vérification du mot de passe.
 *
 * session_regenerate_id() est indispensable ici : sans lui, un identifiant de
 * session obtenu avant la connexion resterait valable après, ce qui permet la
 * fixation de session (l'attaquant impose un identifiant à la victime, puis
 * réutilise le même une fois qu'elle s'est connectée).
 */
function connecterClient(int $clientId): void
{
	demarrerSessionSite();

	// Le jeton CSRF appartenait à la session anonyme. Le renouveler en même
	// temps que l'identifiant évite qu'un jeton connu d'avance reste valable
	// une fois le client authentifié. L'endpoint de connexion renvoie le
	// nouveau, le front n'a rien à recharger.
	unset($_SESSION['bab_csrf']);

	session_regenerate_id(true);

	$_SESSION['bab_client_id'] = $clientId;
	$_SESSION['bab_client_depuis'] = time();
}

/**
 * Identifiant du client connecté, ou null.
 *
 * Ne crée jamais de session : un visiteur sans cookie est anonyme, point. Cela
 * évite de poser un cookie à chaque passage sur une page publique.
 */
function clientConnecteId(): ?int
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		if (!sessionSitePresente()) {
			return null;
		}
		demarrerSessionSite();
	}

	$id = $_SESSION['bab_client_id'] ?? null;
	$depuis = $_SESSION['bab_client_depuis'] ?? null;

	if (!is_int($id) || $id <= 0 || !is_int($depuis)) {
		return null;
	}

	if (time() - $depuis > CLIENT_SESSION_TTL) {
		deconnecterClient();
		return null;
	}

	return $id;
}

/**
 * Referme la session client.
 *
 * Ne détruit pas la session entière : elle peut aussi porter la trace d'un
 * administrateur en mode aperçu, qu'une déconnexion client n'a pas à effacer.
 * Le jeton CSRF est renouvelé par prudence, puisque l'identité change.
 */
function deconnecterClient(): void
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		if (!sessionSitePresente()) {
			return;
		}
		demarrerSessionSite();
	}

	unset($_SESSION['bab_client_id'], $_SESSION['bab_client_depuis'], $_SESSION['bab_csrf']);

	// L'identifiant de session survivrait autrement à la déconnexion, et
	// resterait valable pour la prochaine connexion depuis ce navigateur.
	session_regenerate_id(true);
}

/**
 * Jeton CSRF de la session courante, créé au besoin.
 *
 * Démarre la session — et pose donc un cookie — parce qu'un jeton sans session
 * ne vérifie rien. N'appeler que depuis les endpoints du module commercial,
 * jamais depuis une page publique.
 */
function jetonCsrfClient(): string
{
	demarrerSessionSite();

	$jeton = $_SESSION['bab_csrf'] ?? null;
	if (!is_string($jeton) || strlen($jeton) !== 64) {
		$jeton = bin2hex(random_bytes(32));
		$_SESSION['bab_csrf'] = $jeton;
	}

	return $jeton;
}

/**
 * Le jeton fourni correspond-il à celui de la session ?
 *
 * Faux si aucune session n'existe : sans session, il n'y a rien à comparer, et
 * accepter la requête reviendrait à ne pas vérifier du tout. hash_equals pour
 * ne pas révéler par le temps de réponse combien de caractères sont corrects.
 */
function jetonCsrfValide(?string $fourni): bool
{
	if (!is_string($fourni) || $fourni === '') {
		return false;
	}

	if (session_status() !== PHP_SESSION_ACTIVE) {
		if (!sessionSitePresente()) {
			return false;
		}
		demarrerSessionSite();
	}

	$attendu = $_SESSION['bab_csrf'] ?? null;
	if (!is_string($attendu) || $attendu === '') {
		return false;
	}

	return hash_equals($attendu, $fourni);
}
