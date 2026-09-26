<?php
declare(strict_types=1);

/**
 * État du compte courant (docs/SPEC-module-commercial.md §6.1).
 *
 *   GET /api/compte.php → {"connecte": bool, "csrf": "…", "client": {…}|null}
 *
 * C'est aussi le point d'entrée obligé du front : le jeton CSRF renvoyé ici est
 * celui que toutes les requêtes POST du module devront présenter.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/verification-email.php';

apiExigerMethode('GET');

// Pose la session si elle n'existe pas encore : sans elle, pas de jeton
// vérifiable. Acceptable ici, l'appel ne vient que des pages du module.
$csrf = jetonCsrfClient();

$client = null;
/** Adresse confirmée ? Conditionne le passage de commande, pas la connexion. */
$verifie = false;
$id = clientConnecteId();

if ($id !== null) {
	// La base n'est interrogée que si une session prétend à un compte : un
	// visiteur anonyme obtient son jeton même si les migrations n'ont pas
	// encore été appliquées.
	apiExigerBase();

	$ligne = clientParId($id);
	if ($ligne !== null && $ligne['anonymise_a'] === null) {
		$client = clientPublic($ligne);
		$verifie = emailVerifie($ligne);
	} else {
		// Compte supprimé ou anonymisé pendant que la session vivait.
		deconnecterClient();
		$csrf = jetonCsrfClient();
	}
}

apiJson(200, [
	'connecte' => $client !== null,
	'csrf' => $csrf,
	'client' => $client,
	'email_verifie' => $verifie,
]);
