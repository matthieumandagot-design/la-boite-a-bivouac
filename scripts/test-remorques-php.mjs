/**
 * Compare la règle de dimensionnement PHP à celle du TypeScript, sur les 28
 * remorques (docs/SPEC-module-commercial.md §6.3).
 *
 * Le serveur recalcule les dimensions sans faire confiance au navigateur. Deux
 * implémentations coexistent donc, et si elles divergent, le site affiche une
 * cote et en facture une autre — sans que rien ne plante. Ce test est la seule
 * chose qui rende cette divergence visible.
 *
 * Chaque remorque est passée à plusieurs hauteurs, dont les cas limites :
 * en dessous des ridelles, au-dessus de la longueur de caisse, et zéro.
 *
 *   node scripts/test-remorques-php.mjs
 *
 * Sort en code 1 au premier écart. Demande php dans le PATH ; sans lui, le test
 * s'abstient plutôt que d'échouer — la CI de déploiement n'a pas PHP.
 */

import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RACINE = path.join(__dirname, "..");

const { REMORQUES, HAUTEUR_DEFAUT_CM, calculeBoite } = await import("../src/data/remorques.ts");

/** Hauteurs testées pour chaque remorque, y compris hors bornes. */
function hauteursTestees(remorque) {
	const ridelle = remorque.ridelle ?? 35;
	return [HAUTEUR_DEFAUT_CM, ridelle, ridelle - 10, 0, 500, remorque.longueur, remorque.longueur + 50];
}

const cas = [];
for (const r of REMORQUES) {
	for (const hauteur of hauteursTestees(r)) {
		cas.push({ longueur: r.longueur, largeur: r.largeur, hauteur, ridelle: r.ridelle });
	}
}

// Quelques cotes saisies à la main, hors catalogue : le panier les accepte aussi.
for (const [longueur, largeur, hauteur, ridelle] of [
	[100, 90, 90, null],
	[264, 164, 90, null],
	[101, 91, 90, 200],
	[262, 162, 300, 30],
]) {
	cas.push({ longueur, largeur, hauteur, ridelle });
}

const PHP = `
require '${path.join(RACINE, "public", "lib", "remorques.php").replace(/\\/g, "/")}';
$cas = json_decode(stream_get_contents(STDIN), true);
$sortie = [];
foreach ($cas as $c) {
	$sortie[] = rqCalculeBoite($c['longueur'], $c['largeur'], (float) $c['hauteur'], $c['ridelle']);
}
echo json_encode($sortie);
`;

let brut;
try {
	brut = execFileSync("php", ["-r", PHP], { input: JSON.stringify(cas), encoding: "utf-8" });
} catch (erreur) {
	if (erreur.code === "ENOENT") {
		console.log("[test-remorques-php] php introuvable dans le PATH : test ignoré.");
		process.exit(0);
	}
	console.error("[test-remorques-php] PHP a échoué :", erreur.stderr || erreur.message);
	process.exit(1);
}

const cotePhp = JSON.parse(brut);
let echecs = 0;

cas.forEach((c, i) => {
	const ts = calculeBoite(c.longueur, c.largeur, c.hauteur, c.ridelle);
	const php = cotePhp[i];
	const attendu = {
		longueur: ts.longueur,
		largeur: ts.largeur,
		hauteur: ts.hauteur,
		volume: ts.volume,
		horsGabarit: ts.horsGabarit,
	};
	const obtenu = {
		longueur: php.longueur,
		largeur: php.largeur,
		hauteur: php.hauteur,
		volume: php.volume,
		horsGabarit: php.horsGabarit,
	};

	if (JSON.stringify(attendu) !== JSON.stringify(obtenu)) {
		echecs++;
		console.error(
			`ÉCART sur ${c.longueur}×${c.largeur} h=${c.hauteur} ridelle=${c.ridelle}\n` +
				`  TypeScript : ${JSON.stringify(attendu)}\n` +
				`  PHP        : ${JSON.stringify(obtenu)}`,
		);
	}
});

console.log(
	`[test-remorques-php] ${cas.length} cas comparés sur ${REMORQUES.length} remorques : ` +
		(echecs === 0 ? "aucun écart." : `${echecs} écart(s).`),
);
process.exit(echecs === 0 ? 0 : 1);
