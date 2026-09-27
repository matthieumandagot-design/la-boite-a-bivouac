/**
 * Catalogue de remorques pour la page /configurateur.
 *
 * Les cotes sont les dimensions INTÉRIEURES de la caisse, en centimètres,
 * relevées une par une sur la fiche citée en source. Un même nom de modèle peut
 * couvrir plusieurs millésimes et finitions aux cotes différentes : la page
 * affiche donc toujours la source et invite à mesurer.
 *
 * Erdé et Daxara sont deux marques du même fabricant : plusieurs modèles se
 * répondent d'une gamme à l'autre avec des cotes identiques. Les deux sont
 * listés, un acheteur cherchant le nom inscrit sur sa remorque.
 *
 * Pour ajouter un modèle : une entrée ici suffit, le sélecteur et le tableau se
 * mettent à jour tout seuls. N'ajoutez que des cotes relevées sur une fiche
 * constructeur ou revendeur, jamais une estimation.
 */

/** Hauteur proposée par défaut pour la Boîte à Bivouac. */
export const HAUTEUR_DEFAUT_CM = 90;

/**
 * Hauteur de ridelle retenue quand la fiche ne la donne pas. Sert de repli au
 * calcul de la hauteur minimale et au schéma.
 */
export const RIDELLE_DEFAUT_CM = 35;

/**
 * Hauteur hors tout maximale, du sol au sommet de la boîte : la boîte repose
 * sur le plancher de la remorque, qui compte donc dans le total.
 */
export const HAUTEUR_TOTALE_MAX_CM = 200;

/** Hauteur de plancher retenue quand la fiche ne la donne pas. */
export const PLANCHER_DEFAUT_CM = 55;

/**
 * Épaisseur des barres de toit, intégrées au-dessus de la boîte. Elles sont
 * toujours là, tente ou non, et surélèvent d'autant ce qui se pose dessus.
 */
export const BARRES_TOIT_CM = 6;

/**
 * Hauteur de la tente de toit repliée, posée sur les barres de toit de la
 * boîte. Elle s'ajoute à la hauteur hors tout, donc elle mange le budget des
 * 2 m et compte dans la classe de péage. 0 = sans tente.
 */
export const TENTE_DEFAUT_CM = 35;

/**
 * Jeu laissé de CHAQUE CÔTÉ, en cm. La longueur et la largeur de la boîte
 * valent donc la cote intérieure moins deux fois cette valeur.
 */
export const JEU_PAR_COTE_CM = 1;

/**
 * Longueur intérieure de remorque la plus grande acceptée : 4 m (décision de
 * Matthieu, 27/09/2026). Au-delà, la configuration est hors gabarit.
 */
export const LONGUEUR_REMORQUE_MAX_CM = 400;

/**
 * Hauteur de boîte la plus grande fabriquée : 3 m (décision du 27/09/2026).
 * S'ajoute à l'autre plafond, la longueur intérieure de la caisse : c'est le
 * plus bas des deux qui s'applique.
 */
export const HAUTEUR_BOITE_MAX_CM = 300;

/**
 * Limites de fabrication au sol, sur les cotes de la BOÎTE. La longueur
 * maximale découle de la remorque de 4 m, moins le jeu des deux côtés. Les
 * autres valeurs restent provisoires : à confirmer avec l'atelier.
 */
export const LIMITES = {
	longueurMin: 100,
	largeurMin: 90,
	longueurMax: LONGUEUR_REMORQUE_MAX_CM - 2 * JEU_PAR_COTE_CM,
	largeurMax: 160,
};

export type Remorque = {
	marque: string;
	modele: string;
	/** Longueur intérieure de la caisse, en cm. */
	longueur: number;
	/** Largeur intérieure de la caisse, en cm. */
	largeur: number;
	/** Hauteur des ridelles, en cm. null quand la source ne la donne pas. */
	ridelle: number | null;
	/** Hauteur de chargement : plancher au-dessus du sol, en cm. */
	plancher: number | null;
	/** PTAC le plus élevé proposé, en kg. */
	ptac: number | null;
	/** Charge utile maximale annoncée, en kg. */
	chargeUtile: number | null;
	source: string;
	/**
	 * true : cote relevee sur la fiche elle-meme. false : cote rapportee par un
	 * tiers, la fiche du vendeur etant inaccessible. La page le signale.
	 */
	verifie: boolean;
};

export const REMORQUES: Remorque[] = [
	// ── Erdé, gamme Classic ────────────────────────────────────────────
	{
		marque: "Erdé",
		modele: "Classic 122",
		longueur: 120,
		largeur: 92,
		ridelle: 35,
		plancher: null,
		ptac: 400,
		chargeUtile: 337,
		source: "https://erde.fr/nos-remorques/erde-classic-122/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "Classic 153",
		longueur: 144,
		largeur: 98,
		ridelle: 40,
		plancher: 56,
		ptac: 650,
		chargeUtile: 526,
		source: "https://erde.fr/nos-remorques/erde-classic-153/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "Classic 163",
		longueur: 155,
		largeur: 115,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 600,
		source: "https://erde.fr/nos-remorques/erde-classic-163/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "Classic 193",
		longueur: 185,
		largeur: 115,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 590,
		source: "https://erde.fr/nos-remorques/erde-classic-193/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "Classic 213",
		longueur: 205,
		largeur: 129,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 590,
		source: "https://erde.fr/nos-remorques/erde-classic-213/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "Classic 233",
		longueur: 225,
		largeur: 129,
		ridelle: 40,
		plancher: 58,
		ptac: 750,
		chargeUtile: 529,
		source: "https://erde.fr/nos-remorques/erde-classic-233/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "Classic 234x4",
		longueur: 225,
		largeur: 129,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 490,
		source: "https://erde.fr/nos-remorques/erde-classic-234x4/",
		verifie: true,
	},

	// ── Erdé, gamme LC ─────────────────────────────────────────────────
	{
		marque: "Erdé",
		modele: "LC 151",
		longueur: 150,
		largeur: 110,
		ridelle: 45,
		plancher: 52,
		ptac: 750,
		chargeUtile: 630,
		source: "https://erde.fr/nos-remorques/lc-201/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "LC 171",
		longueur: 170,
		largeur: 124,
		ridelle: 45,
		plancher: 52,
		ptac: 750,
		chargeUtile: 589,
		source: "https://erde.fr/nos-remorques/lc-171/",
		verifie: true,
	},
	{
		marque: "Erdé",
		modele: "LC 251",
		longueur: 251,
		largeur: 135,
		ridelle: 45,
		plancher: 52,
		ptac: 750,
		chargeUtile: 558,
		source: "https://erde.fr/nos-remorques/lc-251/",
		verifie: true,
	},

	// ── Daxara (même fabricant qu'Erdé) ────────────────────────────────
	{
		marque: "Daxara",
		modele: "107",
		longueur: 106,
		largeur: 85,
		ridelle: 32,
		plancher: 52,
		ptac: 350,
		chargeUtile: 295,
		source: "https://erde.fr/nos-remorques/daxara-107/",
		verifie: true,
	},
	{
		marque: "Daxara",
		modele: "127",
		longueur: 120,
		largeur: 92,
		ridelle: 35,
		plancher: 54,
		ptac: 400,
		chargeUtile: 335,
		source: "https://erde.fr/nos-remorques/daxara-127/",
		verifie: true,
	},
	{
		marque: "Daxara",
		modele: "158",
		longueur: 144,
		largeur: 98,
		ridelle: 40,
		plancher: 56,
		ptac: 650,
		chargeUtile: 516,
		source: "https://erde.fr/nos-remorques/daxara-158/",
		verifie: true,
	},
	{
		marque: "Daxara",
		modele: "168",
		longueur: 155,
		largeur: 115,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 585,
		source: "https://erde.fr/nos-remorques/daxara-168/",
		verifie: true,
	},
	{
		marque: "Daxara",
		modele: "198",
		longueur: 185,
		largeur: 115,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 568,
		source: "https://erde.fr/nos-remorques/daxara-198/",
		verifie: true,
	},
	{
		marque: "Daxara",
		modele: "218",
		longueur: 205,
		largeur: 129,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 545,
		source: "https://erde.fr/nos-remorques/daxara-218/",
		verifie: true,
	},
	{
		marque: "Daxara",
		modele: "238",
		longueur: 225,
		largeur: 129,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 523,
		source: "https://erde.fr/nos-remorques/daxara-238/",
		verifie: true,
	},
	{
		marque: "Daxara",
		modele: "239x4",
		longueur: 225,
		largeur: 129,
		ridelle: 40,
		plancher: 56,
		ptac: 750,
		chargeUtile: 475,
		source: "https://erde.fr/nos-remorques/daxara-239x4/",
		verifie: true,
	},

	// ── Brenderup ──────────────────────────────────────────────────────
	{
		marque: "Brenderup",
		modele: "1150 S",
		longueur: 144,
		largeur: 94,
		ridelle: null,
		plancher: null,
		ptac: 500,
		chargeUtile: 400,
		source: "https://www.remorques-wilsud.com/produit/1-44-m-x-0-95-m-500-kg-brenderup/",
		verifie: true,
	},
	{
		marque: "Brenderup",
		modele: "1205 S",
		longueur: 204,
		largeur: 116,
		ridelle: 35,
		plancher: 54.5,
		ptac: 500,
		chargeUtile: 370,
		source: "https://remorquesbremond.com/accueil/885-remorque-brenderup-type-1205s-basc-310969.html",
		verifie: true,
	},

	// ── Anssems ────────────────────────────────────────────────────────
	{
		marque: "Anssems",
		modele: "GT 750.211",
		longueur: 211,
		largeur: 126,
		ridelle: 30,
		plancher: null,
		ptac: 750,
		chargeUtile: 610,
		source: "https://www.univers-remorques.fr/remorque-bagagere-gt-750-211-anssems",
		verifie: true,
	},
	{
		marque: "Anssems",
		modele: "GT 750.251",
		longueur: 251,
		largeur: 126,
		ridelle: 30,
		plancher: null,
		ptac: 750,
		chargeUtile: 600,
		source: "https://www.univers-remorques.fr/remorques/remorque-bagagere-gt-750-251-anssems",
		verifie: true,
	},

	// ── Mil Remorques ──────────────────────────────────────────────────
	// Leurs fiches PDF sont protégées par mot de passe (HTTP 401), y compris
	// depuis un navigateur : ces cotes proviennent d'un index tiers citant ces
	// mêmes fiches. Elles restent à confirmer auprès du vendeur.
	{
		marque: "Mil Remorques",
		modele: "FIRST 150",
		longueur: 150,
		largeur: 100,
		ridelle: 39,
		plancher: null,
		ptac: null,
		chargeUtile: null,
		source:
			"https://www.mil-remorques.fr/lien%20pdf%20index/fiches-produits/Fiche_Produit_FIRST150.pdf",
		verifie: false,
	},
	{
		marque: "Mil Remorques",
		modele: "FIRST 170",
		longueur: 170,
		largeur: 122,
		ridelle: 39,
		plancher: null,
		ptac: null,
		chargeUtile: null,
		source:
			"https://www.mil-remorques.fr/lien%20pdf%20index/fiches-produits/Fiche_Produit_FIRST170.pdf",
		verifie: false,
	},
	{
		marque: "Mil Remorques",
		modele: "FIRST 230.2",
		longueur: 233,
		largeur: 132,
		ridelle: 39,
		plancher: null,
		ptac: null,
		chargeUtile: null,
		source:
			"https://www.mil-remorques.fr/lien%20pdf%20index/fiches-produits/Fiche_Produit_FIRST230.2.pdf",
		verifie: false,
	},
	{
		marque: "Mil Remorques",
		modele: "LIREM 150",
		longueur: 150,
		largeur: 100,
		ridelle: 46,
		plancher: null,
		ptac: null,
		chargeUtile: null,
		source:
			"https://www.mil-remorques.fr/lien%20pdf%20index/fiches-produits/Fiche_Produit_LIREM150.pdf",
		verifie: false,
	},
	{
		marque: "Mil Remorques",
		modele: "LIREM 170",
		longueur: 171,
		largeur: 122,
		ridelle: 46,
		plancher: null,
		ptac: null,
		chargeUtile: null,
		source:
			"https://www.mil-remorques.fr/lien%20pdf%20index/fiches-produits/Fiche_Produit_LIREM170.pdf",
		verifie: false,
	},

	// ── Norauto ────────────────────────────────────────────────────────
	// norauto.fr renvoie « Accès temporairement restreint » aux lectures
	// automatisées : cote rapportée, à confirmer en magasin.
	{
		marque: "Norauto",
		modele: "Premium 200",
		longueur: 194,
		largeur: 128,
		ridelle: 40,
		plancher: null,
		ptac: 500,
		chargeUtile: null,
		source: "https://www.norauto.fr/p/remorque-norauto-premium-200-75719.html",
		verifie: false,
	},
];

export type Resultat = {
	longueur: number;
	largeur: number;
	hauteur: number;
	/** Volume en litres. */
	volume: number;
	horsGabarit: string[];
};

/**
 * Hauteur minimale de la boîte : celle des ridelles. En dessous, la boîte
 * s'emboîterait entièrement dans la caisse et ne dépasserait plus.
 */
export function hauteurMini(ridelle: number | null): number {
	return Math.round(ridelle ?? RIDELLE_DEFAUT_CM);
}

/**
 * Hauteur maximale de la boîte, en cm : jamais plus haute que la caisse n'est
 * longue, et jamais plus de HAUTEUR_BOITE_MAX_CM (3 m). Les 2 m hors tout ne
 * bornent rien, ils ne servent qu'à situer l'ensemble dans les classes de péage.
 */
export function hauteurMaxi(longueurInterieure: number): number {
	return Math.max(1, Math.min(Math.round(longueurInterieure), HAUTEUR_BOITE_MAX_CM));
}

/**
 * Hauteur hors tout de l'ensemble, du sol au point le plus haut : plancher de
 * la remorque, boîte, barres de toit, puis tente repliée si elle est montée.
 */
export function hauteurHorsTout(
	hauteurBoite: number,
	plancher: number | null,
	tente = 0,
): number {
	return Math.round(
		hauteurBoite + (plancher ?? PLANCHER_DEFAUT_CM) + BARRES_TOIT_CM + tente,
	);
}

export type ClassePeage = { numero: 1 | 2 | 3; libelle: string; detail: string };

/**
 * Classe de péage de l'ensemble roulant, d'après la classification ASFA :
 * la hauteur hors tout et le PTAC déterminent la classe, et une voiture qui
 * tracte une remorque de plus de 2 m passe en classe 2.
 * https://www.autoroutes.fr/fr/classification-des-vehicules.htm
 */
export function classePeage(hauteurTotale: number): ClassePeage {
	if (hauteurTotale <= 200) {
		return {
			numero: 1,
			libelle: "Classe 1 — véhicules légers",
			detail: "Jusqu'à 2 m hors tout et 3,5 t de PTAC : le tarif le plus bas au péage.",
		};
	}
	if (hauteurTotale < 300) {
		return {
			numero: 2,
			libelle: "Classe 2 — véhicules intermédiaires",
			detail: "Entre 2 et 3 m hors tout : le péage coûte nettement plus cher qu'en classe 1.",
		};
	}
	return {
		numero: 3,
		libelle: "Classe 3 — au-delà de 3 m",
		detail: "À partir de 3 m hors tout, l'ensemble bascule dans la classe des poids lourds.",
	};
}

/** Ramène une hauteur saisie dans les bornes fabricables pour cette remorque. */
export function borneHauteur(
	valeur: number,
	ridelle: number | null,
	longueurInterieure: number,
	plancher: number | null,
	tente = 0,
): number {
	const mini = hauteurMini(ridelle);
	const maxi = hauteurMaxi(longueurInterieure);
	// Bornes croisées : la remorque ne peut pas recevoir de boîte. Le plafond
	// l'emporte, un dépassement de gabarit étant la faute la plus grave.
	if (maxi < mini) return maxi;
	if (!Number.isFinite(valeur)) return Math.min(maxi, Math.max(mini, HAUTEUR_DEFAUT_CM));
	return Math.min(maxi, Math.max(mini, Math.round(valeur)));
}

/** Applique la règle de dimensionnement à une remorque donnée. */
export function calculeBoite(
	longueurInterieure: number,
	largeurInterieure: number,
	hauteurSouhaitee: number = HAUTEUR_DEFAUT_CM,
	ridelle: number | null = null,
	plancher: number | null = null,
	tente = 0,
): Resultat {
	// Le jeu est laissé des deux côtés : il se retire donc deux fois par cote.
	const longueur = longueurInterieure - 2 * JEU_PAR_COTE_CM;
	const largeur = largeurInterieure - 2 * JEU_PAR_COTE_CM;
	const hauteur = borneHauteur(hauteurSouhaitee, ridelle, longueurInterieure, plancher, tente);

	const horsGabarit: string[] = [];
	if (hauteurMaxi(longueurInterieure) < hauteurMini(ridelle)) {
		horsGabarit.push("ridelles plus hautes que la boîte permise");
	}
	if (longueur < LIMITES.longueurMin) horsGabarit.push("longueur trop courte");
	if (longueur > LIMITES.longueurMax) {
		horsGabarit.push(`remorque de plus de ${LONGUEUR_REMORQUE_MAX_CM / 100} m de long`);
	}
	if (largeur < LIMITES.largeurMin) horsGabarit.push("largeur trop étroite");
	if (largeur > LIMITES.largeurMax) horsGabarit.push("largeur trop grande");

	return {
		longueur,
		largeur,
		hauteur,
		// 1 litre = 1 dm³ = 1000 cm³.
		volume: Math.round((longueur * largeur * hauteur) / 1000),
		horsGabarit,
	};
}
