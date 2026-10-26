export const SITE_TITLE = "La Boîte à Bivouac";
export const TAGLINE = "L'aventure tient dans une boîte";

// Titre de la page d'accueil. Sans lui, l'accueil retombait sur SITE_TITLE
// seul — 20 caractères, aucun mot-clé — alors que c'est la page la plus
// importante du site. On garde la marque en tête, suivie de ce que le
// produit est réellement, pour rester sous les ~60 caractères affichés.
export const HOME_TITLE = "La Boîte à Bivouac — Plans gratuits d'un module de bivouac pour remorque";
/**
 * Modèle libre (26/10/2026) : la Boîte n'est plus vendue. Le site offre le
 * dossier de fabrication sur mesure, sous licence CC BY-SA 4.0.
 */
export const SITE_DESCRIPTION =
	"La Boîte à Bivouac est un module amovible qui transforme une remorque utilitaire standard en base de bivouac. Ses plans sont libres et gratuits : configurez la Boîte aux cotes de votre remorque et recevez son dossier de fabrication complet. Conçue en Occitanie.";
export const HOME_DESCRIPTION =
	"Configurez une Boîte à Bivouac aux cotes de votre remorque et recevez gratuitement son dossier de fabrication : plan A3, modèles 3D STEP et FreeCAD, liste de débit. Plans libres, sous licence CC BY-SA 4.0.";

/** Licence des plans et des modèles 3D, citée partout où le dossier est évoqué. */
export const LICENCE = {
	nom: "CC BY-SA 4.0",
	complet: "Creative Commons Attribution – Partage dans les mêmes conditions 4.0 International",
	url: "https://creativecommons.org/licenses/by-sa/4.0/deed.fr",
};

/**
 * Conservé pour les composants du module commercial, aujourd'hui hors parcours
 * (panier, commande) : la Boîte n'est plus vendue.
 */
export const MESSAGE_OUVERTURE_COMMANDES =
	"La Boîte n'est plus vendue : ses plans sont désormais offerts depuis le configurateur.";

export const NAV_LINKS = [
	{ href: "/", label: "Accueil" },
	{ href: "/la-boite/", label: "La Boîte" },
	{ href: "/configurateur/", label: "Plans gratuits" },
	{ href: "/equipements/", label: "Équipements" },
	{ href: "/faq/", label: "FAQ" },
	{ href: "/contact/", label: "Contact" },
];

export const NAVBAR_SOCIAL_LINKS = [
	{ href: "https://www.instagram.com/laboiteabivouac", label: "Instagram", icon: "instagram" },
	{ href: "https://www.facebook.com/laboiteabivouac", label: "Facebook", icon: "facebook" },
	{ href: "https://www.linkedin.com/company/laboiteabivouac", label: "LinkedIn", icon: "linkedin" },
];

export const SOCIAL_LINKS = [
	{
		href: "https://www.instagram.com/laboiteabivouac/",
		label: "Instagram",
		icon: "tabler:brand-instagram",
	},
	{
		href: "https://www.facebook.com/laboiteabivouac",
		label: "Facebook",
		icon: "tabler:brand-facebook",
	},
	{
		href: "https://www.linkedin.com/company/laboiteabivouac",
		label: "LinkedIn",
		icon: "tabler:brand-linkedin",
	},
	// URL par identifiant de chaîne plutôt que par pseudo : « @LaBoîteàBivouac »
	// contient des accents, qui s'encodent mal selon les outils qui relisent le
	// balisage. La fiche Google connaissait déjà cette chaîne alors que le site
	// ne la déclarait nulle part — d'où un lien de moins entre les deux.
	{
		href: "https://www.youtube.com/channel/UCK0cwQp6uq7g12jbGNa_8wg",
		label: "YouTube",
		icon: "tabler:brand-youtube",
	},
];
