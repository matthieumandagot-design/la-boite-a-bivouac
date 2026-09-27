<?php
declare(strict_types=1);

/**
 * Validation d'une commande (docs/SPEC-module-commercial.md §6.4).
 *
 *   POST /api/commande-creer.php
 *   { "lignes": [...], "adresse_id": 3, "livraison": "retrait-toulouse", "cgv": true }
 *   → 201 {"ok": true, "numero": "BAB-2026-0001", "total_cents": 204900}
 *   → 409 {"error": "…", "refusees": [...]}  — le panier a changé
 *
 * Le serveur revalide tout : que le client est bien connecté, que l'adresse lui
 * appartient, que chaque ligne est encore commandable, et il recalcule chaque
 * montant. Le total enregistré n'a aucun rapport avec ce que le navigateur
 * croyait afficher.
 *
 * 409 et non 422 quand une ligne a été écartée : ce n'est pas une faute de
 * saisie, c'est le monde qui a bougé entre l'affichage et le clic. Le front
 * recharge le panier et laisse le client décider.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/commandes.php';
require_once __DIR__ . '/../lib/verification-email.php';

apiExigerMethode('POST');
apiExigerBase();

$client = apiExigerClient();

/*
 * Adresse email confirmée exigée (§6.1, « P1 »).
 *
 * C'est ici, et nulle part avant, que la vérification bloque : on peut se
 * connecter et remplir un panier sans, mais pas s'engager. Une boîte est
 * fabriquée sur mesure — sans moyen de joindre le client, ni l'atelier ni lui
 * ne s'en sortent.
 */
if (!emailVerifie($client)) {
	apiJson(403, [
		'error' => 'Confirmez votre adresse email avant de commander. Le lien vous a été envoyé à l\'inscription ; vous pouvez en demander un nouveau depuis votre compte.',
		'email_a_verifier' => true,
	]);
}

// Une commande par minute en moyenne sur une heure : très au-dessus d'un usage
// normal, très en dessous de ce qu'il faudrait pour inonder la base.
apiLimiterDebit('commande', 60, 3600);
apiExigerCsrf();

$donnees = apiCorpsJson();

// Case obligatoire, et seul le booléen vrai compte : « on » ou 1 viendraient
// d'un formulaire bricolé, pas de notre tunnel.
if (($donnees['cgv'] ?? null) !== true) {
	apiJson(422, [
		'error' => 'Vous devez accepter les conditions générales de vente.',
		'champs' => ['cgv' => 'Case obligatoire.'],
	]);
}

$adresseId = is_int($donnees['adresse_id'] ?? null) ? $donnees['adresse_id'] : 0;
if ($adresseId <= 0) {
	apiJson(422, [
		'error' => 'Choisissez une adresse de livraison.',
		'champs' => ['adresse_id' => 'Adresse manquante.'],
	]);
}

// L'appartenance est vérifiée dans la requête : impossible de commander vers
// l'adresse de quelqu'un d'autre en devinant un identifiant.
$requete = bdCommerce()->prepare('SELECT * FROM addresses WHERE id = :id AND customer_id = :client LIMIT 1');
$requete->execute([':id' => $adresseId, ':client' => (int) $client['id']]);
$adresse = $requete->fetch();

if ($adresse === false) {
	apiJson(422, ['error' => 'Adresse introuvable.', 'champs' => ['adresse_id' => 'Adresse introuvable.']]);
}

// Mode de livraison obligatoire, et il doit desservir l'adresse choisie.
$idLivraison = is_string($donnees['livraison'] ?? null) ? $donnees['livraison'] : '';
$modeLivraison = livraisonMode($idLivraison);
if ($modeLivraison === null) {
	apiJson(422, [
		'error' => 'Choisissez un mode de livraison.',
		'champs' => ['livraison' => 'Mode manquant ou inconnu.'],
	]);
}
$refusLivraison = livraisonRefus($modeLivraison, $adresse);
if ($refusLivraison !== null) {
	apiJson(422, ['error' => $refusLivraison, 'champs' => ['livraison' => $refusLivraison]]);
}

$panier = panierRecalcule($donnees['lignes'] ?? [], $idLivraison);

if ($panier['lignes'] === []) {
	apiJson(409, ['error' => 'Votre panier est vide.', 'refusees' => $panier['refusees']]);
}
if ($panier['refusees'] !== []) {
	apiJson(409, [
		'error' => 'Votre panier a changé depuis son affichage. Vérifiez-le avant de valider.',
		'refusees' => $panier['refusees'],
	]);
}

try {
	$commande = commandeCreer((int) $client['id'], $panier, $adresse);
} catch (Throwable $e) {
	error_log('[commande-creer] ' . $e->getMessage());
	apiJson(500, ['error' => 'Enregistrement impossible. Réessayez dans un instant.']);
}

// Aucun email ici : la spec ne les envoie qu'après confirmation du paiement
// (§6.4). Une commande en attente de paiement n'est pas une vente.
apiJson(201, [
	'ok' => true,
	'numero' => $commande['numero'],
	'total_cents' => (int) $panier['total_cents'],
	'statut' => 'en_attente_paiement',
]);
