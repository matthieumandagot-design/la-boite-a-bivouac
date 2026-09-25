<?php
declare(strict_types=1);

/**
 * Session administrateur — sert uniquement à reconnaître un admin en dehors
 * de /admin (voir docs/SPEC-module-commercial.md §5.1, mode aperçu).
 *
 * POURQUOI UNE SESSION EN PLUS DU BASIC AUTH
 * L'administration est protégée par HTTP Basic Auth. Le navigateur ne renvoie
 * ces identifiants qu'aux URL du même espace protégé : `/api/settings.php`,
 * appelé depuis les pages publiques, ne les reçoit pas. Sans un cookie de
 * session, il n'aurait donc aucun moyen de savoir qu'un admin regarde le site,
 * et le mode aperçu serait impossible.
 *
 * Le Basic Auth reste seul juge de l'authentification. Cette session n'en est
 * que la trace : elle ne donne accès à rien qu'un mot de passe n'ait ouvert
 * d'abord, et elle expire d'elle-même.
 */

/** Au-delà, la trace admin est ignorée et il faut repasser par /admin. */
const ADMIN_SESSION_TTL = 8 * 3600;

/**
 * Démarre la session avec un cookie durci, avant toute écriture de sortie.
 *
 * À appeler à la place de session_start(). Les paramètres de cookie doivent
 * être posés AVANT le démarrage : une fois la session ouverte, ils n'ont plus
 * aucun effet.
 */
function demarrerSessionAdmin(): void
{
	if (session_status() === PHP_SESSION_ACTIVE) {
		return;
	}

	session_set_cookie_params([
		// Chemin racine : le cookie posé par /admin/*.php doit accompagner les
		// appels à /api/settings.php, sinon le mode aperçu ne peut pas marcher.
		'path' => '/',
		// Le site est servi en HTTPS de bout en bout ; on refuse que ce cookie
		// parte en clair si une URL http:// traînait quelque part.
		'secure' => true,
		'httponly' => true,
		// Lax et non Strict : l'admin doit rester reconnu en arrivant sur le
		// site depuis un lien externe. Suffisant ici, le cookie n'autorisant
		// aucune action d'écriture à lui seul.
		'samesite' => 'Lax',
	]);

	session_start();
}

/** Marque la session courante comme authentifiée. À appeler après le Basic Auth. */
function marquerSessionAdmin(): void
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		demarrerSessionAdmin();
	}

	$_SESSION['bab_admin_depuis'] = time();
}

/**
 * Un administrateur est-il reconnu ? Démarre la session si besoin, pour que
 * les endpoints publics puissent poser la question sans rien savoir du reste.
 */
function sessionAdminOuverte(): bool
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		// Pas de cookie de session : inutile d'en créer un pour un visiteur
		// anonyme, cela poserait un cookie à tout le monde sans raison.
		if (!isset($_COOKIE[session_name()])) {
			return false;
		}
		demarrerSessionAdmin();
	}

	$depuis = $_SESSION['bab_admin_depuis'] ?? null;
	if (!is_int($depuis)) {
		return false;
	}

	if (time() - $depuis > ADMIN_SESSION_TTL) {
		unset($_SESSION['bab_admin_depuis']);
		return false;
	}

	return true;
}
