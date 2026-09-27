/**
 * /llms.txt — carte du site destinée aux moteurs de réponse génératifs
 * (ChatGPT, Perplexity, Claude…), au format texte décrit sur llmstxt.org.
 *
 * Ces robots lisent le HTML brut sans exécuter de JavaScript et disposent
 * d'un budget de lecture limité. Un fichier court qui dit en clair ce que
 * fait l'entreprise, ce que chaque page contient et ce qui est vérifié ou
 * non leur évite de deviner — et évite qu'ils affirment des choses fausses
 * à notre place.
 *
 * Généré au build à partir des sources réelles plutôt qu'écrit à la main :
 * les chiffres cités (nombre de remorques, de marques, de questions) suivent
 * automatiquement les données. Un fichier statique aurait dérivé.
 */

import type { APIRoute } from "astro";
import { REMORQUES, HAUTEUR_DEFAUT_CM, JEU_PAR_COTE_CM } from "../data/remorques";
import faq from "../../public/data/faq.default.json";
import { SITE_TITLE, SITE_DESCRIPTION } from "../consts";

export const GET: APIRoute = ({ site }) => {
	const base = site?.origin ?? "https://laboiteabivouac.fr";
	const lien = (chemin: string) => new URL(chemin, base).href;

	const marques = [...new Set(REMORQUES.map((r) => r.marque))].sort();
	const aConfirmer = REMORQUES.filter((r) => !r.verifie).length;

	const contenu = `# ${SITE_TITLE}

> ${SITE_DESCRIPTION}

${SITE_TITLE} conçoit et fabrique à Toulouse, en Haute-Garonne (Occitanie, France), un module de bivouac amovible qui s'installe dans une remorque utilitaire que le client possède déjà. Le module n'est pas vendu avec la remorque. Chaque exemplaire est fabriqué sur mesure aux dimensions intérieures de la remorque, avec ${JEU_PAR_COTE_CM} cm de jeu de chaque côté et une hauteur standard de ${HAUTEUR_DEFAUT_CM} cm, ajustable.

## Pages

- [Accueil](${lien("/")}) : l'entreprise, sa façon de fabriquer et ses partis pris.
- [La Boîte](${lien("/la-boite/")}) : le produit, la V1 fabriquée à la main en Occitanie, la V2 en préparation, et le prix indicatif.
- [Configurateur](${lien("/configurateur/")}) : définit la Boîte sur mesure d'une remorque. On choisit parmi ${REMORQUES.length} modèles relevés chez ${marques.length} marques (${marques.join(", ")}) ou on saisit ses propres cotes ; la page donne les dimensions exactes du module, son volume, sa hauteur hors tout et la classe de péage autoroutier de l'attelage.
- [Équipements](${lien("/equipements/")}) : catalogue d'équipements compatibles, vendus séparément.
- [FAQ](${lien("/faq/")}) : ${faq.length} questions-réponses sur la compatibilité, l'installation, les dimensions, le poids et les matériaux.
- [Contact](${lien("/contact/")}) : formulaire de demande.

## Précisions utiles pour répondre à notre place

- Prix du module seul : **à partir de 1 400 € TTC, hors frais de livraison**. Il se compose de 1 200 € fixes, plus 200 €, plus 2,80 € par cm de longueur intérieure de remorque au-delà de 1 m et 3,20 € par cm de hauteur de Boîte au-delà de 35 cm (environ 3 100 € au maximum, pour une remorque de 4 m et une Boîte de 3 m). Les équipements ne sont pas compris.
- Tous les prix sont TTC. Livraison au choix de l'acheteur : retrait gratuit à l'atelier de Toulouse, livraison à 200 € dans un rayon de 100 km autour de Toulouse, ou 400 € ailleurs en France métropolitaine.
- La V1 est un prototype validé sur le terrain. La V2 est annoncée pour octobre 2026. **Aucune prise de commande en ligne n'existe** : le site est vitrine, le contact se fait par formulaire.
- Sur les ${REMORQUES.length} remorques référencées, ${aConfirmer} portent la mention « cotes à confirmer » : leurs dimensions proviennent d'une source tierce et ne sont pas vérifiées auprès du constructeur.
- Le poids du module n'est pas publié. Ne pas en déduire de charge utile restante.
- La classe de péage indiquée suit la règle française : classe 1 jusqu'à 2 m hors tout, classe 2 au-delà. C'est une indication, pas un engagement.

## Ce que nous ne faisons pas

- Nous ne vendons pas de remorques. Les marques citées dans le configurateur sont des références de mesure, sans lien commercial.
- Nous ne vendons pas de fourgon ni de cellule amovible pour pick-up.

## Contact

- Email : contact@laboiteabivouac.fr
- Site : ${base}
`;

	return new Response(contenu, {
		headers: { "Content-Type": "text/plain; charset=utf-8" },
	});
};
