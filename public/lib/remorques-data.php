<?php
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

const RQ_HAUTEUR_DEFAUT_CM = 90;
const RQ_RIDELLE_DEFAUT_CM = 35;
const RQ_HAUTEUR_TOTALE_MAX_CM = 200;
const RQ_PLANCHER_DEFAUT_CM = 55;
const RQ_BARRES_TOIT_CM = 6;
const RQ_TENTE_DEFAUT_CM = 35;
const RQ_JEU_PAR_COTE_CM = 1;

const RQ_LIMITES = [
	'longueurMin' => 100,
	'largeurMin' => 90,
	'longueurMax' => 260,
	'largeurMax' => 160,
];

/**
 * Les 28 remorques du catalogue, indexées par « marque|modele ».
 *
 * @return array<string, array{marque: string, modele: string, longueur: int, largeur: int, ridelle: ?int, plancher: ?int, ptac: ?int, chargeUtile: ?int, verifie: bool}>
 */
function remorquesCatalogue(): array
{
	return [
		'Erdé|Classic 122' => ['marque' => 'Erdé', 'modele' => 'Classic 122', 'longueur' => 120, 'largeur' => 92, 'ridelle' => 35, 'plancher' => null, 'ptac' => 400, 'chargeUtile' => 337, 'verifie' => true],
		'Erdé|Classic 153' => ['marque' => 'Erdé', 'modele' => 'Classic 153', 'longueur' => 144, 'largeur' => 98, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 650, 'chargeUtile' => 526, 'verifie' => true],
		'Erdé|Classic 163' => ['marque' => 'Erdé', 'modele' => 'Classic 163', 'longueur' => 155, 'largeur' => 115, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 600, 'verifie' => true],
		'Erdé|Classic 193' => ['marque' => 'Erdé', 'modele' => 'Classic 193', 'longueur' => 185, 'largeur' => 115, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 590, 'verifie' => true],
		'Erdé|Classic 213' => ['marque' => 'Erdé', 'modele' => 'Classic 213', 'longueur' => 205, 'largeur' => 129, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 590, 'verifie' => true],
		'Erdé|Classic 233' => ['marque' => 'Erdé', 'modele' => 'Classic 233', 'longueur' => 225, 'largeur' => 129, 'ridelle' => 40, 'plancher' => 58, 'ptac' => 750, 'chargeUtile' => 529, 'verifie' => true],
		'Erdé|Classic 234x4' => ['marque' => 'Erdé', 'modele' => 'Classic 234x4', 'longueur' => 225, 'largeur' => 129, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 490, 'verifie' => true],
		'Erdé|LC 151' => ['marque' => 'Erdé', 'modele' => 'LC 151', 'longueur' => 150, 'largeur' => 110, 'ridelle' => 45, 'plancher' => 52, 'ptac' => 750, 'chargeUtile' => 630, 'verifie' => true],
		'Erdé|LC 171' => ['marque' => 'Erdé', 'modele' => 'LC 171', 'longueur' => 170, 'largeur' => 124, 'ridelle' => 45, 'plancher' => 52, 'ptac' => 750, 'chargeUtile' => 589, 'verifie' => true],
		'Erdé|LC 251' => ['marque' => 'Erdé', 'modele' => 'LC 251', 'longueur' => 251, 'largeur' => 135, 'ridelle' => 45, 'plancher' => 52, 'ptac' => 750, 'chargeUtile' => 558, 'verifie' => true],
		'Daxara|107' => ['marque' => 'Daxara', 'modele' => '107', 'longueur' => 106, 'largeur' => 85, 'ridelle' => 32, 'plancher' => 52, 'ptac' => 350, 'chargeUtile' => 295, 'verifie' => true],
		'Daxara|127' => ['marque' => 'Daxara', 'modele' => '127', 'longueur' => 120, 'largeur' => 92, 'ridelle' => 35, 'plancher' => 54, 'ptac' => 400, 'chargeUtile' => 335, 'verifie' => true],
		'Daxara|158' => ['marque' => 'Daxara', 'modele' => '158', 'longueur' => 144, 'largeur' => 98, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 650, 'chargeUtile' => 516, 'verifie' => true],
		'Daxara|168' => ['marque' => 'Daxara', 'modele' => '168', 'longueur' => 155, 'largeur' => 115, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 585, 'verifie' => true],
		'Daxara|198' => ['marque' => 'Daxara', 'modele' => '198', 'longueur' => 185, 'largeur' => 115, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 568, 'verifie' => true],
		'Daxara|218' => ['marque' => 'Daxara', 'modele' => '218', 'longueur' => 205, 'largeur' => 129, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 545, 'verifie' => true],
		'Daxara|238' => ['marque' => 'Daxara', 'modele' => '238', 'longueur' => 225, 'largeur' => 129, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 523, 'verifie' => true],
		'Daxara|239x4' => ['marque' => 'Daxara', 'modele' => '239x4', 'longueur' => 225, 'largeur' => 129, 'ridelle' => 40, 'plancher' => 56, 'ptac' => 750, 'chargeUtile' => 475, 'verifie' => true],
		'Brenderup|1150 S' => ['marque' => 'Brenderup', 'modele' => '1150 S', 'longueur' => 144, 'largeur' => 94, 'ridelle' => null, 'plancher' => null, 'ptac' => 500, 'chargeUtile' => 400, 'verifie' => true],
		'Brenderup|1205 S' => ['marque' => 'Brenderup', 'modele' => '1205 S', 'longueur' => 204, 'largeur' => 116, 'ridelle' => 35, 'plancher' => 54.5, 'ptac' => 500, 'chargeUtile' => 370, 'verifie' => true],
		'Anssems|GT 750.211' => ['marque' => 'Anssems', 'modele' => 'GT 750.211', 'longueur' => 211, 'largeur' => 126, 'ridelle' => 30, 'plancher' => null, 'ptac' => 750, 'chargeUtile' => 610, 'verifie' => true],
		'Anssems|GT 750.251' => ['marque' => 'Anssems', 'modele' => 'GT 750.251', 'longueur' => 251, 'largeur' => 126, 'ridelle' => 30, 'plancher' => null, 'ptac' => 750, 'chargeUtile' => 600, 'verifie' => true],
		'Mil Remorques|FIRST 150' => ['marque' => 'Mil Remorques', 'modele' => 'FIRST 150', 'longueur' => 150, 'largeur' => 100, 'ridelle' => 39, 'plancher' => null, 'ptac' => null, 'chargeUtile' => null, 'verifie' => false],
		'Mil Remorques|FIRST 170' => ['marque' => 'Mil Remorques', 'modele' => 'FIRST 170', 'longueur' => 170, 'largeur' => 122, 'ridelle' => 39, 'plancher' => null, 'ptac' => null, 'chargeUtile' => null, 'verifie' => false],
		'Mil Remorques|FIRST 230.2' => ['marque' => 'Mil Remorques', 'modele' => 'FIRST 230.2', 'longueur' => 233, 'largeur' => 132, 'ridelle' => 39, 'plancher' => null, 'ptac' => null, 'chargeUtile' => null, 'verifie' => false],
		'Mil Remorques|LIREM 150' => ['marque' => 'Mil Remorques', 'modele' => 'LIREM 150', 'longueur' => 150, 'largeur' => 100, 'ridelle' => 46, 'plancher' => null, 'ptac' => null, 'chargeUtile' => null, 'verifie' => false],
		'Mil Remorques|LIREM 170' => ['marque' => 'Mil Remorques', 'modele' => 'LIREM 170', 'longueur' => 171, 'largeur' => 122, 'ridelle' => 46, 'plancher' => null, 'ptac' => null, 'chargeUtile' => null, 'verifie' => false],
		'Norauto|Premium 200' => ['marque' => 'Norauto', 'modele' => 'Premium 200', 'longueur' => 194, 'largeur' => 128, 'ridelle' => 40, 'plancher' => null, 'ptac' => 500, 'chargeUtile' => null, 'verifie' => false],
	];
}
