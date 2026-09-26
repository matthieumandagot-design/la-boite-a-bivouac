<?php
declare(strict_types=1);

/**
 * Session administrateur — sert uniquement à reconnaître un admin en dehors
 * de /admin (voir docs/SPEC-module-commercial.md §5.1, mode aperçu).
 *
 * POURQUOI UNE SESSION EN PLUS DU BASIC AUTH
 * Le mot de passe est vérifié une fois, à la connexion (formulaire de /admin/,
 * qui l'envoie en Basic Auth). Cette session en garde la trace et ouvre
 * ensuite toute l'administration — pages PHP, API et mode aperçu du site
 * public — sans redemander le mot de passe, pendant ADMIN_SESSION_TTL.
 *
 * Avant le 27/09/2026, chaque page PHP exigeait le Basic Auth du navigateur :
 * deux connexions distinctes pour une même administration, et une fenêtre
 * qu'on annulait par réflexe. Elle ne donne toujours accès à rien qu'un mot de
 * passe n'ait ouvert d'abord, expire d'elle-même, et se referme par
 * fermerSessionAdmin().
 */

require_once __DIR__ . '/session.php';

/** Au-delà, la trace admin est ignorée et il faut repasser par /admin. */
const ADMIN_SESSION_TTL = 8 * 3600;

/**
 * Démarre la session, avant toute écriture de sortie.
 *
 * À appeler à la place de session_start(). Le durcissement du cookie vit
 * désormais dans session.php, partagé avec les comptes clients : un seul
 * endroit où les drapeaux Secure / HttpOnly / SameSite sont décidés.
 */
function demarrerSessionAdmin(): void
{
	demarrerSessionSite();
}

/**
 * Marque la session courante comme authentifiée. À appeler après vérification
 * du mot de passe, et seulement là : la date posée ici ouvre toute
 * l'administration pendant ADMIN_SESSION_TTL, sans prolongation à l'usage.
 */
function marquerSessionAdmin(): void
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		demarrerSessionAdmin();
	}

	// Nouvel identifiant au passage à l'état admin : un identifiant de session
	// connu d'avance (fixation) ne doit pas devenir une session admin.
	if (!isset($_SESSION['bab_admin_depuis'])) {
		session_regenerate_id(true);
	}

	$_SESSION['bab_admin_depuis'] = time();
}

/**
 * Referme la session admin. La session client éventuelle (test en mode
 * aperçu) reste ouverte : se déconnecter de l'administration n'a pas à vider
 * un panier ou déconnecter un compte client.
 */
function fermerSessionAdmin(): void
{
	if (!sessionSitePresente()) {
		return;
	}
	if (session_status() !== PHP_SESSION_ACTIVE) {
		demarrerSessionAdmin();
	}

	unset($_SESSION['bab_admin_depuis'], $_SESSION['csrf_token']);
	session_regenerate_id(true);
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
		if (!sessionSitePresente()) {
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
