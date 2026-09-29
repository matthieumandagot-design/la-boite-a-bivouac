/**
 * Prix de vente des accessoires du catalogue Équipements, pour l'affichage au
 * build (27/09/2026).
 *
 * Même calcul que equipementPrixVenteCents() dans public/lib/equipements.php,
 * qui fait foi au panier : prix estimé + marge de data/pricing.json, arrondi
 * au centime. scripts/test-prix.mjs vérifie que les deux s'accordent sur tout
 * le catalogue. La marge est passée en paramètre plutôt qu'importée : ce
 * fichier reste lisible par Node sans l'outillage d'Astro.
 */

/** Prix de vente TTC en centimes, à partir du prix estimé en centimes. */
export function prixVenteAccessoireCents(estimeCents: number, margePourcent: number): number {
	return Math.round((estimeCents * (100 + margePourcent)) / 100);
}

/** 3599 → « 35,99 € ». Même rendu que formaterCents() côté navigateur. */
export function formaterEuros(cents: number): string {
	return new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR" }).format(cents / 100);
}

/** Texte de prix d'un accessoire : « 35,99 € TTC », suivi de sa précision. */
export function libellePrixAccessoire(prixCents: number, precision = ""): string {
	return `${formaterEuros(prixCents)} TTC${precision ? ` ${precision}` : ""}`;
}

/** Frais de port affichés sous le prix, ou chaîne vide s'il n'y en a pas. */
export function libellePortAccessoire(portCents: number): string {
	return portCents > 0 ? `+ ${formaterEuros(portCents)} de frais de port` : "";
}
