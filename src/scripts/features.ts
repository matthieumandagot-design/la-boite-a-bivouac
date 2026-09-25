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
