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
require_once __DIR__ . '/../lib/oauth.php';

apiExigerMethode('GET');

// Pose la session si elle n'existe pas encore : sans elle, pas de jeton
// vérifiable. Acceptable ici, l'appel ne vient que des pages du module.
$csrf = jetonCsrfClient();

$client = null;
/** Adresse confirmée ? Conditionne le passage de commande, pas la connexion. */
$verifie = false;
/** Comptes Google/Facebook rattachés, et existence d'un mot de passe. */
$identites = [];
$motDePasseDefini = true;
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
		$identites = array_map(
			static fn (array $i): array => ['fournisseur' => (string) $i['fournisseur'], 'email' => (string) $i['email']],
			identitesDuClient((int) $ligne['id'])
		);
		$motDePasseDefini = (int) ($ligne['password_defini'] ?? 1) === 1;
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
	// Les boutons de connexion sociale ne s'affichent que pour les fournisseurs
	// réellement configurés : mieux vaut aucun bouton qu'un bouton qui échoue.
	'fournisseurs' => oauthFournisseursDisponibles(),
	'identites' => $identites,
	'mot_de_passe_defini' => $motDePasseDefini,
]);
