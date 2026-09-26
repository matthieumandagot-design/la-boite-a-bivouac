<?php
declare(strict_types=1);

/**
 * Règles de prix, côté serveur (docs/SPEC-module-commercial.md §2 et §6.3).
 *
 * Un seul fichier de règles, data/pricing.json, lu ici par le PHP et au build
 * par le TypeScript : aucune règle de prix n'est écrite en dur deux fois.
 *
 * Tant que « provisoire » vaut true, les montants sont factices — le modèle de
 * prix de la Boîte n'est pas arrêté (question 1 de la spec). Toute interface qui
 * affiche un montant doit alors montrer pricingMention(), c'est-à-dire
 * « À DÉFINIR ». Ce n'est pas une décoration : afficher un prix inventé sans le
 * dire, c'est laisser croire à un engagement.
 *
 * Les montants sont des ENTIERS DE CENTIMES, jamais des flottants : 0.1 + 0.2
 * ne fait pas 0.3 en binaire, et une facture ne se discute pas.
 */

const PRICING_FICHIER = __DIR__ . '/../data/pricing.json';

/**
 * Repli si le fichier manque ou devient illisible. Volontairement pauvre : il
 * ne sert qu'à ce que le panier refuse proprement plutôt qu'à facturer sur des
 * valeurs devinées — les bornes min et max identiques rendent tout calcul
 * impossible à valider, donc tout est refusé.
 */
function pricingFallback(): array
{
	return [
		'provisoire' => true,
		'devise' => 'EUR',
		'mention_provisoire' => 'À DÉFINIR',
		'boite' => [
			'prix_base_cents' => 0,
			'supplement_par_litre_cents' => 0,
			'supplement_par_cm_hauteur_cents' => 0,
			'total_min_cents' => 0,
			'total_max_cents' => 0,
		],
		'livraison' => ['forfait_cents' => 0, 'retrait_atelier_possible' => true],
	];
}

/** Charge les règles une seule fois par requête. */
function pricing(): array
{
	static $regles = null;
	if ($regles !== null) {
		return $regles;
	}

	$brut = is_file(PRICING_FICHIER) ? json_decode((string) file_get_contents(PRICING_FICHIER), true) : null;
	$regles = is_array($brut) ? $brut + pricingFallback() : pricingFallback();

	return $regles;
}

/** Les montants sont-ils encore factices ? */
function pricingProvisoire(): bool
{
	return (pricing()['provisoire'] ?? true) === true;
}

/** Mention à coller à côté de tout montant tant qu'il est provisoire. */
function pricingMention(): string
{
	$mention = pricing()['mention_provisoire'] ?? 'À DÉFINIR';

	return is_string($mention) && $mention !== '' ? $mention : 'À DÉFINIR';
}

/** Entier de centimes lu dans la configuration, 0 si la valeur est douteuse. */
function pricingEntier(array $source, string $cle): int
{
	$valeur = $source[$cle] ?? 0;

	return is_int($valeur) && $valeur >= 0 ? $valeur : 0;
}

/**
 * Partie variable du prix selon la longueur de la Boîte (modèle du 27/09/2026).
 *
 * Droite passant par les deux points de data/pricing.json (200 € à 100 cm,
 * 1 500 € à 300 cm), prolongée au-delà dans les deux sens comme demandé. Jamais
 * négative : très en dessous de 100 cm, la droite passerait sous zéro, et une
 * Boîte ne coûte pas moins que sa partie fixe.
 *
 * Calcul en entiers de centimes : 1 300 € sur 200 cm font 650 centimes par cm,
 * sans arrondi. Un point mal renseigné annule la partie variable plutôt que de
 * facturer un montant deviné.
 */
function prixVariableLongueurCents(int $longueurCm): int
{
	$regle = pricing()['boite']['variable_longueur'] ?? null;
	$de = is_array($regle) ? ($regle['de'] ?? null) : null;
	$a = is_array($regle) ? ($regle['a'] ?? null) : null;

	if (!is_array($de) || !is_array($a)) {
		return 0;
	}

	$l1 = $de['longueur_cm'] ?? null;
	$p1 = $de['cents'] ?? null;
	$l2 = $a['longueur_cm'] ?? null;
	$p2 = $a['cents'] ?? null;

	if (!is_int($l1) || !is_int($p1) || !is_int($l2) || !is_int($p2) || $l1 === $l2) {
		return 0;
	}

	$variable = $p1 + ($longueurCm - $l1) * ($p2 - $p1) / ($l2 - $l1);

	return max(0, (int) round($variable));
}

/**
 * Prix d'une Boîte à partir de ses dimensions calculées.
 *
 * @param array{longueur: int, largeur: int, hauteur: int, volume: int} $dimensions
 * @return array{cents: int, hors_bornes: bool}
 *
 * Les bornes ne sont pas cosmétiques : un total qui en sort signale une erreur
 * de configuration, pas une commande légitime. L'appelant doit refuser la ligne
 * plutôt que facturer un montant que personne n'a décidé.
 */
function prixBoiteCents(array $dimensions, int $optionsCents = 0): array
{
	$boite = pricing()['boite'] ?? [];

	$total = pricingEntier($boite, 'prix_base_cents')
		+ prixVariableLongueurCents((int) $dimensions['longueur'])
		+ (int) round($dimensions['volume'] * pricingEntier($boite, 'supplement_par_litre_cents'))
		+ (int) round($dimensions['hauteur'] * pricingEntier($boite, 'supplement_par_cm_hauteur_cents'))
		// Les options entrent AVANT le contrôle des bornes : elles font partie du
		// prix de la boîte configurée, et un cumul aberrant doit être attrapé.
		+ max(0, $optionsCents);

	$min = pricingEntier($boite, 'total_min_cents');
	$max = pricingEntier($boite, 'total_max_cents');
	$horsBornes = $max <= 0 || $total < $min || $total > $max;

	return ['cents' => $total, 'hors_bornes' => $horsBornes];
}

/**
 * Options du configurateur, indexées par identifiant (§7, question 6).
 *
 * La liste vit dans data/pricing.json et peut être vide — elle l'est tant que
 * les options ne sont pas arrêtées. Une entrée mal formée est écartée plutôt
 * que corrigée : mieux vaut une option qui n'apparaît pas qu'une option
 * facturée à un prix deviné.
 *
 * @return array<string, array{id: string, libelle: string, description: string, prix_cents: int}>
 */
function pricingOptions(): array
{
	$brutes = pricing()['options']['disponibles'] ?? [];
	if (!is_array($brutes)) {
		return [];
	}

	$options = [];
	foreach ($brutes as $option) {
		if (!is_array($option)) {
			continue;
		}
		$id = $option['id'] ?? null;
		$libelle = $option['libelle'] ?? null;
		$prix = $option['prix_cents'] ?? null;

		if (!is_string($id) || $id === '' || !is_string($libelle) || $libelle === '' || !is_int($prix) || $prix < 0) {
			continue;
		}

		$options[$id] = [
			'id' => $id,
			'libelle' => $libelle,
			'description' => is_string($option['description'] ?? null) ? $option['description'] : '',
			'prix_cents' => $prix,
		];
	}

	return $options;
}

/**
 * Additionne des options choisies.
 *
 * Un identifiant inconnu n'est pas ignoré en silence : il ressort dans
 * « inconnues », et l'appelant refuse la ligne. Une option retirée du catalogue
 * entre l'affichage et la commande doit se voir, pas disparaître du total.
 *
 * @param mixed $ids ce que le navigateur a transmis, sans confiance
 * @return array{cents: int, retenues: array<int, array{id: string, libelle: string, prix_cents: int}>, inconnues: string[]}
 */
function prixOptionsCents($ids): array
{
	$disponibles = pricingOptions();
	$retenues = [];
	$inconnues = [];
	$total = 0;
	$vues = [];

	foreach (is_array($ids) ? $ids : [] as $id) {
		if (!is_string($id) || $id === '' || isset($vues[$id])) {
			// Doublon : une même option ne se facture pas deux fois.
			continue;
		}
		$vues[$id] = true;

		if (!isset($disponibles[$id])) {
			$inconnues[] = $id;
			continue;
		}

		$retenues[] = [
			'id' => $id,
			'libelle' => $disponibles[$id]['libelle'],
			'prix_cents' => $disponibles[$id]['prix_cents'],
		];
		$total += $disponibles[$id]['prix_cents'];
	}

	return ['cents' => $total, 'retenues' => $retenues, 'inconnues' => $inconnues];
}

/** Forfait de livraison, en centimes. */
function prixLivraisonCents(): int
{
	return pricingEntier(pricing()['livraison'] ?? [], 'forfait_cents');
}

/** Formate un montant pour l'affichage : 200000 → « 2 000,00 € ». */
function formaterCents(int $cents): string
{
	// Espace insécable avant l'euro et comme séparateur de milliers, comme le
	// veut la typographie française.
	return number_format($cents / 100, 2, ',', "\u{202f}") . "\u{a0}€";
}
