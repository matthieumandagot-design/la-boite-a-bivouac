<?php
declare(strict_types=1);

/**
 * Accès aux clés Stripe (docs/SPEC-module-commercial.md §2).
 *
 * Les clés ne sont jamais dans le dépôt : elles arrivent par les secrets GitHub
 * Actions et sont écrites dans api/config.php au déploiement par
 * scripts/write-api-config.mjs. Ce fichier est le seul à les lire, pour qu'aucun
 * endpoint n'ait à savoir d'où elles viennent ni quel mode est actif.
 *
 * Deux jeux de constantes cohabitent, un par mode :
 *
 *   STRIPE_TEST_SECRET_KEY / STRIPE_TEST_WEBHOOK_SECRET
 *   STRIPE_LIVE_SECRET_KEY / STRIPE_LIVE_WEBHOOK_SECRET
 *
 * Le mode effectif ne vient pas d'ici mais de data/settings.json
 * (payment_mode), réglé depuis /admin/parametres.php. Une clé de test ne peut
 * donc pas servir un paiement réel, ni l'inverse, même par erreur de code.
 *
 * Principe : échec fermé. Clé absente ou paire incomplète, stripeCles() renvoie
 * null et l'appelant doit refuser la demande. Aucune fonction ne renvoie de clé
 * à destination de l'affichage — stripeEmpreinteConstante() est là pour ça.
 */

require_once __DIR__ . '/settings.php';

/**
 * Charge api/config-stripe.php si ce n'est pas déjà fait.
 *
 * Fichier distinct de api/config.php à dessein : celui-ci définit ADMIN_USERNAME
 * et ADMIN_PASSWORD_HASH, que admin/config.php définit également. Une page
 * d'administration qui incluerait les deux redéfinirait ces constantes et
 * provoquerait des avertissements PHP au milieu de la page.
 *
 * Le fichier est absent en développement local — il est généré au déploiement —
 * et absent en production tant qu'aucune clé n'a été déposée. Ce n'est pas une
 * erreur : cela signifie qu'aucune clé n'est disponible, et tout ce qui dépend
 * du paiement se refusera de lui-même.
 */
function stripeChargerConfig(): void
{
	$chemin = __DIR__ . '/../api/config-stripe.php';
	if (is_file($chemin)) {
		require_once $chemin;
	}
}

/**
 * Noms des constantes portant les clés du mode demandé.
 *
 * Tout ce qui n'est pas « live » est traité comme « test », exactement comme
 * normaliseSettings() : la valeur par défaut d'un réglage douteux ne doit jamais
 * être le paiement réel.
 *
 * @return array{0: string, 1: string} [clé secrète, secret de webhook]
 */
function stripeNomsConstantes(string $mode): array
{
	$prefixe = $mode === 'live' ? 'STRIPE_LIVE_' : 'STRIPE_TEST_';

	return [$prefixe . 'SECRET_KEY', $prefixe . 'WEBHOOK_SECRET'];
}

/** Mode de paiement courant, tel que réglé dans /admin/parametres.php. */
function stripeMode(): string
{
	return lireSettings()['payment_mode'];
}

/**
 * Clés du mode demandé, ou null si l'une des deux manque.
 *
 * Les deux vont de pair : une clé secrète sans secret de webhook permettrait
 * d'encaisser un paiement que le site ne saurait pas rattacher à une commande.
 * Mieux vaut refuser le paiement que perdre la trace d'un encaissement.
 *
 * @return array{secret: string, webhook: string}|null
 */
function stripeCles(?string $mode = null): ?array
{
	$mode ??= stripeMode();
	stripeChargerConfig();

	[$nomSecret, $nomWebhook] = stripeNomsConstantes($mode);
	if (!defined($nomSecret) || !defined($nomWebhook)) {
		return null;
	}

	$secret = (string) constant($nomSecret);
	$webhook = (string) constant($nomWebhook);
	if ($secret === '' || $webhook === '') {
		return null;
	}

	return ['secret' => $secret, 'webhook' => $webhook];
}

/** Le paiement est-il configuré de bout en bout pour ce mode ? */
function stripeConfigure(?string $mode = null): bool
{
	return stripeCles($mode) !== null;
}

/**
 * Empreinte affichable d'une constante de clé : préfixe, puis les quatre
 * derniers caractères. Retourne null si la constante n'existe pas.
 *
 * Sert au diagnostic dans l'administration : vérifier qu'on a bien déployé la
 * clé attendue, et non celle du mauvais compte ou une valeur tronquée au
 * copier-coller. C'est la forme que Stripe utilise lui-même dans son tableau de
 * bord ; quatre caractères ne permettent pas de reconstituer une clé qui en
 * compte plus de trente, ni de s'en servir.
 *
 * Ne jamais élargir cette fenêtre, et ne jamais appeler cette fonction hors
 * d'une page authentifiée.
 */
function stripeEmpreinteConstante(string $nom): ?string
{
	stripeChargerConfig();
	if (!defined($nom)) {
		return null;
	}

	$valeur = (string) constant($nom);
	if ($valeur === '') {
		return null;
	}

	// Le préfixe est tout ce qui précède le dernier souligné : « sk_test_ »,
	// « rk_live_ », « whsec_ ». Une clé sans souligné n'est pas une clé Stripe,
	// mais on la masque quand même plutôt que de la laisser paraître.
	$segments = explode('_', $valeur);
	$prefixe = count($segments) > 1
		? implode('_', array_slice($segments, 0, -1)) . '_'
		: '';

	$corps = (string) end($segments);
	$fin = strlen($corps) >= 8 ? substr($corps, -4) : '';

	return $prefixe . '…' . $fin;
}

/**
 * État des clés d'un mode, pour affichage dans l'administration.
 *
 * @return array<string, array{nom: string, libelle: string, presente: bool, empreinte: ?string}>
 */
function stripeDiagnostic(string $mode): array
{
	[$nomSecret, $nomWebhook] = stripeNomsConstantes($mode);

	$etat = static fn (string $nom, string $libelle): array => [
		'nom' => $nom,
		'libelle' => $libelle,
		'presente' => stripeEmpreinteConstante($nom) !== null,
		'empreinte' => stripeEmpreinteConstante($nom),
	];

	return [
		'secret' => $etat($nomSecret, 'Clé secrète'),
		'webhook' => $etat($nomWebhook, 'Secret de webhook'),
	];
}
