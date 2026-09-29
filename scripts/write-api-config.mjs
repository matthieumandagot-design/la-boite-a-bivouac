import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUTPUT_DIR = path.join(__dirname, "..", "dist", "api");
const OUTPUT_PATH = path.join(OUTPUT_DIR, "config.php");
const STRIPE_PATH = path.join(OUTPUT_DIR, "config-stripe.php");
const SOCIAL_PATH = path.join(OUTPUT_DIR, "config-social.php");

const { ADMIN_USERNAME, ADMIN_PASSWORD_HASH, SMTP_LOGIN, SMTP_PASSWORD, NEWSLETTER_TOKEN_SALT } = process.env;

/**
 * Clés Stripe : OPTIONNELLES, contrairement aux cinq secrets ci-dessus, et
 * écrites dans un fichier séparé.
 *
 * Deux décisions, pour deux raisons distinctes.
 *
 * Optionnelles : les cinq premiers secrets sont obligatoires — sans eux,
 * config.php n'est pas écrit et /api/* répond 503. Ajouter les clés Stripe à
 * cette liste aurait coupé la newsletter et le formulaire de contact tant que
 * les clés n'étaient pas déposées. Une fonctionnalité qui dort n'a pas à faire
 * tomber celles qui tournent. Une clé absente n'est donc pas une erreur : la
 * constante n'existe pas, et lib/stripe.php refuse alors le paiement dans ce
 * mode. Échec fermé, jamais de paiement à moitié configuré.
 *
 * Fichier séparé : config.php définit déjà ADMIN_USERNAME et
 * ADMIN_PASSWORD_HASH, que admin/config.php définit aussi. Une page
 * d'administration qui voudrait lire les clés Stripe en incluant config.php
 * redéfinirait ces deux constantes et déclencherait des avertissements PHP.
 * Isoler les clés évite la collision, et limite au passage leur portée : les
 * endpoints de la newsletter ne les chargent jamais.
 *
 * Les préfixes sont vérifiés pour attraper la faute la plus courante : coller
 * une clé « live » dans l'emplacement « test », ou coller la clé publique
 * (pk_…) au lieu de la clé secrète. Une valeur mal formée est ignorée plutôt
 * qu'écrite — et rien de son contenu n'est journalisé.
 */
const CLES_STRIPE = [
	// rk_ = clé restreinte, acceptée : c'est même la meilleure pratique côté
	// serveur, avec les seules permissions du tunnel de commande.
	{ nom: "STRIPE_TEST_SECRET_KEY", prefixes: ["sk_test_", "rk_test_"], role: "clé secrète (mode test)" },
	{ nom: "STRIPE_LIVE_SECRET_KEY", prefixes: ["sk_live_", "rk_live_"], role: "clé secrète (mode réel)" },
	// Les secrets de webhook portent le même préfixe en test et en réel : Stripe
	// ne les distingue pas. Impossible de détecter ici une inversion des deux ;
	// c'est le webhook lui-même qui rejettera les signatures invalides.
	{ nom: "STRIPE_TEST_WEBHOOK_SECRET", prefixes: ["whsec_"], role: "secret de webhook (mode test)" },
	{ nom: "STRIPE_LIVE_WEBHOOK_SECRET", prefixes: ["whsec_"], role: "secret de webhook (mode réel)" },
];

function escapeSingleQuoted(value) {
	return value.replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}

/**
 * Écrit dist/api/config-stripe.php avec les clés présentes et bien formées.
 *
 * Ne journalise jamais la valeur d'une clé, pas même tronquée : les journaux
 * d'Actions restent lisibles par quiconque a accès au dépôt, et le masquage
 * automatique de GitHub ne couvre pas une valeur recomposée.
 *
 * Volontairement indépendant du reste : une clé Stripe déposée doit arriver en
 * production même si un autre secret manque, et inversement.
 */
async function ecrireConfigStripe() {
	const lignes = [];
	const retenues = [];

	for (const { nom, prefixes, role } of CLES_STRIPE) {
		const brut = process.env[nom];
		if (!brut) {
			continue;
		}

		// Un copier-coller depuis le tableau de bord ramène souvent une espace ou
		// un retour à la ligne : le supprimer évite une signature invalide
		// impossible à diagnostiquer.
		const valeur = brut.trim();
		if (valeur === "") {
			continue;
		}

		if (!prefixes.some((prefixe) => valeur.startsWith(prefixe))) {
			console.warn(
				`[write-api-config] ${nom} ignoré : une valeur de type « ${role} » doit commencer par ` +
					`${prefixes.join(" ou ")}. Vérifiez que vous n'avez pas collé la clé publique (pk_…), ` +
					"ni une clé du mode opposé.",
			);
			continue;
		}

		lignes.push(`define('${nom}', '${escapeSingleQuoted(valeur)}');`);
		retenues.push(nom);
	}

	if (lignes.length === 0) {
		console.log(
			"[write-api-config] Aucune clé Stripe exploitable : config-stripe.php non généré, le paiement restera indisponible.",
		);
		return;
	}

	const content = `<?php
declare(strict_types=1);

// Généré au déploiement par scripts/write-api-config.mjs, jamais commité.
// Seules les clés effectivement fournies sont définies : une constante absente
// signifie « paiement indisponible dans ce mode », ce que lib/stripe.php
// traduit par un refus. Le mode utilisé vient de data/settings.json
// (payment_mode), réglé dans /admin/parametres.php, pas de ce fichier.
${lignes.join("\n")}
`;

	await writeFile(STRIPE_PATH, content, "utf-8");

	console.log(
		`[write-api-config] ${path.relative(process.cwd(), STRIPE_PATH)} écrit (${retenues.join(", ")}).`,
	);
}

/**
 * Identifiants d'application Google et Facebook, pour la connexion sociale.
 *
 * Optionnels et par paire : un identifiant sans son secret ne sert à rien, et
 * un fournisseur à moitié configuré afficherait un bouton qui échoue. On écrit
 * donc une paire ou rien, et lib/oauth.php ne propose que ce qui est complet.
 *
 * Fichier séparé de config.php pour les mêmes raisons que les clés Stripe :
 * pas de collision de constantes, et les endpoints qui n'en ont pas besoin ne
 * chargent pas ces secrets.
 */
const PAIRES_SOCIALES = [
	{ nom: "Google", constantes: ["GOOGLE_CLIENT_ID", "GOOGLE_CLIENT_SECRET"] },
	{ nom: "Facebook", constantes: ["FACEBOOK_APP_ID", "FACEBOOK_APP_SECRET"] },
];

async function ecrireConfigSocial() {
	const lignes = [];
	const retenus = [];

	for (const { nom, constantes } of PAIRES_SOCIALES) {
		const valeurs = constantes.map((c) => (process.env[c] ?? "").trim());

		if (valeurs.every((v) => v === "")) {
			continue;
		}
		if (valeurs.some((v) => v === "")) {
			console.warn(
				`[write-api-config] ${nom} ignoré : ${constantes.join(" et ")} doivent être fournis tous les deux. ` +
					"Un fournisseur à moitié configuré afficherait un bouton qui échoue.",
			);
			continue;
		}

		constantes.forEach((c, i) => lignes.push(`define('${c}', '${escapeSingleQuoted(valeurs[i])}');`));
		retenus.push(nom);
	}

	if (lignes.length === 0) {
		console.log("[write-api-config] Aucun fournisseur de connexion sociale configuré.");
		return;
	}

	const content = `<?php
declare(strict_types=1);

// Généré au déploiement par scripts/write-api-config.mjs, jamais commité.
// Seuls les fournisseurs entièrement configurés sont définis ; lib/oauth.php
// n'en propose aucun autre. L'URL de retour déclarée chez chaque fournisseur
// doit être exactement https://laboiteabivouac.fr/api/oauth-retour.php
${lignes.join("\n")}
`;

	await writeFile(SOCIAL_PATH, content, "utf-8");

	console.log(`[write-api-config] ${path.relative(process.cwd(), SOCIAL_PATH)} écrit (${retenus.join(", ")}).`);
}

/** Écrit dist/api/config.php : SMTP, identifiants admin et sel de désinscription. */
async function ecrireConfigApi() {
	if (!ADMIN_USERNAME || !ADMIN_PASSWORD_HASH || !SMTP_LOGIN || !SMTP_PASSWORD || !NEWSLETTER_TOKEN_SALT) {
		console.warn(
			"[write-api-config] Secret(s) manquant(s) (ADMIN_USERNAME, ADMIN_PASSWORD_HASH, SMTP_LOGIN, SMTP_PASSWORD, NEWSLETTER_TOKEN_SALT) : dist/api/config.php non généré, /api/* répondra 503.",
		);
		return;
	}

	// Relais SMTP Brevo (ex-Sendinblue) plutôt que ssl0.ovh.net : une boîte
	// mutualisée OVH n'est pas conçue pour ce volume/type d'envoi et souffre
	// d'une réputation d'IP partagée. SMTP_LOGIN est l'identifiant SMTP généré
	// par Brevo (Settings > SMTP & API > SMTP), distinct de l'adresse
	// d'expédition visible (SMTP_FROM) : Brevo autorise un compte à envoyer au
	// nom de n'importe quel expéditeur vérifié.
	const content = `<?php
declare(strict_types=1);

define('SMTP_HOST', 'smtp-relay.brevo.com');
define('SMTP_PORT', 465);
define('SMTP_USER', '${escapeSingleQuoted(SMTP_LOGIN)}');
define('SMTP_PASS', '${escapeSingleQuoted(SMTP_PASSWORD)}');
define('SMTP_FROM', 'contact@laboiteabivouac.fr');
define('SMTP_NAME', 'La Boîte à Bivouac');

define('ADMIN_USERNAME', '${escapeSingleQuoted(ADMIN_USERNAME)}');
define('ADMIN_PASSWORD_HASH', '${escapeSingleQuoted(ADMIN_PASSWORD_HASH)}');

define('UNSUBSCRIBE_SALT', '${escapeSingleQuoted(NEWSLETTER_TOKEN_SALT)}');
`;

	await writeFile(OUTPUT_PATH, content, "utf-8");

	console.log(`[write-api-config] ${path.relative(process.cwd(), OUTPUT_PATH)} écrit.`);
}

async function main() {
	await mkdir(OUTPUT_DIR, { recursive: true });
	await ecrireConfigStripe();
	await ecrireConfigSocial();
	await ecrireConfigApi();
}

main();
