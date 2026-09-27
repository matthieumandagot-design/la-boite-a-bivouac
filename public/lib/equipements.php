<?php
declare(strict_types=1);

/**
 * Accessoires du catalogue Équipements, rendus achetables (27/09/2026).
 *
 * Le catalogue reste celui de /admin/equipements.php, stocké en JSON : c'est là
 * que Matthieu saisit photos et descriptions. Deux champs chiffrés s'y ajoutent,
 * jamais montrés au public :
 *   - prix_estime_cents : ce que l'accessoire coûte à l'atelier (estimation) ;
 *   - frais_port_cents  : les frais de port estimés pour le faire venir.
 *
 * Prix de vente = prix estimé + la marge de data/pricing.json (20 %). Les frais
 * de port ne sont pas margés : ils s'ajoutent à part, au panier et à la
 * commande (décision du 27/09/2026).
 *
 * Les fiches enregistrées avant l'ajout de ces champs n'ont qu'un texte libre
 * (« À partir de 29,99 € ») : le montant en est extrait tant que l'admin n'a
 * pas saisi le chiffre. Le texte lui-même n'est plus publié — c'est un prix
 * d'achat, pas un prix de vente.
 *
 * Aucune sortie HTTP ici : l'API, le panier et l'admin s'en servent.
 */

require_once __DIR__ . '/pricing.php';

const EQUIPEMENTS_LIVE = __DIR__ . '/../data/equipements.json';
const EQUIPEMENTS_DEFAUT = __DIR__ . '/../data/equipements.default.json';

/**
 * Le catalogue tel que l'admin l'a enregistré, ou celui du dépôt à défaut.
 *
 * @return list<array<string, mixed>>
 */
function equipementsCatalogue(): array
{
	static $catalogue = null;
	if ($catalogue !== null) {
		return $catalogue;
	}

	$chemin = is_file(EQUIPEMENTS_LIVE) ? EQUIPEMENTS_LIVE : EQUIPEMENTS_DEFAUT;
	$brut = is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;
	$catalogue = is_array($brut)
		? array_values(array_filter($brut, static fn ($item): bool => is_array($item) && is_string($item['id'] ?? null)))
		: [];

	return $catalogue;
}

/** Une fiche du catalogue, ou null. */
function equipementParId(string $id): ?array
{
	foreach (equipementsCatalogue() as $item) {
		if ($item['id'] === $id) {
			return $item;
		}
	}

	return null;
}

/**
 * Premier montant en euros d'un texte libre, en centimes.
 *
 * « À partir de 29,99 € » → 2999 ; « 1 249 € TTC » → 124900. Les séparateurs
 * de milliers peuvent être des espaces, insécables compris. Null si aucun
 * montant n'est reconnu.
 *
 * @return array{cents: int, reste: string}|null reste : ce qui suit « € »
 */
function equipementLireMontant(string $texte): ?array
{
	if (!preg_match('/(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+|\d+)(?:[,.](\d{1,2}))?\s*€(.*)$/u', $texte, $m)) {
		return null;
	}

	$euros = (int) preg_replace('/\D/u', '', $m[1]);
	$centimes = isset($m[2]) && $m[2] !== '' ? (int) str_pad($m[2], 2, '0') : 0;

	return ['cents' => $euros * 100 + $centimes, 'reste' => trim($m[3])];
}

/** Prix estimé (d'achat), en centimes : le chiffre saisi, sinon celui du texte. */
function equipementPrixEstimeCents(array $item): ?int
{
	$chiffre = $item['prix_estime_cents'] ?? null;
	if (is_int($chiffre) && $chiffre > 0) {
		return $chiffre;
	}

	$lu = equipementLireMontant((string) ($item['prix'] ?? ''));

	return $lu !== null && $lu['cents'] > 0 ? $lu['cents'] : null;
}

/** Frais de port estimés pour un exemplaire, en centimes (0 si non renseignés). */
function equipementPortCents(array $item): int
{
	$port = $item['frais_port_cents'] ?? 0;

	return is_int($port) && $port > 0 ? $port : 0;
}

/**
 * Précision affichée après le prix : « la paire », « l'unité »…
 *
 * Le champ saisi dans l'admin, sinon ce qui suivait « € » dans l'ancien texte,
 * sans la mention TTC désormais ajoutée partout.
 */
function equipementPrecision(array $item): string
{
	if (is_string($item['precision'] ?? null)) {
		return trim($item['precision']);
	}

	$lu = equipementLireMontant((string) ($item['prix'] ?? ''));
	if ($lu === null) {
		return '';
	}

	return trim((string) preg_replace('/\bTTC\b/iu', '', $lu['reste']));
}

/** Marge appliquée aux prix estimés, en pour cent (data/pricing.json). */
function equipementMargePourcent(): int
{
	$marge = pricing()['accessoires']['marge_pourcent'] ?? 0;

	return is_int($marge) && $marge >= 0 ? $marge : 0;
}

/**
 * Prix de vente TTC d'un accessoire, en centimes : prix estimé + marge,
 * arrondi au centime. Même calcul que prixVenteAccessoireCents() dans
 * src/data/accessoires.ts, qui affiche le prix au build.
 */
function equipementPrixVenteCents(int $estimeCents): int
{
	return (int) round($estimeCents * (100 + equipementMargePourcent()) / 100);
}

/** Médias d'une fiche, en ramenant l'ancien champ « image » à la liste. */
function equipementMedias(array $item): array
{
	if (is_array($item['medias'] ?? null)) {
		return array_values(array_filter(
			$item['medias'],
			static fn ($m): bool => is_array($m) && is_string($m['url'] ?? null) && $m['url'] !== ''
		));
	}

	return is_string($item['image'] ?? null) && $item['image'] !== ''
		? [['type' => 'image', 'url' => $item['image']]]
		: [];
}

/**
 * Ce qui peut être publié d'une fiche : ni prix estimé, ni frais de port.
 *
 * @return array{id: string, domaine: string, titre: string, description: string, medias: array, prix_cents: ?int, port_cents: int, precision: string}
 */
function equipementPublic(array $item): array
{
	$estime = equipementPrixEstimeCents($item);

	return [
		'id' => (string) $item['id'],
		'domaine' => (string) ($item['domaine'] ?? ''),
		'titre' => (string) ($item['titre'] ?? ''),
		'description' => (string) ($item['description'] ?? ''),
		'medias' => equipementMedias($item),
		// Null : pas encore de prix, l'accessoire s'affiche sans bouton d'achat.
		'prix_cents' => $estime === null ? null : equipementPrixVenteCents($estime),
		// Frais de port facturés à part : le visiteur doit les connaître avant
		// d'ajouter l'article. Seul le coût d'achat reste privé.
		'port_cents' => equipementPortCents($item),
		'precision' => equipementPrecision($item),
	];
}
