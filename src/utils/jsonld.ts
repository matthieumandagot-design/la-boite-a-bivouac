/**
 * Sérialise un objet JSON-LD pour l'injecter via set:html dans un
 * <script type="application/ld+json">. Échappe tous les "<" en séquence
 * unicode pour qu'aucune valeur (question de FAQ, description produit...)
 * ne puisse jamais fermer prématurément la balise <script>.
 */
export function toJsonLd(data: unknown): string {
	return JSON.stringify(data).replace(/</g, "\\u003c");
}
