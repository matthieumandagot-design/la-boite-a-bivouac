<?php
declare(strict_types=1);

/**
 * Ouverture d'une session de paiement Stripe Checkout
 * (docs/SPEC-module-commercial.md §6.5).
 *
 *   POST /api/checkout.php  { "numero": "BAB-2026-0001" }
 *   → 200 {"url": "https://checkout.stripe.com/…"}
 *
 * Aucune donnée de carte ne passe par le serveur : le client est envoyé sur la
 * page hébergée par Stripe, et c'est le webhook qui confirmera le règlement.
 *
 * LES MONTANTS VIENNENT DE LA COMMANDE, PAS DE LA REQUÊTE
 * Les lignes envoyées à Stripe sont celles enregistrées dans order_items, c'est
 * à dire l'instantané figé au moment de la validation. Le navigateur ne
 * transmet que le numéro de commande ; tout le reste est relu en base. Un
 * catalogue modifié entre-temps ne change donc pas ce qui est facturé — c'est
 * bien le montant que le client a accepté.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/commandes.php';
require_once __DIR__ . '/../lib/stripe-api.php';
require_once __DIR__ . '/../lib/admin-session.php';

apiExigerMethode('POST');
apiExigerBase();

$client = apiExigerClient();

apiLimiterDebit('checkout', 30, 3600);
apiExigerCsrf();

$donnees = apiCorpsJson();
$numero = is_string($donnees['numero'] ?? null) ? trim($donnees['numero']) : '';

$commande = $numero === '' ? null : commandeParNumero($numero);
if ($commande === null || (int) $commande['customer_id'] !== (int) $client['id']) {
	// Même réponse pour « n'existe pas » et « n'est pas la vôtre » : un numéro
	// de commande se devine, il ne doit rien révéler.
	apiJson(404, ['error' => 'Commande introuvable.']);
}

if ((string) $commande['statut'] !== 'en_attente_paiement') {
	apiJson(409, [
		'error' => 'Cette commande n\'attend pas de paiement.',
		'statut' => (string) $commande['statut'],
	]);
}

$mode = (string) $commande['payment_mode'];

/*
 * En mode test, seul un administrateur peut lancer un paiement (§6.5).
 *
 * Un visiteur qui « paierait » avec une carte de test repartirait avec une
 * commande confirmée sans avoir été débité : il croirait avoir acheté, et
 * l'atelier fabriquerait pour rien.
 */
if ($mode === 'test' && !sessionAdminOuverte()) {
	apiJson(403, ['error' => 'Le paiement est en mode test : il n\'est ouvert qu\'à l\'administration.']);
}

$lignes = commandeLignes((int) $commande['id']);
if ($lignes === []) {
	apiJson(409, ['error' => 'Commande vide.']);
}

$parametres = [
	'mode' => 'payment',
	'client_reference_id' => (string) $commande['numero'],
	'customer_email' => (string) $client['email'],
	'metadata' => ['commande' => (string) $commande['numero']],
	'success_url' => 'https://laboiteabivouac.fr/commande/?paiement=ok&numero=' . rawurlencode((string) $commande['numero']),
	'cancel_url' => 'https://laboiteabivouac.fr/commande/?paiement=annule&numero=' . rawurlencode((string) $commande['numero']),
	'line_items' => [],
];

foreach ($lignes as $ligne) {
	// Une ligne à 0 € (retrait à l'atelier) n'a rien à encaisser : on ne la
	// transmet pas, le total Stripe reste celui de la commande.
	if ((int) $ligne['prix_unitaire_cents'] === 0) {
		continue;
	}
	$parametres['line_items'][] = [
		'quantity' => (int) $ligne['quantite'],
		'price_data' => [
			'currency' => 'eur',
			'unit_amount' => (int) $ligne['prix_unitaire_cents'],
			'product_data' => [
				// Stripe refuse un nom vide et tronque au-delà de 250 caractères ;
				// on coupe nous-mêmes pour une erreur de moins.
				'name' => substr((string) $ligne['libelle'], 0, 250),
			],
		],
	];
}

// Clé d'idempotence = numéro de commande (§6.5). Un double clic, un
// rechargement ou un réseau capricieux ne créent pas deux paiements : Stripe
// renvoie la session déjà ouverte.
$reponse = stripeAppel('POST', '/v1/checkout/sessions', $parametres, 'checkout-' . $commande['numero']);

if (!$reponse['ok']) {
	error_log('[checkout] ' . $numero . ' : ' . (string) $reponse['erreur']);
	apiJson(502, ['error' => 'Le paiement est momentanément indisponible. Réessayez dans un instant.']);
}

$session = $reponse['donnees'];
$url = is_string($session['url'] ?? null) ? $session['url'] : '';

if ($url === '') {
	apiJson(502, ['error' => 'Réponse inattendue du prestataire de paiement.']);
}

// On garde l'identifiant de session pour rapprocher un paiement de sa commande
// depuis l'administration, même si le webhook tarde.
bdCommerce()->prepare('UPDATE orders SET stripe_session_id = :session, updated_at = :maj WHERE id = :id')
	->execute([
		':session' => (string) ($session['id'] ?? ''),
		':maj' => gmdate('c'),
		':id' => (int) $commande['id'],
	]);

apiJson(200, ['url' => $url, 'mode' => $mode]);
