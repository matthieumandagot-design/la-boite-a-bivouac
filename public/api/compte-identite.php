<?php
declare(strict_types=1);

/**
 * Détacher un compte Google ou Facebook.
 *
 *   DELETE /api/compte-identite.php?fournisseur=google → 200 {"ok": true}
 *
 * Refusé si c'est le dernier moyen de se connecter : détacher son unique
 * identité sur un compte sans mot de passe enfermerait son propriétaire
 * dehors. Il faut d'abord définir un mot de passe.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/oauth.php';

apiExigerBase();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'DELETE') {
	header('Allow: DELETE');
	apiJson(405, ['error' => 'Méthode non autorisée.']);
}

$client = apiExigerClient();
apiExigerCsrf();

$fournisseur = $_GET['fournisseur'] ?? '';
if ($fournisseur !== 'google' && $fournisseur !== 'facebook') {
	apiJson(400, ['error' => 'Fournisseur inconnu.']);
}

$clientId = (int) $client['id'];
$identites = identitesDuClient($clientId);
$motDePasseDefini = (int) ($client['password_defini'] ?? 1) === 1;

if (count($identites) <= 1 && !$motDePasseDefini) {
	apiJson(409, [
		'error' => 'C\'est votre seul moyen de connexion. Définissez d\'abord un mot de passe, '
			. 'depuis « mot de passe oublié » sur la page de connexion.',
	]);
}

if (!identiteDelier($clientId, $fournisseur)) {
	apiJson(404, ['error' => 'Aucun compte de ce type n\'est rattaché.']);
}

apiJson(200, ['ok' => true]);
