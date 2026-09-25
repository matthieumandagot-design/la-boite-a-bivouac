<?php
declare(strict_types=1);

/**
 * Choix d'un nouveau mot de passe depuis un lien reçu par email
 * (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-motdepasse-reinit.php  { "jeton": "…", "motdepasse": "…" }
 *   → 200 {"ok": true}
 *   → 400 {"error": "Lien expiré ou déjà utilisé."}
 *
 * Aucune connexion automatique : posséder le lien prouve l'accès à la boîte
 * mail, pas la présence du titulaire devant l'écran. Le client se connecte
 * ensuite avec son nouveau mot de passe, ce qui vérifie aussi qu'il l'a retenu.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/motdepasse-reset.php';

apiExigerMethode('POST');
apiExigerBase();

// Un jeton de 32 octets ne se devine pas, mais rien n'empêche d'essayer en
// boucle : la limite de débit rend la tentative inutile.
apiLimiterDebit('motdepasse-reinit', 20, 3600);

apiExigerCsrf();

$donnees = apiCorpsJson();
$jeton = is_string($donnees['jeton'] ?? null) ? trim($donnees['jeton']) : '';
$motDePasse = is_string($donnees['motdepasse'] ?? null) ? $donnees['motdepasse'] : '';

if (strlen($motDePasse) < MDP_LONGUEUR_MIN || strlen($motDePasse) > MDP_LONGUEUR_MAX) {
	apiJson(422, [
		'error' => 'Mot de passe invalide.',
		'champs' => ['motdepasse' => sprintf('Le mot de passe doit faire au moins %d caractères.', MDP_LONGUEUR_MIN)],
	]);
}

$demande = resetDemandeValide($jeton);
if ($demande === null) {
	apiJson(400, ['error' => 'Lien expiré ou déjà utilisé. Demandez-en un nouveau.']);
}

$client = clientParId((int) $demande['customer_id']);
if ($client === null || $client['anonymise_a'] !== null) {
	// Compte supprimé entre la demande et le clic : le lien ne vaut plus rien,
	// et on ne dit pas pourquoi.
	apiJson(400, ['error' => 'Lien expiré ou déjà utilisé. Demandez-en un nouveau.']);
}

changerMotDePasse((int) $client['id'], $motDePasse);
resetMarquerUtilise((int) $demande['id']);

apiJson(200, ['ok' => true]);
