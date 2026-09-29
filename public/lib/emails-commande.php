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
const EMAIL_SITE = 'https://laboiteabivouac.fr';

/**
 * Habillage des messages (29/09/2026).
 *
 * Contraintes propres à l'email, qui expliquent le style de ce code :
 *   - tout en tableaux et en styles en ligne, les clients de messagerie
 *     ignorent les feuilles de style et la mise en page moderne ;
 *   - largeur bornée à 600 px, la limite au-delà de laquelle Outlook coupe ;
 *   - le logo est une image distante, que Gmail et consorts bloquent par
 *     défaut : rien d'important ne doit reposer dessus, d'où le nom écrit en
 *     toutes lettres juste en dessous et l'attribut alt.
 */
const EMAIL_LOGO = EMAIL_SITE . '/images/logo-badge.png';
const EMAIL_POLICE = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
const EMAIL_FORET = '#122218';
const EMAIL_OR = '#E8B428';
const EMAIL_CREME = '#F5ECD8';
const EMAIL_FOND = '#F4F2EE';
const EMAIL_TRAIT = '#E4E0D8';
const EMAIL_TEXTE = '#1A1A1A';
const EMAIL_DISCRET = '#6A6A62';
const EMAIL_TERRE = '#B85438';

/** Échappement HTML pour les emails, indépendant de l'admin. */
function emailH(string $valeur): string
{
	return htmlspecialchars($valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Document complet : en-tête à la marque, contenu, pied de page.
 *
 * Le préheader est la ligne que les boîtes mail affichent à côté de l'objet.
 * Sans lui, elles y mettent le premier texte du message — ici « La Boîte à
 * Bivouac », ce qui n'apprend rien.
 */
function emailGabarit(string $preheader, string $contenu, string $piedSupplement = ''): string
{
	$police = EMAIL_POLICE;
	$foret = EMAIL_FORET;
	$or = EMAIL_OR;
	$creme = EMAIL_CREME;
	$fond = EMAIL_FOND;
	$trait = EMAIL_TRAIT;
	$discret = EMAIL_DISCRET;
	$logo = EMAIL_LOGO;
	$site = EMAIL_SITE;
	$apercu = emailH($preheader);

	return <<<HTML
	<!doctype html>
	<html lang="fr">
	<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="color-scheme" content="light only" />
	<title>La Boîte à Bivouac</title>
	</head>
	<body style="margin:0;padding:0;background:{$fond};">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$apercu}</div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$fond};">
	<tr><td align="center" style="padding:24px 12px;">
		<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#FFFFFF;border:1px solid {$trait};border-radius:16px;">
			<tr><td align="center" style="background:{$foret};border-radius:16px 16px 0 0;padding:26px 24px 22px;">
				<img src="{$logo}" width="60" height="60" alt="La Boîte à Bivouac" style="display:block;border:0;outline:none;margin:0 auto 12px;" />
				<div style="font-family:{$police};font-size:15px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:{$creme};">La Boîte à Bivouac</div>
				<div style="width:54px;height:3px;background:{$or};margin:14px auto 0;font-size:0;line-height:0;">&nbsp;</div>
			</td></tr>
			<tr><td style="padding:30px 28px 26px;font-family:{$police};font-size:16px;line-height:1.6;color:#1A1A1A;">
	{$contenu}
			</td></tr>
			<tr><td style="border-top:1px solid {$trait};padding:18px 28px 24px;font-family:{$police};font-size:12px;line-height:1.6;color:{$discret};">
				{$piedSupplement}
				<div><a href="{$site}" style="color:{$discret};">laboiteabivouac.fr</a> — une question&nbsp;? Répondez simplement à cet email.</div>
			</td></tr>
		</table>
	</td></tr>
	</table>
	</body>
	</html>
	HTML;
}

/** Bouton d'action, en tableau : les clients Outlook ignorent le padding d'un <a>. */
function emailBouton(string $url, string $texte): string
{
	$police = EMAIL_POLICE;
	$or = EMAIL_OR;
	$foret = EMAIL_FORET;

	return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 6px;"><tr>'
		. '<td align="center" style="background:' . $or . ';border-radius:999px;">'
		. '<a href="' . emailH($url) . '" style="display:inline-block;padding:13px 28px;font-family:' . $police
		. ';font-size:15px;font-weight:700;color:' . $foret . ';text-decoration:none;">' . emailH($texte) . '</a>'
		. '</td></tr></table>';
}

/** Tableau des lignes, commun aux deux messages. */
function emailLignesCommande(array $lignes): string
{
	$html = '';
	foreach ($lignes as $ligne) {
		$html .= sprintf(
			'<tr><td style="padding:9px 0;border-bottom:1px solid %s;">%s%s</td>'
			. '<td style="padding:9px 0 9px 12px;border-bottom:1px solid %s;text-align:right;white-space:nowrap;">%s</td></tr>',
			EMAIL_TRAIT,
			(int) $ligne['quantite'] > 1 ? ((int) $ligne['quantite'] . ' × ') : '',
			emailH((string) $ligne['libelle']),
			EMAIL_TRAIT,
			emailH(formaterCents((int) $ligne['total_cents']))
		);
	}

	return $html;
}

/** Récapitulatif complet : lignes, total et adresse. */
function emailRecapitulatif(array $commande, array $lignes): string
{
	$police = EMAIL_POLICE;
	$foret = EMAIL_FORET;
	$trait = EMAIL_TRAIT;
	$discret = EMAIL_DISCRET;
	$corpsLignes = emailLignesCommande($lignes);
	$total = emailH(formaterCents((int) $commande['total_cents']));
	$adresse = nl2br(emailH((string) $commande['adresse_livraison']));

	return <<<HTML
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-family:{$police};font-size:15px;color:#1A1A1A;margin-top:6px;">
	{$corpsLignes}
		<tr><td style="padding:14px 0 0;font-weight:700;">Total TTC</td>
			<td style="padding:14px 0 0 12px;text-align:right;font-weight:700;font-size:18px;color:{$foret};white-space:nowrap;">{$total}</td></tr>
	</table>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;">
	<tr><td style="border:1px solid {$trait};border-radius:12px;padding:16px 18px;font-family:{$police};font-size:14px;line-height:1.6;color:#1A1A1A;">
		<div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:{$discret};padding-bottom:6px;">Livraison</div>
		{$adresse}
	</td></tr>
	</table>
	HTML;
}

/** Version texte du récapitulatif, pour la partie text/plain du message. */
function emailTexteRecapitulatif(array $commande, array $lignes): string
{
	$texte = '';
	foreach ($lignes as $ligne) {
		$texte .= sprintf(
			"- %s%s : %s\n",
			(int) $ligne['quantite'] > 1 ? ((int) $ligne['quantite'] . ' x ') : '',
			(string) $ligne['libelle'],
			formaterCents((int) $ligne['total_cents'])
		);
	}
	$texte .= 'Total TTC : ' . formaterCents((int) $commande['total_cents']) . "\n\n";
	$texte .= "Livraison\n" . (string) $commande['adresse_livraison'] . "\n";

	return $texte;
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
	$police = EMAIL_POLICE;
	$foret = EMAIL_FORET;
	$discret = EMAIL_DISCRET;
	$terre = EMAIL_TERRE;
	$creme = EMAIL_CREME;
	$numero = emailH((string) $commande['numero']);
	$cgv = emailH((string) $commande['cgv_version']);
	$recapitulatif = emailRecapitulatif($commande, $lignes);
	$bouton = emailBouton(EMAIL_SITE . '/compte/', 'Suivre ma commande');

	$titre = $prenom === '' ? 'Merci pour votre commande&nbsp;!' : 'Merci ' . emailH($prenom) . '&nbsp;!';

	$avertissementTest = $commande['payment_mode'] === 'test'
		? '<div style="border-left:3px solid ' . $terre . ';background:#FBEFEB;padding:10px 14px;margin:0 0 18px;font-size:14px;color:'
			. $terre . ';"><strong>Commande de test</strong> — aucun paiement réel n\'a été effectué.</div>'
		: '';

	$mentionPrix = pricingProvisoire()
		? '<p style="margin:0 0 16px;font-size:14px;color:' . $terre . ';">Les montants de cette commande sont provisoires ('
			. emailH(pricingMention()) . ').</p>'
		: '';

	$contenu = <<<HTML
	{$avertissementTest}
	<h1 style="margin:0 0 14px;font-family:{$police};font-size:26px;line-height:1.25;font-weight:700;color:{$foret};">{$titre}</h1>
	<p style="margin:0 0 18px;">Votre paiement est bien arrivé : la commande <strong>{$numero}</strong> est confirmée. Votre Boîte part maintenant en fabrication, et nous y mettrons le même soin qu'à la nôtre.</p>
	{$mentionPrix}
	{$recapitulatif}
	{$bouton}
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:26px;">
	<tr><td style="background:{$creme};border-radius:12px;padding:18px;font-family:{$police};font-size:14px;line-height:1.7;color:#1A1A1A;">
		<div style="font-weight:700;color:{$foret};padding-bottom:6px;">Et maintenant&nbsp;?</div>
		Nous lançons la fabrication à l'atelier. Vous recevrez un email à chaque étape&nbsp;: mise en fabrication, Boîte prête, puis retrait ou livraison. Le détail reste consultable à tout moment dans votre espace client.
	</td></tr>
	</table>
	<p style="margin:26px 0 0;">Merci de votre confiance&nbsp;— et bons bivouacs.</p>
	<p style="margin:4px 0 0;font-weight:700;color:{$foret};">Toute l'équipe de La Boîte à Bivouac</p>
	HTML;

	$pied = '<div style="padding-bottom:6px;">Conditions générales de vente applicables&nbsp;: ' . $cgv . '.</div>';

	return emailGabarit('Votre commande ' . (string) $commande['numero'] . ' est confirmée.', $contenu, $pied);
}

/** Version texte de la confirmation, pour les clients qui n'affichent pas le HTML. */
function emailTexteConfirmationClient(array $commande, array $lignes, string $prenom): string
{
	$titre = $prenom === '' ? 'Merci pour votre commande !' : 'Merci ' . $prenom . ' !';
	$test = $commande['payment_mode'] === 'test'
		? "COMMANDE DE TEST - aucun paiement reel n'a ete effectue.\n\n"
		: '';
	$provisoire = pricingProvisoire()
		? 'Les montants de cette commande sont provisoires (' . pricingMention() . ").\n\n"
		: '';

	return $titre . "\n\n" . $test
		. 'Votre paiement est bien arrive : la commande ' . (string) $commande['numero'] . " est confirmee.\n"
		. "Votre Boite part maintenant en fabrication.\n\n"
		. $provisoire
		. emailTexteRecapitulatif($commande, $lignes) . "\n"
		. "Suivre ma commande : " . EMAIL_SITE . "/compte/\n\n"
		. "Vous recevrez un email a chaque etape : mise en fabrication, Boite prete, puis retrait ou livraison.\n\n"
		. "Conditions generales applicables : " . (string) $commande['cgv_version'] . "\n\n"
		. "Merci de votre confiance, et bons bivouacs.\n"
		. "Toute l'equipe de La Boite a Bivouac\n"
		. EMAIL_SITE . "\n";
}

/** Corps du message envoyé à l'atelier. */
function emailNotificationAtelier(array $commande, array $lignes, string $client, string $email): string
{
	$police = EMAIL_POLICE;
	$foret = EMAIL_FORET;
	$trait = EMAIL_TRAIT;
	$discret = EMAIL_DISCRET;
	$terre = EMAIL_TERRE;
	$numero = emailH((string) $commande['numero']);
	$nom = emailH($client);
	$courriel = emailH($email);
	$recapitulatif = emailRecapitulatif($commande, $lignes);
	$bouton = emailBouton(EMAIL_SITE . '/admin/commandes.php', 'Ouvrir dans l\'administration');

	$avertissementTest = $commande['payment_mode'] === 'test'
		? '<div style="border-left:3px solid ' . $terre . ';background:#FBEFEB;padding:10px 14px;margin:0 0 18px;font-size:14px;color:'
			. $terre . ';"><strong>Mode test</strong> — aucun encaissement.</div>'
		: '';

	$contenu = <<<HTML
	{$avertissementTest}
	<h1 style="margin:0 0 6px;font-family:{$police};font-size:22px;line-height:1.3;font-weight:700;color:{$foret};">Commande {$numero} payée</h1>
	<p style="margin:0 0 18px;font-size:14px;color:{$discret};">{$nom} &lt;<a href="mailto:{$courriel}" style="color:{$discret};">{$courriel}</a>&gt;</p>
	<div style="height:1px;background:{$trait};font-size:0;line-height:0;">&nbsp;</div>
	{$recapitulatif}
	{$bouton}
	HTML;

	return emailGabarit('Commande ' . (string) $commande['numero'] . ' payée.', $contenu);
}

/** Version texte de la notification interne. */
function emailTexteAtelier(array $commande, array $lignes, string $client, string $email): string
{
	$mode = $commande['payment_mode'] === 'test' ? " (MODE TEST, aucun encaissement)" : '';

	return 'Commande ' . (string) $commande['numero'] . ' payee' . $mode . "\n"
		. $client . ' <' . $email . ">\n\n"
		. emailTexteRecapitulatif($commande, $lignes) . "\n"
		. 'Ouvrir dans l\'administration : ' . EMAIL_SITE . "/admin/commandes.php\n";
}

/**
 * Envoie un email par le SMTP du site. Retourne false au lieu de lever.
 *
 * Une connexion par message : les envois sont rares, et une session SMTP
 * partagée ferait qu'un premier échec emporte les suivants.
 */
function emailEnvoyer(string $destinataire, string $sujet, string $html, string $contexte, ?string $texte = null): bool
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
		// La version texte fait passer le message en multipart/alternative : sans
		// elle, le HTML partirait annoncé en text/plain (voir api/lib/smtp.php).
		$smtp->sendMail($destinataire, $sujet, $html, null, $texte);
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
			'Votre commande ' . $numero . ' est confirmée — La Boîte à Bivouac',
			emailConfirmationClient($commande, $lignes, (string) $client['prenom']),
			'confirmation client ' . $numero,
			emailTexteConfirmationClient($commande, $lignes, (string) $client['prenom'])
		),
		'atelier' => emailEnvoyer(
			EMAIL_ATELIER,
			'Commande ' . $numero . ' payée',
			emailNotificationAtelier($commande, $lignes, $nom, (string) $client['email']),
			'notification atelier ' . $numero,
			emailTexteAtelier($commande, $lignes, $nom, (string) $client['email'])
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

	$police = EMAIL_POLICE;
	$foret = EMAIL_FORET;
	$creme = EMAIL_CREME;
	$terre = EMAIL_TERRE;
	$bouton = emailBouton(EMAIL_SITE . '/compte/', 'Voir ma commande');

	$avertissementTest = $commande['payment_mode'] === 'test'
		? '<div style="border-left:3px solid ' . $terre . ';background:#FBEFEB;padding:10px 14px;margin:0 0 18px;font-size:14px;color:'
			. $terre . ';"><strong>Commande de test</strong> — aucun paiement réel n\'a été effectué.</div>'
		: '';

	$contenu = <<<HTML
	{$avertissementTest}
	<p style="margin:0 0 14px;">{$bonjour}</p>
	<h1 style="margin:0 0 14px;font-family:{$police};font-size:24px;line-height:1.3;font-weight:700;color:{$foret};">{$libelle}</h1>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
	<tr><td style="background:{$creme};border-radius:12px;padding:16px 18px;font-family:{$police};font-size:15px;line-height:1.7;color:#1A1A1A;">
		Commande <strong>{$numero}</strong><br />{$message}
	</td></tr>
	</table>
	{$bouton}
	<p style="margin:26px 0 0;">À bientôt,</p>
	<p style="margin:4px 0 0;font-weight:700;color:{$foret};">Toute l'équipe de La Boîte à Bivouac</p>
	HTML;

	return emailGabarit('Commande ' . (string) $commande['numero'] . ' : ' . commandeStatutLibelle($statut), $contenu);
}

/** Version texte du changement de statut. */
function emailTexteChangementStatut(array $commande, string $prenom): string
{
	$statut = (string) $commande['statut'];
	$test = $commande['payment_mode'] === 'test'
		? "COMMANDE DE TEST - aucun paiement reel n'a ete effectue.\n\n"
		: '';

	return ($prenom === '' ? 'Bonjour,' : 'Bonjour ' . $prenom . ',') . "\n\n" . $test
		. 'Commande ' . (string) $commande['numero'] . ' : ' . commandeStatutLibelle($statut) . ".\n"
		. (emailMessagesStatut()[$statut] ?? '') . "\n\n"
		. 'Voir ma commande : ' . EMAIL_SITE . "/compte/\n\n"
		. "A bientot,\nToute l'equipe de La Boite a Bivouac\n";
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
		'changement de statut ' . $numero,
		emailTexteChangementStatut($commande, (string) $client['prenom'])
	);
}
