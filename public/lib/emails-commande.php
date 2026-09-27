<?php
declare(strict_types=1);

/**
 * Emails liés aux commandes (docs/SPEC-module-commercial.md §6.4).
 *
 * La confirmation de commande part UNIQUEMENT après validation du paiement par
 * le webhook. Une commande en attente n'est pas une vente : annoncer « merci
 * pour votre commande » à quelqu'un dont la carte a été refusée serait faux, et
 * l'inciter à attendre une livraison qui ne viendra pas.
 *
 * Les changements de statut suivants (fabrication, expédition, annulation…)
 * sont annoncés au client depuis l'administration (§6.6).
 *
 * Un échec d'envoi ne doit jamais faire échouer le webhook : Stripe réémettrait
 * l'événement, et la commande repasserait au statut payée en boucle. Les
 * fonctions attrapent donc tout et signalent par leur valeur de retour.
 */

require_once __DIR__ . '/commandes.php';
require_once __DIR__ . '/pricing.php';

const EMAIL_ATELIER = 'contact@laboiteabivouac.fr';

/** Échappement HTML pour les emails, indépendant de l'admin. */
function emailH(string $valeur): string
{
	return htmlspecialchars($valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Tableau des lignes, commun aux deux messages. */
function emailLignesCommande(array $lignes): string
{
	$html = '';
	foreach ($lignes as $ligne) {
		$html .= sprintf(
			'<tr><td style="padding:6px 0;">%d × %s</td><td style="padding:6px 0;text-align:right;">%s</td></tr>',
			(int) $ligne['quantite'],
			emailH((string) $ligne['libelle']),
			emailH(formaterCents((int) $ligne['total_cents']))
		);
	}

	return $html;
}

/**
 * Corps du message envoyé au client.
 *
 * Reprend le récapitulatif complet : les dimensions calculées font partie du
 * libellé de la ligne, et les conditions acceptées sont rappelées — ce sont
 * elles qui s'appliquent, même si le texte change ensuite.
 */
function emailConfirmationClient(array $commande, array $lignes, string $prenom): string
{
	$bonjour = $prenom === '' ? 'Bonjour,' : 'Bonjour ' . emailH($prenom) . ',';
	$numero = emailH((string) $commande['numero']);
	$total = emailH(formaterCents((int) $commande['total_cents']));
	$adresse = nl2br(emailH((string) $commande['adresse_livraison']));
	$cgv = emailH((string) $commande['cgv_version']);
	$corpsLignes = emailLignesCommande($lignes);

	$avertissementTest = $commande['payment_mode'] === 'test'
		? '<p style="color:#8c2020;"><strong>Commande de test : aucun paiement réel n\'a été effectué.</strong></p>'
		: '';

	$mentionPrix = pricingProvisoire()
		? '<p style="color:#8c2020;">Les montants de cette commande sont provisoires (' . emailH(pricingMention()) . ').</p>'
		: '';

	return <<<HTML
	<p>{$bonjour}</p>
	{$avertissementTest}
	<p>Nous avons bien reçu votre paiement pour la commande <strong>{$numero}</strong>. Merci de votre confiance.</p>
	<table style="width:100%;border-collapse:collapse;">{$corpsLignes}
		<tr><td style="padding:10px 0;border-top:1px solid #ddd;"><strong>Total TTC</strong></td>
			<td style="padding:10px 0;border-top:1px solid #ddd;text-align:right;"><strong>{$total}</strong></td></tr>
	</table>
	{$mentionPrix}
	<p><strong>Adresse</strong><br />{$adresse}</p>
	<p>Conditions générales de vente applicables : {$cgv}.</p>
	<p>Nous vous tiendrons informé de l'avancement de la fabrication. Vous retrouvez à tout moment le statut de votre commande dans votre espace client.</p>
	<p>— La Boîte à Bivouac</p>
	HTML;
}

/** Corps du message envoyé à l'atelier. */
function emailNotificationAtelier(array $commande, array $lignes, string $client, string $email): string
{
	$numero = emailH((string) $commande['numero']);
	$total = emailH(formaterCents((int) $commande['total_cents']));
	$adresse = nl2br(emailH((string) $commande['adresse_livraison']));
	$mode = $commande['payment_mode'] === 'test' ? ' — MODE TEST, aucun encaissement' : '';
	$corpsLignes = emailLignesCommande($lignes);

	return <<<HTML
	<p><strong>Commande {$numero} payée{$mode}</strong></p>
	<p>{$client} &lt;{$email}&gt;</p>
	<table style="width:100%;border-collapse:collapse;">{$corpsLignes}
		<tr><td style="padding:10px 0;border-top:1px solid #ddd;"><strong>Total TTC</strong></td>
			<td style="padding:10px 0;border-top:1px solid #ddd;text-align:right;"><strong>{$total}</strong></td></tr>
	</table>
	<p><strong>Adresse</strong><br />{$adresse}</p>
	<p><a href="https://laboiteabivouac.fr/admin/commandes.php">Ouvrir dans l'administration</a></p>
	HTML;
}

/**
 * Envoie un email par le SMTP du site. Retourne false au lieu de lever.
 *
 * Une connexion par message : les envois sont rares, et une session SMTP
 * partagée ferait qu'un premier échec emporte les suivants.
 */
function emailEnvoyer(string $destinataire, string $sujet, string $html, string $contexte): bool
{
	$config = __DIR__ . '/../api/config.php';
	if (!is_file($config)) {
		// Absent en développement local : rien ne part, et c'est normal.
		error_log('[emails-commande] config.php absent, aucun email envoyé (' . $contexte . ')');

		return false;
	}

	require_once $config;
	require_once __DIR__ . '/../api/lib/smtp.php';

	try {
		$smtp = new SimpleSmtp(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
		$smtp->connect();
		$smtp->sendMail($destinataire, $sujet, $html);
		$smtp->quit();

		return true;
	} catch (Throwable $e) {
		error_log('[emails-commande] envoi impossible (' . $contexte . ') : ' . $e->getMessage());

		return false;
	}
}

/**
 * Envoie les deux emails d'une commande payée.
 *
 * Deux envois séparés : l'échec de la notification interne ne doit pas priver
 * le client de sa confirmation, et inversement.
 *
 * @return array{client: bool, atelier: bool} ce qui est effectivement parti
 */
function envoyerEmailsCommandePayee(array $commande, array $lignes, array $client): array
{
	$nom = trim(((string) $client['prenom']) . ' ' . ((string) $client['nom']));
	$numero = (string) $commande['numero'];

	return [
		'client' => emailEnvoyer(
			(string) $client['email'],
			'Votre commande ' . $numero . ' — La Boîte à Bivouac',
			emailConfirmationClient($commande, $lignes, (string) $client['prenom']),
			'confirmation client ' . $numero
		),
		'atelier' => emailEnvoyer(
			EMAIL_ATELIER,
			'Commande ' . $numero . ' payée',
			emailNotificationAtelier($commande, $lignes, $nom, (string) $client['email']),
			'notification atelier ' . $numero
		),
	];
}

/**
 * Message affiché au client pour chaque statut (§6.6, « P1 »).
 *
 * « En attente de paiement » n'en a pas : c'est l'état de départ, jamais une
 * nouvelle à annoncer. « Payée » non plus : ce passage-là est annoncé par la
 * confirmation de commande, qui porte le récapitulatif complet.
 *
 * @return array<string, string>
 */
function emailMessagesStatut(): array
{
	return [
		'en_fabrication' => 'Votre Boîte est en cours de fabrication à l\'atelier.',
		'prete' => 'Votre commande est prête. Nous revenons vers vous pour convenir de la livraison ou du retrait.',
		'expediee' => 'Votre commande vient d\'être expédiée.',
		'livree' => 'Votre commande est indiquée comme livrée. Bons bivouacs !',
		'annulee' => 'Votre commande a été annulée. Si un paiement avait été encaissé, il vous sera remboursé.',
		'remboursee' => 'Votre commande a été remboursée. Le montant apparaîtra sur votre compte sous quelques jours, selon votre banque.',
	];
}

/** Ce statut donne-t-il lieu à un email au client ? */
function statutANotifier(string $statut): bool
{
	return array_key_exists($statut, emailMessagesStatut());
}

/** Corps du message de changement de statut. */
function emailChangementStatut(array $commande, string $prenom): string
{
	$statut = (string) $commande['statut'];
	$bonjour = $prenom === '' ? 'Bonjour,' : 'Bonjour ' . emailH($prenom) . ',';
	$numero = emailH((string) $commande['numero']);
	$libelle = emailH(commandeStatutLibelle($statut));
	$message = emailH(emailMessagesStatut()[$statut] ?? '');

	$avertissementTest = $commande['payment_mode'] === 'test'
		? '<p style="color:#8c2020;"><strong>Commande de test : aucun paiement réel n\'a été effectué.</strong></p>'
		: '';

	return <<<HTML
	<p>{$bonjour}</p>
	{$avertissementTest}
	<p>Le statut de votre commande <strong>{$numero}</strong> a changé : <strong>{$libelle}</strong>.</p>
	<p>{$message}</p>
	<p>Vous retrouvez le détail de votre commande dans votre <a href="https://laboiteabivouac.fr/compte/">espace client</a>. Une question ? Répondez simplement à cet email.</p>
	<p>— La Boîte à Bivouac</p>
	HTML;
}

/**
 * Prévient le client que sa commande a changé de statut.
 *
 * Rien ne part vers un compte supprimé : son adresse est une adresse .invalid,
 * et la personne a demandé qu'on l'oublie.
 *
 * @return bool true si l'email est parti
 */
function envoyerEmailChangementStatut(array $commande, array $client): bool
{
	if (!statutANotifier((string) $commande['statut']) || $client['anonymise_a'] !== null) {
		return false;
	}

	$numero = (string) $commande['numero'];

	return emailEnvoyer(
		(string) $client['email'],
		'Commande ' . $numero . ' : ' . commandeStatutLibelle((string) $commande['statut']) . ' — La Boîte à Bivouac',
		emailChangementStatut($commande, (string) $client['prenom']),
		'changement de statut ' . $numero
	);
}
