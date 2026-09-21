/**
 * Récupère les contenus édités en ligne (FAQ, catalogue équipements) et les
 * écrit dans les fichiers de repli versionnés, juste avant le build.
 *
 * POURQUOI
 * Les fichiers `public/data/*.json` sont écrits par le PHP sur le serveur et
 * exclus du déploiement FTP : ils ne sont donc jamais dans le dépôt. Les pages
 * Astro, elles, se construisent à partir des `*.default.json` versionnés.
 * Quand les deux divergent, le HTML servi ne correspond plus à ce que le site
 * affiche réellement une fois le JavaScript exécuté.
 *
 * C'était le cas du catalogue : `equipements.default.json` contenait `[]`,
 * donc la page `/equipements` était livrée vide et ne se remplissait qu'après
 * un fetch navigateur. Les robots des moteurs génératifs lisent le HTML brut
 * sans exécuter de JavaScript : le catalogue leur était invisible. Pour la
 * FAQ, l'enjeu est différent mais réel — ses données structurées FAQPage sont
 * générées au build, et annoncer à Google des questions différentes de celles
 * affichées est un risque.
 *
 * Ce script tourne en CI avant `npm run build`. Le cron quotidien de 6 h
 * suffit donc à rattraper toute modification faite depuis l'admin.
 *
 * NE FAIT JAMAIS ÉCHOUER LE BUILD : en cas d'erreur réseau, de JSON invalide
 * ou de réponse inattendue, on conserve le fichier de repli existant et on
 * continue. Même principe que scripts/fetch-social-posts.mjs.
 */

import { readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DOSSIER_DATA = path.join(__dirname, "..", "public", "data");

const SITE = process.env.SITE_URL ?? "https://laboiteabivouac.fr";

const CIBLES = [
	{ nom: "FAQ", source: "faq.json", repli: "faq.default.json" },
	{ nom: "Équipements", source: "equipements.json", repli: "equipements.default.json" },
];

/** Coupe court si le serveur ne répond pas : le build ne doit pas attendre. */
const DELAI_MS = 10_000;

async function litRepli(fichier) {
	try {
		return JSON.parse(await readFile(path.join(DOSSIER_DATA, fichier), "utf8"));
	} catch {
		return null;
	}
}

async function synchronise({ nom, source, repli }) {
	const url = new URL(`/data/${source}`, SITE);

	let distant;
	try {
		const reponse = await fetch(url, {
			signal: AbortSignal.timeout(DELAI_MS),
			headers: { Accept: "application/json" },
		});
		if (!reponse.ok) throw new Error(`le serveur a répondu ${reponse.status}`);
		distant = JSON.parse(await reponse.text());
	} catch (error) {
		console.warn(`[contenu-live] ${nom} ignoré, repli conservé : ${error.message}`);
		return;
	}

	if (!Array.isArray(distant)) {
		console.warn(`[contenu-live] ${nom} ignoré : un tableau était attendu, repli conservé.`);
		return;
	}

	const actuel = await litRepli(repli);

	// Garde-fou : on refuse d'effacer un repli garni avec une réponse vide.
	// Un fichier live vide est bien plus souvent le signe d'un incident serveur
	// que d'une suppression volontaire de tout le contenu. Si la suppression
	// est réelle, vider le fichier de repli dans le dépôt rend la chose
	// explicite et tracée.
	if (distant.length === 0 && Array.isArray(actuel) && actuel.length > 0) {
		console.warn(
			`[contenu-live] ${nom} : la source live est vide alors que le repli contient ` +
				`${actuel.length} entrée(s). Repli conservé — à vérifier.`,
		);
		return;
	}

	if (JSON.stringify(actuel) === JSON.stringify(distant)) {
		console.log(`[contenu-live] ${nom} : déjà à jour (${distant.length} entrée(s)).`);
		return;
	}

	await writeFile(path.join(DOSSIER_DATA, repli), `${JSON.stringify(distant, null, "	")}\n`, "utf8");
	console.log(
		`[contenu-live] ${nom} : repli mis à jour — ${actuel?.length ?? 0} → ${distant.length} entrée(s).`,
	);
}

async function main() {
	console.log(`[contenu-live] Source : ${SITE}`);
	for (const cible of CIBLES) {
		await synchronise(cible);
	}
}

main().catch((error) => {
	console.warn(`[contenu-live] Erreur inattendue, le build continue : ${error.message}`);
});
