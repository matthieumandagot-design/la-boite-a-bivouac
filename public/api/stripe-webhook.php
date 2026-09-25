<?php
declare(strict_types=1);

/**
 * Webhook Stripe (docs/SPEC-module-commercial.md §6.5).
 *
 *   POST /api/stripe-webhook.php
 *
 * CE FICHIER N'EST PAS DERRIÈRE commerce-guard.php, ET C'EST VOULU.
 * Il doit pouvoir confirmer un règlement même si l'interrupteur vient d'être
 * refermé : sinon un client débité resterait sans commande payée. Il n'y a donc
 * volontairement aucun appel à exigerCommerceActif() ici, et ce fichier
 * n'inclut même pas le verrou, pour qu'on ne puisse pas l'ajouter par réflexe.
 *
 * Ce qui tient lieu de protection, c'est la signature : seul Stripe connaît le
 * secret de la destination, et un corps non signé est rejeté avant toute
 * lecture. Rien d'autre dans la requête n'est cru — ni le montant, ni le
 * numéro de commande, tant que la signature n'a pas été vérifiée.
 *
 * SEUL LE WEBHOOK FAIT FOI. La page de retour du navigateur ne passe aucune
 * commande à « Payée » : elle peut être ouverte, rechargée ou fabriquée par
 * n'importe qui.
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/stripe-api.php';
require_once __DIR__ . '/../lib/commandes.php';
require_once __DIR__ . '/../lib/clients.php';
require_once __DIR__ . '/../lib/emails-commande.php';

/** Réponse JSON puis fin. Autonome : ce fichier ne dépend pas de commerce-api. */
function webhookReponse(int $code, string $message): void
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	header('Allow: POST');
	webhookReponse(405, 'Méthode non autorisée.');
}

// Le corps BRUT, jamais le tableau décodé : la signature porte sur les octets
// exacts reçus, et un aller-retour par json_decode/json_encode les changerait.
$corps = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if ($corps === '' || $signature === '') {
	webhookReponse(400, 'Requête incomplète.');
}

/**
 * Vérifie la signature contre les secrets des deux modes.
 *
 * Les deux destinations — test et réel — sont les nôtres, et le site peut
 * recevoir un événement de l'une alors qu'il est réglé sur l'autre : un
 * paiement de test confirmé après un passage en mode réel, par exemple. On
 * accepte donc les deux, et c'est plus loin, en comparant au mode de la
 * commande, qu'on refuse une confirmation venue du mauvais environnement.
 */
$modeSigne = null;
foreach (['test', 'live'] as $mode) {
	$cles = stripeCles($mode);
	if ($cles !== null && stripeSignatureValide($corps, $signature, $cles['webhook'])) {
		$modeSigne = $mode;
		break;
	}
}

if ($modeSigne === null) {
	// 400 et rien d'autre : ni journal détaillé, ni indice sur ce qui a échoué.
	webhookReponse(400, 'Signature invalide.');
}

$evenement = json_decode($corps, true);
if (!is_array($evenement) || !is_string($evenement['id'] ?? null) || !is_string($evenement['type'] ?? null)) {
	webhookReponse(400, 'Événement illisible.');
}

$evenementId = $evenement['id'];
$type = $evenement['type'];

// Les événements que nous ne traitons pas reçoivent quand même un 200 : sans
// cela Stripe les réémettrait indéfiniment.
if ($type !== 'checkout.session.completed') {
	webhookReponse(200, 'Événement ignoré.');
}

if (!bdExiste()) {
	// 500 : Stripe réessaiera, et la commande finira par passer à payée une fois
	// la base en place. Répondre 200 perdrait définitivement la confirmation.
	webhookReponse(500, 'Base indisponible.');
}

$session = $evenement['data']['object'] ?? [];
$numero = is_string($session['client_reference_id'] ?? null) && $session['client_reference_id'] !== ''
	? $session['client_reference_id']
	: (string) ($session['metadata']['commande'] ?? '');

$commande = $numero === '' ? null : commandeParNumero($numero);

if ($commande === null) {
	// 200 : l'événement est authentique mais ne nous concerne pas (commande
	// supprimée, autre site partageant le compte). Le réémettre n'y changerait
	// rien.
	webhookReponse(200, 'Commande inconnue.');
}

// Le mode doit concorder. Sans ce contrôle, un paiement du bac à sable pourrait
// marquer payée une commande réelle.
$modeEvenement = ($evenement['livemode'] ?? false) === true ? 'live' : 'test';
if ($modeEvenement !== (string) $commande['payment_mode']) {
	webhookReponse(200, 'Mode de paiement discordant, ignoré.');
}

if (($session['payment_status'] ?? '') !== 'paid') {
	// Paiement différé ou non abouti : la commande reste en attente.
	webhookReponse(200, 'Paiement non abouti.');
}

// Le montant encaissé doit correspondre au total recalculé par le serveur. Une
// différence signale une session fabriquée ou un catalogue modifié en cours de
// route : on ne valide pas, et on laisse une trace pour l'administrateur.
$montant = (int) ($session['amount_total'] ?? -1);
if ($montant !== (int) $commande['total_cents']) {
	error_log(sprintf(
		'[stripe-webhook] montant discordant sur %s : encaissé %d, attendu %d',
		$numero,
		$montant,
		(int) $commande['total_cents']
	));
	webhookReponse(200, 'Montant discordant, commande non validée.');
}

$pdo = bdCommerce();
$dejaPayee = (string) $commande['statut'] === 'payee';

/*
 * Enregistrement de l'événement et passage à « Payée » dans une seule
 * transaction.
 *
 * L'insertion échoue d'elle-même si l'événement a déjà été reçu — la clé
 * primaire s'en charge — et c'est cet échec qui rend le traitement idempotent,
 * sans vérification préalable que deux livraisons simultanées pourraient
 * franchir toutes les deux.
 *
 * Tout dans la même transaction : si la mise à jour échoue, la trace de
 * l'événement disparaît avec elle et la réémission de Stripe pourra réessayer.
 */
try {
	$pdo->beginTransaction();

	$pdo->prepare('INSERT INTO stripe_events (id, type, order_id, recu_a) VALUES (:id, :type, :commande, :recu)')
		->execute([
			':id' => $evenementId,
			':type' => $type,
			':commande' => (int) $commande['id'],
			':recu' => gmdate('c'),
		]);

	if (!$dejaPayee) {
		$pdo->prepare(
			"UPDATE orders
			 SET statut = 'payee', paid_at = COALESCE(paid_at, :date), updated_at = :date,
			     stripe_session_id = :session, stripe_payment_intent_id = :intent
			 WHERE id = :id"
		)->execute([
			':date' => gmdate('c'),
			':session' => (string) ($session['id'] ?? ''),
			':intent' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
			':id' => (int) $commande['id'],
		]);
	}

	$pdo->commit();
} catch (PDOException $e) {
	$pdo->rollBack();

	// 23000 : événement déjà enregistré. C'est le cas nominal d'une seconde
	// livraison, pas une erreur — 200, et surtout aucun second email.
	if ($e->getCode() === '23000') {
		webhookReponse(200, 'Événement déjà traité.');
	}

	error_log('[stripe-webhook] ' . $e->getMessage());
	webhookReponse(500, 'Enregistrement impossible.');
}

// Les emails partent après la transaction, et seulement si c'est bien ce
// passage-ci qui a fait basculer la commande. Un échec d'envoi est journalisé
// mais ne fait pas échouer le webhook : Stripe réémettrait, et la commande
// repasserait en boucle par le même chemin.
if (!$dejaPayee) {
	$client = clientParId((int) $commande['customer_id']);
	if ($client !== null) {
		envoyerEmailsCommandePayee(commandeParId((int) $commande['id']), commandeLignes((int) $commande['id']), $client);
	}
}

webhookReponse(200, 'Commande confirmée.');
