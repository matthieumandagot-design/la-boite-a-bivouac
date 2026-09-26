/**
 * Tests du calcul de prix (docs/SPEC-module-commercial.md §7).
 *
 * Les règles de prix vivent dans un seul fichier, public/data/pricing.json, lu
 * au build par le TypeScript et à l'exécution par le PHP. C'est le PHP qui
 * chiffre — le navigateur n'additionne jamais rien — donc c'est lui qu'on teste.
 *
 * Le catalogue d'options est vide tant que la question 6 n'est pas tranchée.
 * Pour éprouver quand même cette mécanique, le test travaille sur une COPIE de
 * public/lib et public/data dans un dossier temporaire, où il ajoute des
 * options fictives. Le dépôt n'est jamais modifié.
 *
 *   node scripts/test-prix.mjs
 *
 * Demande php dans le PATH avec pdo_sqlite ; sans lui, le test s'abstient
 * plutôt que d'échouer.
 */

import { execFileSync } from "node:child_process";
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RACINE = path.join(__dirname, "..");

const bac = mkdtempSync(path.join(tmpdir(), "bab-prix-"));
let echecs = 0;
let total = 0;

function verifie(quoi, attendu, obtenu) {
	total++;
	const ok = JSON.stringify(attendu) === JSON.stringify(obtenu);
	if (!ok) echecs++;
	console.log(
		`  ${ok ? "OK   " : "ECHEC"} ${quoi}` +
			(ok ? "" : `\n        attendu ${JSON.stringify(attendu)}\n        obtenu  ${JSON.stringify(obtenu)}`),
	);
}

try {
	cpSync(path.join(RACINE, "public", "lib"), path.join(bac, "lib"), { recursive: true });
	cpSync(path.join(RACINE, "public", "data"), path.join(bac, "data"), { recursive: true });

	const regles = JSON.parse(readFileSync(path.join(bac, "data", "pricing.json"), "utf-8"));

	// Deux options valides, et trois entrées volontairement mal formées : une
	// option sans identifiant, une au prix négatif, une au prix en chaîne. Aucune
	// des trois ne doit apparaître ni être facturée.
	regles.options.disponibles = [
		{ id: "galerie", libelle: "Galerie de toit", description: "Barres renforcées", prix_cents: 15000 },
		{ id: "serrure", libelle: "Serrure à clé", prix_cents: 4500 },
		{ libelle: "Sans identifiant", prix_cents: 1000 },
		{ id: "negatif", libelle: "Prix négatif", prix_cents: -500 },
		{ id: "chaine", libelle: "Prix en chaîne", prix_cents: "2000" },
	];
	writeFileSync(path.join(bac, "data", "pricing.json"), JSON.stringify(regles, null, "\t"));

	const PHP = `
require '${path.join(bac, "lib", "panier.php").replace(/\\/g, "/")}';
$sortie = [];
$dims = rqCalculeBoite(155, 115, 90.0, 40);

$sortie['longueur_boite'] = $dims['longueur'];
$sortie['base'] = prixBoiteCents($dims)['cents'];
// Modèle du 27/09/2026 : 1 000 € fixes + variable de 200 € (100 cm) à
// 1 500 € (300 cm), prolongée de part et d'autre, jamais négative.
$sortie['variable'] = array_map('prixVariableLongueurCents', [100, 200, 300, 350, 80, 50]);

$sortie['options_catalogue'] = array_keys(pricingOptions());
$sortie['une_option'] = prixBoiteCents($dims, prixOptionsCents(['galerie'])['cents'])['cents'];
$sortie['deux_options'] = prixBoiteCents($dims, prixOptionsCents(['galerie', 'serrure'])['cents'])['cents'];
$sortie['doublon'] = prixOptionsCents(['galerie', 'galerie'])['cents'];
$sortie['inconnue'] = prixOptionsCents(['galerie', 'inexistante'])['inconnues'];
$sortie['mal_formees'] = prixOptionsCents(['negatif', 'chaine'])['inconnues'];
$sortie['mention'] = pricingMention();
$sortie['provisoire'] = pricingProvisoire();

// Le panier complet : c'est lui qui refuse une option inconnue.
$avecOption = panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163', 'options' => ['galerie']]]);
$sortie['panier_total'] = $avecOption['total_cents'];
$sortie['panier_detail'] = $avecOption['lignes'][0]['detail'] ?? '';
$sortie['panier_config_options'] = array_column($avecOption['lignes'][0]['configuration']['options'] ?? [], 'id');

$refus = panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163', 'options' => ['inexistante']]]);
$sortie['option_inconnue_refusee'] = count($refus['lignes']) === 0 && count($refus['refusees']) === 1;

echo json_encode($sortie);
`;

	let brut;
	try {
		brut = execFileSync("php", ["-r", PHP], { encoding: "utf-8" });
	} catch (erreur) {
		if (erreur.code === "ENOENT") {
			console.log("[test-prix] php introuvable dans le PATH : test ignoré.");
			process.exit(0);
		}
		console.error("[test-prix] PHP a échoué :", erreur.stderr || erreur.message);
		process.exit(1);
	}

	const r = JSON.parse(brut);

	console.log("### catalogue d'options");
	verifie("seules les entrées bien formées sont retenues", ["galerie", "serrure"], r.options_catalogue);
	verifie("prix négatif et prix en chaîne traités comme inconnus", ["negatif", "chaine"], r.mal_formees);

	console.log("\n### prix selon la longueur");
	verifie("variable : 200 € à 100 cm, 850 € à 200 cm, 1 500 € à 300 cm", [20000, 85000, 150000], r.variable.slice(0, 3));
	verifie("variable prolongée au-delà de 300 cm", 182500, r.variable[3]);
	verifie("variable prolongée en dessous de 100 cm", 7000, r.variable[4]);
	verifie("variable jamais négative", 0, r.variable[5]);
	const attendu = 100000 + Math.round(20000 + (r.longueur_boite - 100) * 650);
	verifie(`boîte de ${r.longueur_boite} cm : fixe + variable`, attendu, r.base);

	console.log("\n### addition");
	verifie("une option ajoutée", attendu + 15000, r.une_option);
	verifie("deux options ajoutées", attendu + 19500, r.deux_options);
	verifie("même option deux fois comptée une seule", 15000, r.doublon);
	verifie("option inconnue signalée", ["inexistante"], r.inconnue);

	console.log("\n### panier");
	verifie("total du panier avec option (Erdé Classic 163, même caisse)", attendu + 15000, r.panier_total);
	verifie("option nommée dans le détail", true, String(r.panier_detail).includes("Galerie de toit"));
	verifie("option conservée dans la configuration", ["galerie"], r.panier_config_options);
	verifie("option inconnue fait refuser la ligne", true, r.option_inconnue_refusee);

	console.log("\n### montants provisoires");
	verifie("mention affichable", "À DÉFINIR", r.mention);
	verifie("drapeau provisoire", true, r.provisoire);

	// Bornes : un plafond bas doit faire refuser la configuration plutôt que
	// facturer un montant que personne n'a décidé.
	regles.boite.total_max_cents = 1000;
	writeFileSync(path.join(bac, "data", "pricing.json"), JSON.stringify(regles, null, "\t"));

	const PHP_BORNES = `
require '${path.join(bac, "lib", "panier.php").replace(/\\/g, "/")}';
$p = panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163']]);
echo json_encode(['lignes' => count($p['lignes']), 'motif' => $p['refusees'][0]['motif'] ?? '']);
`;
	const bornes = JSON.parse(execFileSync("php", ["-r", PHP_BORNES], { encoding: "utf-8" }));

	console.log("\n### garde-fous");
	verifie("total hors bornes : aucune ligne retenue", 0, bornes.lignes);
	verifie("motif explicite", "Prix indisponible pour cette configuration.", bornes.motif);
} finally {
	rmSync(bac, { recursive: true, force: true });
}

console.log(`\n[test-prix] ${total} assertions, ${echecs} echec(s).`);
process.exit(echecs === 0 ? 0 : 1);
