<?php
declare(strict_types=1);

/**
 * Démarrage de la session PHP, commun à l'administration et aux comptes
 * clients (docs/SPEC-module-commercial.md §6.1).
 *
 * Une seule session pour tout le site, deux usages distincts : la trace admin
 * (bab_admin_depuis, voir admin-session.php) et l'identité client
 * (bab_client_id, voir client-session.php). Deux cookies séparés n'apporteraient
 * rien — un même navigateur peut légitimement être les deux à la fois pendant
 * un test en mode aperçu — et doubleraient les réglages à tenir cohérents.
 *
 * Les paramètres de cookie sont posés ici, à un seul endroit : une fois la
 * session démarrée, ils n'ont plus aucun effet, et une page qui aurait oublié
 * le drapeau Secure poserait un cookie de session en clair.
 */

/**
 * Démarre la session avec un cookie durci. Sans effet si elle tourne déjà.
 * À appeler avant toute écriture de sortie.
 */
function demarrerSessionSite(): void
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
		// site depuis un lien externe, et un client qui revient de la page de
		// paiement Stripe doit retrouver sa session. Lax bloque déjà l'envoi du
		// cookie sur une requête POST venue d'un autre site, ce qui couvre
		// l'essentiel du risque CSRF ; le jeton reste la ceinture.
		'samesite' => 'Lax',
	]);

	session_start();
}

/**
 * Le visiteur porte-t-il déjà un cookie de session ?
 *
 * Permet de répondre « non authentifié » sans démarrer de session, donc sans
 * poser un cookie à un visiteur anonyme qui n'a rien demandé.
 */
function sessionSitePresente(): bool
{
	return session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[session_name()]);
}
