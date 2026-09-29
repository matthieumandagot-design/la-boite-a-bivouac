<?php
declare(strict_types=1);

/**
 * Départ d'une connexion Google ou Facebook.
 *
 *   GET /api/oauth-demarrer.php?fournisseur=google[&retour=/commande/]
 *   → 302 vers le fournisseur
 *
 * GET et non POST, et une redirection plutôt qu'une réponse JSON : c'est un
 * lien sur lequel on clique, et le navigateur doit quitter le site. Aucun effet
 * de bord durable — seule une tentative est notée en session, et elle expire.
 *
 * Pas de jeton CSRF ici : il n'y a rien à protéger, la requête ne modifie rien
 * de significatif. C'est le paramètre `state`, vérifié au retour, qui empêche
 * qu'une connexion soit provoquée dans le navigateur de quelqu'un d'autre.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/../lib/session.php';
require_once __DIR__ . '/../lib/oauth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
	header('Allow: GET');
	http_response_code(405);
	exit;
}

/** Renvoie sur /compte avec un motif lisible plutôt qu'une page blanche. */
function oauthEchec(string $motif): never
{
	header('Location: /compte/?oauth=' . urlencode($motif), true, 302);
	exit;
}

$nom = $_GET['fournisseur'] ?? '';
$fournisseur = is_string($nom) ? oauthFournisseur($nom) : null;

if ($fournisseur === null) {
	// Fournisseur inconnu ou non configuré : même réponse, rien à distinguer.
	oauthEchec('indisponible');
}

// Destination après connexion. Seuls les chemins internes sont acceptés : un
// « retour » vers un autre site ferait de cet endpoint une rampe de lancement.
$retour = $_GET['retour'] ?? '/compte/';
if (!is_string($retour) || preg_match('#^/[a-z0-9/_-]*$#i', $retour) !== 1) {
	$retour = '/compte/';
}

demarrerSessionSite();

header('Cache-Control: no-store');
header('Location: ' . oauthPreparer($fournisseur, $retour), true, 302);
exit;
