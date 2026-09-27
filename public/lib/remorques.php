<?php
declare(strict_types=1);

/**
 * Règle de dimensionnement de la Boîte, côté serveur
 * (docs/SPEC-module-commercial.md §6.3).
 *
 * Portage à l'identique de src/data/remorques.ts. Le serveur ne fait confiance
 * à aucune dimension venue du navigateur : il recalcule tout à partir de la
 * remorque choisie ou des cotes saisies, exactement comme la page
 * /configurateur l'a affiché au visiteur.
 *
 * Les DONNÉES (les 28 remorques et les constantes) ne sont pas recopiées ici :
 * elles sont générées depuis le TypeScript dans remorques-data.php. Seule la
 * règle est écrite à la main — et scripts/test-remorques-php.mjs vérifie qu'elle
 * donne le même résultat que le TypeScript sur les 28 remorques.
 *
 * Note d'arrondi : round() de PHP et Math.round() de JavaScript ne s'accordent
 * que sur les nombres positifs (JavaScript arrondit -0,5 vers zéro, PHP s'en
 * éloigne). Toutes les grandeurs manipulées ici sont des centimètres positifs,
 * le cas ne se présente pas ; ne pas réutiliser ces fonctions ailleurs sans y
 * repenser.
 */

require_once __DIR__ . '/remorques-data.php';

/** Une remorque du catalogue, ou null si la clé est inconnue. */
function remorqueParCle(string $cle): ?array
{
	return remorquesCatalogue()[$cle] ?? null;
}

/**
 * Hauteur minimale de la boîte : celle des ridelles. En dessous, la boîte
 * s'emboîterait entièrement dans la caisse et ne dépasserait plus.
 */
function rqHauteurMini(?int $ridelle): int
{
	return (int) round($ridelle ?? RQ_RIDELLE_DEFAUT_CM);
}

/**
 * Hauteur maximale de la boîte : jamais plus haute que la caisse n'est longue,
 * et jamais plus de RQ_HAUTEUR_BOITE_MAX_CM (3 m). Les 2 m hors tout ne bornent
 * rien, ils ne servent qu'à situer l'ensemble dans les classes de péage.
 */
function rqHauteurMaxi(int $longueurInterieure): int
{
	return max(1, min((int) round($longueurInterieure), RQ_HAUTEUR_BOITE_MAX_CM));
}

/**
 * Hauteur hors tout de l'ensemble, du sol au point le plus haut : plancher de
 * la remorque, boîte, barres de toit, puis tente repliée si elle est montée.
 */
function rqHauteurHorsTout(int $hauteurBoite, ?int $plancher, int $tente = 0): int
{
	return (int) round($hauteurBoite + ($plancher ?? RQ_PLANCHER_DEFAUT_CM) + RQ_BARRES_TOIT_CM + $tente);
}

/** Ramène une hauteur saisie dans les bornes fabricables pour cette remorque. */
function rqBorneHauteur(float $valeur, ?int $ridelle, int $longueurInterieure): int
{
	$mini = rqHauteurMini($ridelle);
	$maxi = rqHauteurMaxi($longueurInterieure);

	// Bornes croisées : la remorque ne peut pas recevoir de boîte. Le plafond
	// l'emporte, un dépassement de gabarit étant la faute la plus grave.
	if ($maxi < $mini) {
		return $maxi;
	}

	if (!is_finite($valeur)) {
		return min($maxi, max($mini, RQ_HAUTEUR_DEFAUT_CM));
	}

	return min($maxi, max($mini, (int) round($valeur)));
}

/**
 * Applique la règle de dimensionnement.
 *
 * @return array{longueur: int, largeur: int, hauteur: int, volume: int, horsGabarit: string[]}
 */
function rqCalculeBoite(
	int $longueurInterieure,
	int $largeurInterieure,
	float $hauteurSouhaitee = RQ_HAUTEUR_DEFAUT_CM,
	?int $ridelle = null
): array {
	// Le jeu est laissé des deux côtés : il se retire donc deux fois par cote.
	$longueur = $longueurInterieure - 2 * RQ_JEU_PAR_COTE_CM;
	$largeur = $largeurInterieure - 2 * RQ_JEU_PAR_COTE_CM;
	$hauteur = rqBorneHauteur($hauteurSouhaitee, $ridelle, $longueurInterieure);

	$horsGabarit = [];
	if (rqHauteurMaxi($longueurInterieure) < rqHauteurMini($ridelle)) {
		$horsGabarit[] = 'ridelles plus hautes que la boîte permise';
	}
	if ($longueur < RQ_LIMITES['longueurMin']) {
		$horsGabarit[] = 'longueur trop courte';
	}
	if ($longueur > RQ_LIMITES['longueurMax']) {
		$horsGabarit[] = 'remorque de plus de ' . (RQ_LONGUEUR_REMORQUE_MAX_CM / 100) . ' m de long';
	}
	if ($largeur < RQ_LIMITES['largeurMin']) {
		$horsGabarit[] = 'largeur trop étroite';
	}
	if ($largeur > RQ_LIMITES['largeurMax']) {
		$horsGabarit[] = 'largeur trop grande';
	}

	return [
		'longueur' => $longueur,
		'largeur' => $largeur,
		'hauteur' => $hauteur,
		// 1 litre = 1 dm³ = 1000 cm³.
		'volume' => (int) round($longueur * $largeur * $hauteur / 1000),
		'horsGabarit' => $horsGabarit,
	];
}
