/**
 * Recopie les données de src/data/remorques.ts dans public/lib/remorques-data.php.
 *
 * POURQUOI GÉNÉRER PLUTÔT QUE RECOPIER
 * Le serveur doit recalculer les dimensions de la boîte sans faire confiance au
 * navigateur (SPEC §2), donc la règle et les cotes existent en TypeScript ET en
 * PHP. Deux listes de 28 remorques tenues à la main finiraient par diverger, et
 * la divergence serait invisible : le site afficherait une cote et facturerait
 * l'autre. Ici la source reste unique — le fichier PHP n'est qu'une copie
 * mécanique, régénérée à chaque build (voir .github/workflows/deploy.yml).
 *
 * Seules les DONNÉES sont générées. La règle de calcul, elle, est écrite à la
 * main dans public/lib/remorques.php : la traduire automatiquement serait
 * fragile. C'est scripts/test-remorques-php.mjs qui vérifie que les deux
 * implémentations donnent le même résultat sur les 28 remorques.
 */

import { writeFile, readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SORTIE = path.join(__dirname, "..", "public", "lib", "remorques-data.php");

const {
	REMORQUES,
	HAUTEUR_DEFAUT_CM,
	RIDELLE_DEFAUT_CM,
	HAUTEUR_TOTALE_MAX_CM,
	PLANCHER_DEFAUT_CM,
	BARRES_TOIT_CM,
	TENTE_DEFAUT_CM,
	JEU_PAR_COTE_CM,
	LIMITES,
} = await import("../src/data/remorques.ts");

/** Clé d'une remorque : la même que celle du sélecteur de /compatibilite. */
const cle = (r) => `${r.marque}|${r.modele}`;

function phpChaine(valeur) {
	return "'" + String(valeur).replace(/\\/g, "\\\\").replace(/'/g, "\\'") + "'";
}

function phpValeur(valeur) {
	if (valeur === null || valeur === undefined) return "null";
	if (typeof valeur === "number") return String(valeur);
	if (typeof valeur === "boolean") return valeur ? "true" : "false";
	return phpChaine(valeur);
}

const lignes = REMORQUES.map((r) => {
	const champs = ["marque", "modele", "longueur", "largeur", "ridelle", "plancher", "ptac", "chargeUtile", "verifie"]
		.map((c) => `'${c}' => ${phpValeur(r[c])}`)
		.join(", ");
	return `\t\t${phpChaine(cle(r))} => [${champs}],`;
}).join("\n");

const contenu = `<?php
declare(strict_types=1);

/**
 * FICHIER GÉNÉRÉ — NE PAS MODIFIER À LA MAIN.
 *
 * Source      : src/data/remorques.ts
 * Générateur  : scripts/generer-remorques-php.mjs
 * Régénéré    : à chaque build (voir .github/workflows/deploy.yml)
 *
 * Toute retouche ici serait perdue au prochain déploiement, et surtout ferait
 * diverger le calcul du serveur de celui affiché au visiteur. Modifier
 * src/data/remorques.ts, puis relancer le générateur.
 *
 * La clé de chaque remorque est « marque|modele », exactement la valeur du
 * sélecteur de /compatibilite : c'est elle que le panier transmet.
 */

const RQ_HAUTEUR_DEFAUT_CM = ${HAUTEUR_DEFAUT_CM};
const RQ_RIDELLE_DEFAUT_CM = ${RIDELLE_DEFAUT_CM};
const RQ_HAUTEUR_TOTALE_MAX_CM = ${HAUTEUR_TOTALE_MAX_CM};
const RQ_PLANCHER_DEFAUT_CM = ${PLANCHER_DEFAUT_CM};
const RQ_BARRES_TOIT_CM = ${BARRES_TOIT_CM};
const RQ_TENTE_DEFAUT_CM = ${TENTE_DEFAUT_CM};
const RQ_JEU_PAR_COTE_CM = ${JEU_PAR_COTE_CM};

const RQ_LIMITES = [
	'longueurMin' => ${LIMITES.longueurMin},
	'largeurMin' => ${LIMITES.largeurMin},
	'longueurMax' => ${LIMITES.longueurMax},
	'largeurMax' => ${LIMITES.largeurMax},
];

/**
 * Les 28 remorques du catalogue, indexées par « marque|modele ».
 *
 * @return array<string, array{marque: string, modele: string, longueur: int, largeur: int, ridelle: ?int, plancher: ?int, ptac: ?int, chargeUtile: ?int, verifie: bool}>
 */
function remorquesCatalogue(): array
{
	return [
${lignes}
	];
}
`;

const ancien = await readFile(SORTIE, "utf-8").catch(() => null);
if (ancien === contenu) {
	console.log(`[generer-remorques-php] ${path.relative(process.cwd(), SORTIE)} déjà à jour (${REMORQUES.length} remorques).`);
} else {
	await writeFile(SORTIE, contenu, "utf-8");
	console.log(`[generer-remorques-php] ${path.relative(process.cwd(), SORTIE)} écrit (${REMORQUES.length} remorques).`);
}
