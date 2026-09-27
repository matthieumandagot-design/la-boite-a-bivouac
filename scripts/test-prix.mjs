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

$sortie['hauteur_boite'] = $dims['hauteur'];
$sortie['base'] = prixBoiteCents($dims, 155)['cents'];
// Formule A (27/09/2026) : 1 200 € fixes + 200 € + 2,80 €/cm de remorque
// au-delà de 100 cm + 3,20 €/cm de hauteur au-delà de 35 cm, chaque partie
// plancher à zéro. [longueur de remorque, hauteur de boîte] => variable.
$sortie['variable'] = array_map(
	static fn (array $c): int => prixVariableCents($c[0], $c[1]),
	[[100, 35], [200, 35], [100, 135], [400, 300], [80, 30], [120, 90]]
);
$sortie['options_catalogue'] = array_keys(pricingOptions());
$sortie['une_option'] = prixBoiteCents($dims, 155, prixOptionsCents(['galerie'])['cents'])['cents'];
$sortie['deux_options'] = prixBoiteCents($dims, 155, prixOptionsCents(['galerie', 'serrure'])['cents'])['cents'];
$sortie['doublon'] = prixOptionsCents(['galerie', 'galerie'])['cents'];
$sortie['inconnue'] = prixOptionsCents(['galerie', 'inexistante'])['inconnues'];
$sortie['mal_formees'] = prixOptionsCents(['negatif', 'chaine'])['inconnues'];
$sortie['mention'] = pricingMention();
$sortie['provisoire'] = pricingProvisoire();
$sortie['mention_prix'] = pricingMentionPrix();
$sortie['panier_mention_prix'] = panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163']])['mention_prix'] ?? null;

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

	console.log("\n### formule A : longueur de remorque et hauteur de boîte");
	verifie("minimum : 200 € à 100 cm × 35 cm", 20000, r.variable[0]);
	verifie("+ 2,80 € par cm de remorque (100 cm de plus : 480 €)", 48000, r.variable[1]);
	verifie("+ 3,20 € par cm de hauteur (100 cm de plus : 520 €)", 52000, r.variable[2]);
	verifie("maximum : 4 m × 3 m → 1 888 €", 188800, r.variable[3]);
	verifie("sous les seuils : jamais moins que la base", 20000, r.variable[4]);
	verifie("Erdé Classic 122, 90 cm : 432 €", 43200, r.variable[5]);
	const attendu = 120000 + 20000 + (155 - 100) * 280 + (r.hauteur_boite - 35) * 320;
	verifie(`remorque de 155 cm, boîte de ${r.hauteur_boite} cm : fixe + variable`, attendu, r.base);

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

	console.log("\n### mentions de prix");
	verifie("montants définitifs depuis le 27/09/2026", false, r.provisoire);
	verifie("mention d'avertissement toujours disponible si besoin", "À DÉFINIR", r.mention);
	verifie("mention de prix", "Hors frais de livraison", r.mention_prix);
	verifie("mention de prix transmise par le panier", "Hors frais de livraison", r.panier_mention_prix);

	// Livraison (27/09/2026) : modes, garde-fous d'adresse, total, et ligne
	// ajoutée à la commande. La base est créée dans le bac, jamais ailleurs.
	const PHP_LIVRAISON = `
require '${path.join(bac, "lib", "commandes.php").replace(/\\/g, "/")}';
require '${path.join(bac, "lib", "clients.php").replace(/\\/g, "/")}';
bdMigrer();
$s = [];
$s['modes'] = array_map(fn ($m) => [$m['id'], $m['prix_cents']], livraisonModesPublics());
$toulouse = livraisonMode('livraison-toulouse');
$france = livraisonMode('livraison-france');
$s['toulouse_31'] = livraisonRefus($toulouse, ['pays' => 'FR', 'code_postal' => '31000']);
$s['toulouse_59'] = livraisonRefus($toulouse, ['pays' => 'FR', 'code_postal' => '59000']) !== null;
$s['france_corse'] = livraisonRefus($france, ['pays' => 'FR', 'code_postal' => '20000']);
$s['france_reunion'] = livraisonRefus($france, ['pays' => 'FR', 'code_postal' => '97400']) !== null;
$s['france_belgique'] = livraisonRefus($france, ['pays' => 'BE', 'code_postal' => '1000']) !== null;
$sans = panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163']]);
$avec = panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163']], 'livraison-france');
$inconnu = panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163']], 'teleportation');
$s['sans'] = [$sans['livraison'], $sans['livraison_cents'], $sans['total_cents']];
$s['avec'] = [$avec['livraison']['id'] ?? null, $avec['livraison_cents'], $avec['total_cents'] - $avec['sous_total_cents']];
$s['inconnu'] = [$inconnu['livraison'], $inconnu['livraison_cents']];
$client = creerClient('test@exemple.fr', 'motdepasse123', 'A', 'B');
$adresse = ['ligne1' => '1 rue', 'code_postal' => '31000', 'ville' => 'Toulouse', 'pays' => 'FR'];
$cmd = commandeCreer($client, $avec, $adresse);
$lignes = commandeLignes($cmd['id']);
$s['commande_lignes'] = array_map(fn ($l) => [$l['libelle'], (int) $l['total_cents']], $lignes);
$s['commande_total'] = [(int) commandeParId($cmd['id'])['total_cents'], $avec['total_cents']];
$retrait = commandeCreer($client, panierRecalcule([['type' => 'boite', 'remorque' => 'Erdé|Classic 163']], 'retrait-toulouse'), $adresse);
$s['retrait_ligne'] = array_map(fn ($l) => (int) $l['total_cents'], commandeLignes($retrait['id']));
echo json_encode($s, JSON_UNESCAPED_UNICODE);
`;
	const l = JSON.parse(execFileSync("php", ["-r", PHP_LIVRAISON], { encoding: "utf-8" }));

	console.log("\n### livraison");
	verifie("trois modes : 0 €, 200 €, 400 €", [["retrait-toulouse", 0], ["livraison-toulouse", 20000], ["livraison-france", 40000]], l.modes);
	verifie("autour de Toulouse : adresse en Haute-Garonne acceptée", null, l.toulouse_31);
	verifie("autour de Toulouse : adresse à Lille refusée", true, l.toulouse_59);
	verifie("France : Corse acceptée", null, l.france_corse);
	verifie("France : outre-mer refusé", true, l.france_reunion);
	verifie("France : autre pays refusé", true, l.france_belgique);
	verifie("sans choix de livraison : rien compté", [null, 0, l.sans[2]], l.sans);
	verifie("livraison en France : 400 € ajoutés au total", ["livraison-france", 40000, 40000], l.avec);
	verifie("mode inconnu : ignoré", [null, 0], l.inconnu);
	verifie("commande : la livraison est une ligne à part", ["Livraison — Livraison en France métropolitaine", 40000], l.commande_lignes[1]);
	verifie("commande : total enregistré = total recalculé", l.commande_total[1], l.commande_total[0]);
	verifie("retrait : ligne à 0 € conservée dans la commande", 0, l.retrait_ligne[1]);

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
