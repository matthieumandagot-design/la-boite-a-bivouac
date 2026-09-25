<?php
declare(strict_types=1);

/**
 * Verrou serveur du module commercial
 * (docs/SPEC-module-commercial.md §2 et §5.1).
 *
 * À inclure en TÊTE de chaque endpoint commerce, avant toute autre logique.
 * Module désactivé et pas de session admin → 404 JSON, et rien d'autre ne
 * s'exécute.
 *
 * Le 404 est délibéré : il ne révèle pas qu'un module commercial existe mais
 * dort. Un 403 dirait « c'est là, mais fermé ».
 *
 * EXCEPTION : le webhook de paiement ne doit jamais passer par ce verrou. Il
 * lui faut pouvoir confirmer un règlement même si le module vient d'être
 * désactivé, sinon un client débité resterait sans commande payée (§6.5).
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/admin-session.php';

/**
 * Le module est-il accessible à l'appelant courant ?
 * Vrai s'il est activé pour tous, ou si un admin est reconnu (mode aperçu).
 */
function commerceAccessible(): bool
{
	if (commerceActif()) {
		return true;
	}

	return sessionAdminOuverte();
}

/** Coupe court avec un 404 JSON si le module n'est pas accessible. */
function exigerCommerceActif(): void
{
	if (commerceAccessible()) {
		return;
	}

	http_response_code(404);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE);
	exit;
}
