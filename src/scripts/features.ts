/**
 * Révélation des éléments du module commercial
 * (docs/SPEC-module-commercial.md §5.3).
 *
 * Tout ce qui relève de la boutique est écrit dans le HTML avec `hidden` et
 * `data-feature="commerce"`. Ce script interroge le serveur et ne retire
 * `hidden` que si le module est réellement ouvert — ou si un administrateur
 * est reconnu, auquel cas un bandeau d'aperçu apparaît.
 *
 * DEUX PRINCIPES
 *
 * 1. Fermé par défaut. Si l'appel échoue — réseau coupé, PHP en erreur, réponse
 *    inattendue — rien n'est révélé. Le mauvais côté de l'erreur est d'ouvrir
 *    une boutique qui devait rester fermée, jamais l'inverse.
 *
 * 2. Ceci n'est pas une protection. Le masquage est un confort d'affichage ;
 *    n'importe qui peut retirer un attribut `hidden` depuis son navigateur.
 *    La vraie barrière est côté serveur, dans lib/commerce-guard.php, qui
 *    répond 404 tant que le module dort.
 */

import { ajouter, rafraichirBadge } from "./panier";

type EtatModule = { commerce: boolean; preview: boolean };

const SELECTEUR = '[data-feature="commerce"]';
const SELECTEUR_BANDEAU = '[data-commerce-preview]';

/** Lit l'état du module. Retourne null dès que la réponse n'est pas exploitable. */
async function litEtat(): Promise<EtatModule | null> {
	try {
		const reponse = await fetch('/api/settings.php', {
			// L'état doit être frais : un admin qui bascule l'interrupteur veut
			// le voir au rechargement suivant, pas au bon vouloir d'un cache.
			cache: 'no-store',
			headers: { Accept: 'application/json' },
			credentials: 'same-origin',
		});

		if (!reponse.ok) return null;

		const donnees: unknown = await reponse.json();
		if (typeof donnees !== 'object' || donnees === null) return null;

		const { commerce, preview } = donnees as Record<string, unknown>;
		// Seul le booléen vrai compte : une réponse mal formée ne doit pas
		// ouvrir la boutique par coïncidence de typage.
		return { commerce: commerce === true, preview: preview === true };
	} catch {
		return null;
	}
}

function revele(racine: ParentNode = document): void {
	racine.querySelectorAll<HTMLElement>(SELECTEUR).forEach((element) => {
		element.hidden = false;
		// aria-hidden accompagne parfois `hidden` sur les éléments décoratifs :
		// le laisser en place masquerait l'élément aux lecteurs d'écran alors
		// qu'il est désormais visible.
		element.removeAttribute('aria-hidden');
	});
}

function afficheBandeauApercu(): void {
	document.querySelectorAll<HTMLElement>(SELECTEUR_BANDEAU).forEach((element) => {
		element.hidden = false;
	});
}

async function initialise(): Promise<void> {
	// L'icône du panier est publique depuis le 27/09/2026 : sa pastille se met
	// à jour sur toutes les pages, boutique ouverte ou non.
	rafraichirBadge();

	const etat = await litEtat();
	if (etat === null) return;

	if (etat.commerce || etat.preview) {
		revele();
	}

	if (etat.preview) {
		afficheBandeauApercu();
	}

	// Signal pour le reste du site : permet à une page d'ajuster son rendu sans
	// refaire l'appel. `detail` est en lecture seule pour les abonnés.
	document.dispatchEvent(new CustomEvent<EtatModule>('bab:commerce', { detail: etat }));
}

void initialise();

/*
 * Boutons « Ajouter au panier » des accessoires du catalogue Équipements
 * (27/09/2026) : page Équipements et fenêtre d'accessoires du configurateur.
 * Délégation sur le document, pour servir aussi les cartes recréées après
 * coup. Le bouton ne porte qu'un identifiant : le prix est recalculé par le
 * serveur, comme pour tout le panier.
 */
document.addEventListener("click", (event) => {
	const bouton = (event.target as Element | null)?.closest<HTMLButtonElement>("[data-ajouter-equipement]");
	const id = bouton?.dataset.ajouterEquipement;
	if (!bouton || !id || bouton.disabled) return;

	ajouter({ type: "equipement", id, quantite: 1 });

	const texte = bouton.textContent;
	bouton.textContent = "Ajouté ✓";
	bouton.disabled = true;
	window.setTimeout(() => {
		bouton.textContent = texte;
		bouton.disabled = false;
	}, 1600);
});
