<?php
declare(strict_types=1);

/**
 * Client HTTP minimal pour l'API Stripe
 * (docs/SPEC-module-commercial.md §6.5).
 *
 * En curl, sans SDK ni Composer : l'hébergement est mutualisé, il n'y a pas de
 * gestionnaire de dépendances, et nous n'appelons que deux points d'entrée. Une
 * bibliothèque complète serait plus de code à maintenir que ce fichier.
 *
 * L'API Stripe attend du formulaire encodé, pas du JSON, avec des crochets pour
 * les structures imbriquées — c'est exactement ce que produit http_build_query.
 *
 * Aucune fonction ici ne journalise la clé, ni ne la renvoie à l'appelant. En
 * cas d'erreur, seul le message de Stripe remonte, jamais la requête envoyée.
 */

require_once __DIR__ . '/stripe.php';

/**
 * Version d'API figée.
 *
 * Stripe fait évoluer la forme de ses réponses ; épingler la version garantit
 * que le code écrit aujourd'hui lira les mêmes champs demain. C'est la version
 * choisie à la création de la destination d'événements, et les deux doivent
 * rester d'accord : le webhook reçoit des charges utiles dans la version de la
 * destination, pas dans celle du compte.
 */
const STRIPE_VERSION_API = '2026-08-26.dahlia';

/** Au-delà, on renonce : une page de paiement qui met dix secondes est perdue. */
const STRIPE_TIMEOUT = 15;

/**
 * Appelle l'API Stripe.
 *
 * @param string $chemin par exemple « /v1/checkout/sessions »
 * @param array<string, mixed> $donnees corps de la requête, imbrication comprise
 * @param string|null $idempotence rejouer la même clé ne crée pas un second objet
 * @return array{ok: bool, code: int, donnees: array, erreur: ?string}
 */
function stripeAppel(string $methode, string $chemin, array $donnees = [], ?string $idempotence = null): array
{
	$cles = stripeCles();
	if ($cles === null) {
		// Échec fermé : sans paire complète, aucun appel n'est tenté. Le message
		// s'adresse à l'administrateur, seul à pouvoir y remédier.
		return [
			'ok' => false,
			'code' => 0,
			'donnees' => [],
			'erreur' => 'Paiement non configuré : clés Stripe absentes pour le mode en cours.',
		];
	}

	$ch = curl_init('https://api.stripe.com' . $chemin);
	if ($ch === false) {
		return ['ok' => false, 'code' => 0, 'donnees' => [], 'erreur' => 'Appel impossible.'];
	}

	$entetes = [
		// Bearer et non l'authentification basique de curl : la clé n'apparaît
		// ainsi ni dans une URL ni dans un paramètre.
		'Authorization: Bearer ' . $cles['secret'],
		'Stripe-Version: ' . STRIPE_VERSION_API,
		'Content-Type: application/x-www-form-urlencoded',
	];
	if ($idempotence !== null && $idempotence !== '') {
		$entetes[] = 'Idempotency-Key: ' . $idempotence;
	}

	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CUSTOMREQUEST => $methode,
		CURLOPT_HTTPHEADER => $entetes,
		CURLOPT_TIMEOUT => STRIPE_TIMEOUT,
		CURLOPT_CONNECTTIMEOUT => 8,
		// Vérification du certificat : jamais désactivée, même « pour tester ».
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	]);

	if ($donnees !== []) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($donnees, '', '&', PHP_QUERY_RFC3986));
	}

	$reponse = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$erreurCurl = curl_error($ch);
	curl_close($ch);

	if ($reponse === false) {
		return ['ok' => false, 'code' => 0, 'donnees' => [], 'erreur' => 'Stripe injoignable : ' . $erreurCurl];
	}

	$decode = json_decode((string) $reponse, true);
	$decode = is_array($decode) ? $decode : [];

	if ($code < 200 || $code >= 300) {
		$message = $decode['error']['message'] ?? 'Erreur Stripe (HTTP ' . $code . ').';

		return ['ok' => false, 'code' => $code, 'donnees' => $decode, 'erreur' => (string) $message];
	}

	return ['ok' => true, 'code' => $code, 'donnees' => $decode, 'erreur' => null];
}

/**
 * Vérifie l'en-tête Stripe-Signature d'un webhook.
 *
 * L'en-tête a la forme « t=1700000000,v1=abc…,v1=def… ». La signature porte sur
 * « timestamp.corps », en HMAC SHA-256 avec le secret de la destination.
 *
 * Trois protections, chacune contre une attaque différente :
 *   - la signature elle-même interdit de forger un événement ;
 *   - la tolérance de cinq minutes empêche de rejouer un ancien message
 *     intercepté, puisque son horodatage est inclus dans la signature ;
 *   - hash_equals empêche de deviner la signature caractère par caractère en
 *     mesurant le temps de réponse.
 *
 * Plusieurs v1 peuvent coexister pendant une rotation de secret : il suffit
 * qu'une seule corresponde.
 */
function stripeSignatureValide(string $corps, string $entete, string $secret, int $tolerance = 300): bool
{
	if ($corps === '' || $entete === '' || $secret === '') {
		return false;
	}

	$horodatage = null;
	$signatures = [];

	foreach (explode(',', $entete) as $partie) {
		$morceaux = explode('=', trim($partie), 2);
		if (count($morceaux) !== 2) {
			continue;
		}
		[$cle, $valeur] = $morceaux;
		if ($cle === 't') {
			$horodatage = ctype_digit($valeur) ? (int) $valeur : null;
		} elseif ($cle === 'v1') {
			$signatures[] = $valeur;
		}
	}

	if ($horodatage === null || $signatures === []) {
		return false;
	}

	// abs() : un message daté du futur est tout aussi suspect qu'un message
	// trop ancien, et signalerait une horloge déréglée d'un côté ou de l'autre.
	if (abs(time() - $horodatage) > $tolerance) {
		return false;
	}

	$attendue = hash_hmac('sha256', $horodatage . '.' . $corps, $secret);

	foreach ($signatures as $signature) {
		if (hash_equals($attendue, $signature)) {
			return true;
		}
	}

	return false;
}
