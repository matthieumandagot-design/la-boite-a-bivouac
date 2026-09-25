/**
 * Panier côté navigateur (docs/SPEC-module-commercial.md §6.3).
 *
 * Le panier vit dans le localStorage : on le remplit sans compte, et il survit
 * à la fermeture de l'onglet. Il ne contient que des CHOIX — quelle remorque,
 * quelles cotes, quel article, combien. Jamais de prix : ce qui est affiché
 * vient de /api/panier.php, qui recalcule tout côté serveur.
 *
 * C'est délibéré, et c'est la moitié visible du critère « un prix modifié dans
 * le localStorage est ignoré » : il n'y a tout simplement pas de prix à
 * modifier. L'autre moitié est dans public/lib/panier.php, qui ne lirait pas ce
 * champ même s'il était là.
 *
 * Tous les accès au stockage sont protégés : en navigation privée verrouillée
 * ou avec les cookies bloqués, lire ou écrire lève une exception. Le panier est
 * alors vide à chaque page plutôt que la page entière ne casse.
 */

const CLE = "bab_cart_v1";

export type LigneBoite = {
	type: "boite";
	/** Clé « marque|modele » du catalogue, ou absente si cotes saisies. */
	remorque?: string;
	longueur?: number;
	largeur?: number;
	ridelle?: number | null;
	hauteur?: number;
	quantite: number;
};

export type LigneAccessoire = {
	type: "accessoire";
	produit_id: number;
	quantite: number;
};

export type Ligne = LigneBoite | LigneAccessoire;

/** Une ligne telle que le serveur la renvoie, prix compris. */
export type LigneCalculee = {
	/** Position dans le panier envoyé : sert à viser la bonne entrée du stockage. */
	index: number;
	type: string;
	libelle: string;
	detail: string;
	quantite: number;
	prix_unitaire_cents: number;
	total_cents: number;
	photo: string;
};

export type PanierCalcule = {
	lignes: LigneCalculee[];
	refusees: { index: number; type: string; motif: string }[];
	sous_total_cents: number;
	livraison_cents: number;
	total_cents: number;
	provisoire: boolean;
	mention: string;
};

export function lire(): Ligne[] {
	try {
		const brut = localStorage.getItem(CLE);
		if (!brut) return [];
		const valeur: unknown = JSON.parse(brut);
		return Array.isArray(valeur) ? (valeur as Ligne[]) : [];
	} catch {
		return [];
	}
}

export function ecrire(lignes: Ligne[]): void {
	try {
		localStorage.setItem(CLE, JSON.stringify(lignes));
	} catch {
		// Stockage indisponible : le panier ne survivra pas au rechargement,
		// mais la page continue de fonctionner.
	}
	rafraichirBadge();
	window.dispatchEvent(new CustomEvent("bab:panier"));
}

/** Nombre d'articles, quantités comprises. */
export function nombreArticles(): number {
	return lire().reduce((total, ligne) => total + Math.max(1, Number(ligne.quantite) || 1), 0);
}

/**
 * Ajoute une ligne.
 *
 * Deux fois le même accessoire n'ajoute pas deux lignes : la quantité monte,
 * comme on s'y attend d'un panier. Deux boîtes, en revanche, restent séparées —
 * elles peuvent avoir des configurations différentes.
 */
export function ajouter(ligne: Ligne): void {
	const lignes = lire();

	if (ligne.type === "accessoire") {
		const existante = lignes.find(
			(l): l is LigneAccessoire => l.type === "accessoire" && l.produit_id === ligne.produit_id,
		);
		if (existante) {
			existante.quantite = Math.min(10, (Number(existante.quantite) || 1) + (Number(ligne.quantite) || 1));
			ecrire(lignes);
			// Même événement que pour une nouvelle ligne : du point de vue de la
			// mesure, ajouter un deuxième exemplaire reste un ajout au panier.
			mesurer("ajout_panier");
			return;
		}
	}

	lignes.push(ligne);
	ecrire(lignes.slice(0, 20));
	mesurer("ajout_panier");
}

export function definirQuantite(index: number, quantite: number): void {
	const lignes = lire();
	if (!lignes[index]) return;
	lignes[index].quantite = Math.max(1, Math.min(10, Math.round(quantite) || 1));
	ecrire(lignes);
}

export function retirer(index: number): void {
	const lignes = lire();
	if (!lignes[index]) return;
	lignes.splice(index, 1);
	ecrire(lignes);
}

export function vider(): void {
	ecrire([]);
}

/** Met à jour la pastille du panier dans l'en-tête. */
export function rafraichirBadge(): void {
	const nombre = nombreArticles();
	document.querySelectorAll<HTMLElement>("[data-panier-compte]").forEach((pastille) => {
		pastille.textContent = String(nombre);
		pastille.hidden = nombre === 0;
	});
}

/**
 * Jeton CSRF de la session, demandé une seule fois par page.
 *
 * Il vient de /api/compte.php, qui ouvre la session au passage. Null si le
 * module est fermé — l'appelant doit alors renoncer, pas réessayer.
 */
let jetonEnCours: Promise<string | null> | null = null;

export function jetonCsrf(forcer = false): Promise<string | null> {
	if (forcer) jetonEnCours = null;
	if (jetonEnCours) return jetonEnCours;

	jetonEnCours = fetch("/api/compte.php", {
		cache: "no-store",
		credentials: "same-origin",
		headers: { Accept: "application/json" },
	})
		.then((reponse) => (reponse.ok ? reponse.json() : null))
		.then((donnees) => (donnees && typeof donnees.csrf === "string" ? donnees.csrf : null))
		.catch(() => null);

	return jetonEnCours;
}

/**
 * Demande au serveur ce que vaut le panier.
 *
 * Retourne null si l'appel échoue : l'appelant affiche alors un message plutôt
 * qu'un total inventé. Aucun prix n'est jamais calculé dans le navigateur.
 */
export async function recalculer(): Promise<PanierCalcule | null> {
	const jeton = await jetonCsrf();
	if (!jeton) return null;

	try {
		const reponse = await fetch("/api/panier.php", {
			method: "POST",
			cache: "no-store",
			credentials: "same-origin",
			headers: { "Content-Type": "application/json", "X-CSRF-Token": jeton },
			body: JSON.stringify({ lignes: lire() }),
		});
		if (!reponse.ok) return null;
		return (await reponse.json()) as PanierCalcule;
	} catch {
		return null;
	}
}

/**
 * Événement de mesure d'audience (docs/SPEC-module-commercial.md §6.7).
 *
 * Umami, sans cookie et sans donnée personnelle : on envoie un nom d'étape, rien
 * d'autre — ni numéro de commande, ni montant, ni identifiant client. Le script
 * peut être absent (bloqueur de publicité, réseau coupé) : l'appel est alors
 * sans effet, et surtout sans erreur.
 */
export function mesurer(evenement: string): void {
	try {
		(window as unknown as { umami?: { track: (nom: string) => void } }).umami?.track(evenement);
	} catch {
		// La mesure d'audience ne doit jamais interrompre un achat.
	}
}

/** Formate des centimes : 209800 → « 2 098,00 € ». */
export function formaterCents(cents: number): string {
	return new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR" }).format(cents / 100);
}
