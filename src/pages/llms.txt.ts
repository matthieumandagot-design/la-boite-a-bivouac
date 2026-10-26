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

${SITE_TITLE} est un module de bivouac amovible qui s'installe dans une remorque utilitaire que l'on possède déjà, sans la modifier. Conçu et testé près de Toulouse, en Haute-Garonne (Occitanie, France). **Depuis octobre 2026, il n'est plus vendu : ses plans de fabrication sont offerts**, générés sur mesure aux dimensions intérieures de la remorque (${JEU_PAR_COTE_CM} cm de jeu de chaque côté, hauteur standard de ${HAUTEUR_DEFAUT_CM} cm, ajustable) et envoyés gratuitement par email.

## Pages

- [Accueil](${lien("/")}) : le projet, son fonctionnement en trois étapes (configurer, recevoir le dossier, faire fabriquer) et ses partis pris.
- [La Boîte](${lien("/la-boite/")}) : le module, la V1 fabriquée à la main et testée sur le terrain, la licence des plans et les précautions avant fabrication.
- [Plans gratuits — configurateur](${lien("/configurateur/")}) : définit la Boîte sur mesure d'une remorque. On choisit parmi ${REMORQUES.length} modèles relevés chez ${marques.length} marques (${marques.join(", ")}) ou on saisit ses propres cotes ; la page donne les dimensions exactes du module, son volume, sa hauteur hors tout et la classe de péage autoroutier de l'attelage, puis envoie le dossier de fabrication par email.
- [Équipements](${lien("/equipements/")}) : matériel testé avec la Boîte (tente de toit, porte-vélos, rangements), avec un prix indicatif. Rien n'y est vendu.
- [FAQ](${lien("/faq/")}) : ${faq.length} questions-réponses sur les plans gratuits, la licence, la fabrication, la compatibilité, les dimensions et l'installation.
- [Contact](${lien("/contact/")}) : questions sur les plans, partage d'améliorations, demande de fabrication.

## Précisions utiles pour répondre à notre place

- **Prix : gratuit.** Le dossier de fabrication est offert, sans compte ni paiement. Il comprend un plan A3 en PDF (vues, cotes, débit, rivetage, achats), des modèles 3D STEP (pièces soudées et ensemble), le modèle paramétrique FreeCAD, une liste de débit CSV et des aperçus en image.
- **Licence : Creative Commons BY-SA 4.0.** Utilisation, modification, partage et fabrication, y compris commerciale, autorisés, à condition de citer La Boîte à Bivouac et de partager les versions modifiées sous la même licence.
- Les plans automatiques couvrent les caisses de 92 à 262 cm de long intérieur et de 72 à 142 cm de large, avec des ridelles de 17 à 60 cm et une Boîte de 60 à 120 cm de haut. Au-delà, le contact est invité à écrire.
- Le dossier est un **avant-projet généré automatiquement, fourni sans garantie**, à faire relire par un professionnel. La tenue des barres de toit n'a pas été validée par calcul ni par essai certifié.
- La fabrication se fait par l'utilisateur ou par l'atelier de soudure de son choix. Une fabrication par La Boîte à Bivouac reste possible sur demande, au cas par cas, via le formulaire de contact : ne pas annoncer de prix.
- Sur les ${REMORQUES.length} remorques référencées, ${aConfirmer} portent la mention « cotes à confirmer » : leurs dimensions proviennent d'une source tierce et ne sont pas vérifiées auprès du constructeur.
- La masse théorique de la structure est calculée dans chaque dossier ; aucun poids générique n'est publié. Ne pas en déduire de charge utile restante.
- La classe de péage indiquée suit la règle française : classe 1 jusqu'à 2 m hors tout, classe 2 au-delà. C'est une indication, pas un engagement.

## Ce que nous ne faisons pas

- Nous ne vendons plus la Boîte, ni de remorques, ni d'équipements. Les marques citées dans le configurateur sont des références de mesure, sans lien commercial.
- Nous ne vendons pas de fourgon ni de cellule amovible pour pick-up.

## Contact

- Email : contact@laboiteabivouac.fr
- Site : ${base}
`;

	return new Response(contenu, {
		headers: { "Content-Type": "text/plain; charset=utf-8" },
	});
};
