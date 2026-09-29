<?php
declare(strict_types=1);

/**
 * Retour de Google ou Facebook après autorisation.
 *
 *   GET /api/oauth-retour.php?code=…&state=…
 *   → 302 vers la page d'où l'on venait
 *
 * C'est l'URL déclarée chez les deux fournisseurs. Elle doit correspondre au
 * caractère près à OAUTH_REDIRECTION.
 *
 * QUATRE CAS, ET UNE RÈGLE DE RATTACHEMENT PRUDENTE
 *
 *   1. Identité déjà connue → on ouvre la session de ce compte. L'email ne
 *      joue aucun rôle : seul le « sujet » du fournisseur compte.
 *   2. Un client est déjà connecté → on rattache l'identité à SON compte.
 *      C'est le bouton « lier mon compte Google » de l'espace client.
 *   3. Aucune identité, mais un compte existe avec cette adresse :
 *      - Google affirme l'adresse vérifiée → rattachement automatique.
 *      - Facebook ne fournit aucune assertion de vérification → on REFUSE, et
 *        on invite à se connecter par mot de passe puis à lier depuis le compte.
 *        Rattacher sur une adresse non prouvée offrirait une prise de contrôle
 *        à qui parviendrait à déclarer l'adresse d'autrui chez Meta.
 *   4. Rien de tout cela → création d'un compte.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/../lib/session.php';
require_once __DIR__ . '/../lib/client-session.php';
require_once __DIR__ . '/../lib/clients.php';
require_once __DIR__ . '/../lib/oauth.php';
require_once __DIR__ . '/../lib/verification-email.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
	header('Allow: GET');
	http_response_code(405);
	exit;
}

demarrerSessionSite();

/** Redirection avec un motif lisible par la page /compte. */
function oauthFin(string $destination, string $motif = ''): never
{
	header('Cache-Control: no-store');
	header('Location: ' . $destination . ($motif === '' ? '' : (str_contains($destination, '?') ? '&' : '?') . 'oauth=' . urlencode($motif)), true, 302);
	exit;
}

// L'état est consommé quoi qu'il arrive : il ne doit jamais pouvoir être rejoué.
$tentative = oauthConsommerEtat();
$etatRecu = $_GET['state'] ?? '';

if ($tentative === null || !is_string($etatRecu) || !hash_equals($tentative['etat'], $etatRecu)) {
	oauthFin('/compte/', 'etat');
}

$retour = $tentative['retour'];

// L'utilisateur a refusé, ou le fournisseur a renvoyé une erreur.
if (isset($_GET['error']) || !isset($_GET['code']) || !is_string($_GET['code']) || $_GET['code'] === '') {
	oauthFin($retour, 'refus');
}

$fournisseur = oauthFournisseur($tentative['fournisseur']);
if ($fournisseur === null) {
	oauthFin($retour, 'indisponible');
}

$jetonAcces = oauthEchangerCode($fournisseur, $_GET['code'], $tentative['verifieur']);
if ($jetonAcces === null) {
	oauthFin($retour, 'echange');
}

$profil = oauthProfil($fournisseur, $jetonAcces);
if ($profil === null) {
	oauthFin($retour, 'profil');
}

// Facebook peut ne rien renvoyer : compte créé avec un numéro de téléphone, ou
// permission refusée. Sans adresse, impossible de confirmer une commande.
if ($profil['email'] === '' || !emailPlausible($profil['email'])) {
	oauthFin($retour, 'sans-email');
}

if (!bdExiste()) {
	oauthFin($retour, 'indisponible');
}

$identite = identiteTrouver($fournisseur['id'], $profil['sujet']);
$clientConnecte = clientConnecteId();

// ── Cas 1 : identité déjà connue ────────────────────────────────────────────
if ($identite !== null) {
	$client = clientParId((int) $identite['customer_id']);
	if ($client === null || $client['anonymise_a'] !== null) {
		oauthFin($retour, 'compte-ferme');
	}

	identiteMarquerConnexion((int) $identite['id']);
	connecterClient((int) $client['id']);
	oauthFin($retour, 'connecte');
}

// ── Cas 2 : un client est déjà connecté, on rattache à son compte ───────────
if ($clientConnecte !== null) {
	$lie = identiteLier($clientConnecte, $fournisseur['id'], $profil['sujet'], $profil['email']);
	oauthFin($retour, $lie ? 'lie' : 'deja-lie');
}

// ── Cas 3 : un compte existe déjà avec cette adresse ────────────────────────
$existant = clientParEmail($profil['email']);
if ($existant !== null) {
	if ($existant['anonymise_a'] !== null) {
		oauthFin($retour, 'compte-ferme');
	}

	// Seule une adresse que le fournisseur AFFIRME vérifiée autorise le
	// rattachement automatique. Voir l'en-tête de ce fichier.
	if (!$profil['email_verifie']) {
		oauthFin($retour, 'compte-existant');
	}

	identiteLier((int) $existant['id'], $fournisseur['id'], $profil['sujet'], $profil['email']);

	// L'adresse est prouvée par le fournisseur : inutile de redemander une
	// confirmation par email à quelqu'un qui vient de s'authentifier avec.
	if (!emailVerifie($existant)) {
		bdCommerce()->prepare('UPDATE customers SET email_verifie_a = :d, updated_at = :d WHERE id = :id')
			->execute([':d' => gmdate('c'), ':id' => (int) $existant['id']]);
	}

	connecterClient((int) $existant['id']);
	oauthFin($retour, 'connecte');
}

// ── Cas 4 : création d'un compte ────────────────────────────────────────────
$nouveau = clientDepuisProfilSocial($profil);
if ($nouveau === null) {
	// Course : le compte vient d'être créé entre la lecture et l'écriture.
	oauthFin($retour, 'compte-existant');
}

identiteLier($nouveau, $fournisseur['id'], $profil['sujet'], $profil['email']);
connecterClient($nouveau);

// Adresse non prouvée (Facebook) : le compte suit le parcours ordinaire et
// devra confirmer avant de commander.
if (!$profil['email_verifie']) {
	$client = clientParId($nouveau);
	if ($client !== null) {
		verifEnvoyerLien($client, verifCreerJeton($nouveau));
	}
}

oauthFin($retour, 'bienvenue');
