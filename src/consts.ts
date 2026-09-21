export const SITE_TITLE = "La Boîte à Bivouac";
export const TAGLINE = "L'aventure tient dans une boîte";

// Titre de la page d'accueil. Sans lui, l'accueil retombait sur SITE_TITLE
// seul — 20 caractères, aucun mot-clé — alors que c'est la page la plus
// importante du site. On garde la marque en tête, suivie de ce que le
// produit est réellement, pour rester sous les ~60 caractères affichés.
export const HOME_TITLE = "La Boîte à Bivouac — Module de bivouac pour votre remorque";
export const SITE_DESCRIPTION =
	"La Boîte à Bivouac conçoit un box modulaire à installer sur une remorque que vous possédez déjà. Le même module s'adapte au bivouac, à la montagne et à la mer, avec une structure renforcée capable d'accueillir une tente de toit et un VTT XXL. Conçu et fabriqué en Occitanie.";
export const HOME_DESCRIPTION =
	"Nous concevons et fabriquons en Occitanie des modules de bivouac et leurs équipements, à installer sur la remorque que vous avez déjà. Du matériel réparable, à moins de 2 000 €.";

export const NAV_LINKS = [
	{ href: "/", label: "Accueil" },
	{ href: "/la-boite/", label: "La Boîte" },
	{ href: "/compatibilite/", label: "Compatibilité" },
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
