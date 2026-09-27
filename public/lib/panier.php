<?php
declare(strict_types=1);

/**
 * Recalcul du panier côté serveur
 * (docs/SPEC-module-commercial.md §2, §6.3 et critère d'acceptation §10).
 *
 * LE POINT ESSENTIEL DE CE FICHIER
 * Le panier vit dans le localStorage du navigateur, donc n'importe qui peut en
 * réécrire le contenu depuis la console. Rien de ce qui arrive ici n'est cru :
 * aucun prix, aucune dimension calculée, aucun libellé. Le client ne transmet
 * que des CHOIX — quelle remorque, quelles cotes, quel article, combien — et le
 * serveur en déduit tout le reste depuis le catalogue et data/pricing.json.
 *
 * Concrètement : si une ligne arrive avec « prix_cents: 1 », ce champ n'est même
 * pas lu. Le total renvoyé est celui du serveur, et c'est le seul qui comptera
 * au moment du paiement.
 *
 * Une ligne invalide n'invalide pas le panier entier : elle est écartée avec son
 * motif, et l'interface le dit. Un article retiré de la vente entre deux visites
 * ne doit pas transformer le panier en page d'erreur.
 */

require_once __DIR__ . '/remorques.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/produits.php';

/** Au-delà, ce n'est plus un panier. Borne les abus autant que les erreurs. */
const PANIER_LIGNES_MAX = 20;
const PANIER_QUANTITE_MAX = 10;

/** Entier lu dans une entrée du client, ou null si la valeur est inexploitable. */
function panierEntier($valeur): ?int
{
	if (is_int($valeur)) {
		return $valeur;
	}
	// Le JSON du navigateur peut porter « 155 » ou 155.0 ; au-delà, on refuse.
	if (is_float($valeur) && floor($valeur) === $valeur) {
		return (int) $valeur;
	}
	if (is_string($valeur) && preg_match('/^-?\d{1,6}$/', trim($valeur)) === 1) {
		return (int) trim($valeur);
	}

	return null;
}

/** Quantité ramenée dans les bornes acceptables. */
function panierQuantite($valeur): int
{
	$quantite = panierEntier($valeur) ?? 1;

	return max(1, min(PANIER_QUANTITE_MAX, $quantite));
}

/**
 * Recalcule une ligne « boîte ».
 *
 * La remorque choisie l'emporte sur les cotes envoyées : si le client dit
 * « Erdé Classic 163 », les dimensions viennent du catalogue, pas du navigateur.
 * Les cotes saisies ne servent que pour une remorque hors catalogue.
 *
 * @return array<string, mixed>
 */
function panierLigneBoite(array $ligne): array
{
	$quantite = panierQuantite($ligne['quantite'] ?? 1);
	$cle = is_string($ligne['remorque'] ?? null) ? $ligne['remorque'] : '';
	$remorque = $cle === '' ? null : remorqueParCle($cle);

	if ($cle !== '' && $remorque === null) {
		return ['ok' => false, 'motif' => 'Remorque inconnue.', 'type' => 'boite'];
	}

	if ($remorque !== null) {
		$longueur = (int) $remorque['longueur'];
		$largeur = (int) $remorque['largeur'];
		$ridelle = $remorque['ridelle'] === null ? null : (int) $remorque['ridelle'];
		$libelleRemorque = $remorque['marque'] . ' ' . $remorque['modele'];
	} else {
		$longueur = panierEntier($ligne['longueur'] ?? null) ?? 0;
		$largeur = panierEntier($ligne['largeur'] ?? null) ?? 0;
		$ridelle = panierEntier($ligne['ridelle'] ?? null);
		$libelleRemorque = 'cotes saisies';

		// Garde-fou avant tout calcul : des cotes absurdes ne doivent pas
		// produire un volume, donc un prix.
		if ($longueur < 1 || $largeur < 1 || $longueur > 1000 || $largeur > 1000) {
			return ['ok' => false, 'motif' => 'Cotes intérieures manquantes ou invalides.', 'type' => 'boite'];
		}
	}

	$hauteurSouhaitee = panierEntier($ligne['hauteur'] ?? null) ?? RQ_HAUTEUR_DEFAUT_CM;
	$dimensions = rqCalculeBoite($longueur, $largeur, (float) $hauteurSouhaitee, $ridelle);

	if ($dimensions['horsGabarit'] !== []) {
		return [
			'ok' => false,
			'type' => 'boite',
			'motif' => 'Cette configuration sort des limites de fabrication : ' . implode(', ', $dimensions['horsGabarit']) . '.',
		];
	}

	// Options du configurateur (§7). Un identifiant inconnu fait refuser la
	// ligne : il signale un catalogue qui a changé, ou un panier bricolé. Dans
	// les deux cas, deviner ce que le client voulait serait pire que demander.
	$options = prixOptionsCents($ligne['options'] ?? []);
	if ($options['inconnues'] !== []) {
		return [
			'ok' => false,
			'type' => 'boite',
			'motif' => 'Une option choisie n\'existe plus : revoyez votre configuration.',
		];
	}

	// Le prix se calcule sur la longueur de la REMORQUE (formule du 27/09/2026),
	// pas sur celle de la boîte.
	$prix = prixBoiteCents($dimensions, $longueur, $options['cents']);
	if ($prix['hors_bornes']) {
		// Configuration de prix incohérente : refuser vaut mieux que facturer un
		// montant que personne n'a décidé.
		return ['ok' => false, 'type' => 'boite', 'motif' => 'Prix indisponible pour cette configuration.'];
	}

	$libellesOptions = array_column($options['retenues'], 'libelle');

	return [
		'ok' => true,
		'type' => 'boite',
		'libelle' => 'La Boîte à Bivouac sur mesure',
		'detail' => sprintf(
			'%d × %d × %d cm — %d L (%s)%s',
			$dimensions['longueur'],
			$dimensions['largeur'],
			$dimensions['hauteur'],
			$dimensions['volume'],
			$libelleRemorque,
			$libellesOptions === [] ? '' : ' · ' . implode(', ', $libellesOptions)
		),
		'configuration' => [
			'remorque' => $cle === '' ? null : $cle,
			'longueur_interieure' => $longueur,
			'largeur_interieure' => $largeur,
			'ridelle' => $ridelle,
			'dimensions' => $dimensions,
			'options' => $options['retenues'],
		],
		'quantite' => $quantite,
		'prix_unitaire_cents' => $prix['cents'],
		'total_cents' => $prix['cents'] * $quantite,
		'photo' => '',
	];
}

/**
 * Recalcule une ligne « accessoire ».
 *
 * Le prix vient de la base, jamais de la ligne reçue. Un article retiré de la
 * vente est écarté : le panier d'hier ne crée pas de droit sur le catalogue
 * d'aujourd'hui.
 */
function panierLigneAccessoire(array $ligne): array
{
	$id = panierEntier($ligne['produit_id'] ?? null);
	$produit = $id === null || $id <= 0 ? null : produitParId($id);

	if ($produit === null) {
		return ['ok' => false, 'type' => 'accessoire', 'motif' => 'Article inconnu.'];
	}
	if ((int) $produit['actif'] !== 1 || (int) $produit['visible'] !== 1) {
		return [
			'ok' => false,
			'type' => 'accessoire',
			'motif' => sprintf('« %s » n\'est plus disponible.', $produit['nom']),
		];
	}

	$quantite = panierQuantite($ligne['quantite'] ?? 1);
	$prix = (int) $produit['prix_cents'];

	return [
		'ok' => true,
		'type' => 'accessoire',
		'produit_id' => (int) $produit['id'],
		'libelle' => (string) $produit['nom'],
		'detail' => (string) $produit['description'],
		'quantite' => $quantite,
		'prix_unitaire_cents' => $prix,
		'total_cents' => $prix * $quantite,
		'photo' => (string) $produit['photo'],
	];
}

/**
 * Recalcule un panier entier.
 *
 * @param mixed $brut le contenu du localStorage, tel quel
 * @param ?string $livraison identifiant du mode de livraison choisi ; null tant
 *                          que l'acheteur n'a pas choisi (panier, configurateur)
 * @return array{lignes: array, refusees: array, sous_total_cents: int, livraison: ?array, livraison_cents: int, livraison_modes: array, total_cents: int, provisoire: bool, mention: string, mention_prix: string}
 */
function panierRecalcule($brut, ?string $livraison = null): array
{
	$lignes = [];
	$refusees = [];

	$entrees = is_array($brut) ? $brut : [];
	// Tronqué plutôt que refusé : un panier trop long est une anomalie, pas une
	// raison de perdre les articles légitimes qui le précèdent.
	$entrees = array_slice(array_values($entrees), 0, PANIER_LIGNES_MAX);

	foreach ($entrees as $position => $entree) {
		if (!is_array($entree)) {
			$refusees[] = ['index' => $position, 'type' => 'inconnu', 'motif' => 'Ligne non reconnue.'];
			continue;
		}

		$type = $entree['type'] ?? '';
		$resultat = match ($type) {
			'boite' => panierLigneBoite($entree),
			'accessoire' => panierLigneAccessoire($entree),
			default => ['ok' => false, 'type' => 'inconnu', 'motif' => 'Ligne non reconnue.'],
		};

		// La position d'origine voyage avec la ligne : sans elle, le navigateur
		// devrait deviner à quelle entrée du localStorage correspond quel
		// résultat — et se tromperait dès qu'une ligne est écartée.
		$resultat['index'] = $position;

		if ($resultat['ok']) {
			unset($resultat['ok']);
			$lignes[] = $resultat;
		} else {
			$refusees[] = ['index' => $position, 'type' => $resultat['type'], 'motif' => $resultat['motif']];
		}
	}

	$sousTotal = array_sum(array_column($lignes, 'total_cents'));
	// Livraison (27/09/2026) : au choix de l'acheteur, dans le tunnel. Tant qu'il
	// n'a pas choisi, rien n'est compté ; un identifiant inconnu non plus.
	$mode = ($livraison === null || $lignes === []) ? null : livraisonMode($livraison);
	$livraisonCents = $mode['prix_cents'] ?? 0;

	return [
		'lignes' => $lignes,
		'refusees' => $refusees,
		'sous_total_cents' => $sousTotal,
		'livraison' => $mode === null ? null : [
			'id' => $mode['id'],
			'libelle' => $mode['libelle'],
			'prix_cents' => $mode['prix_cents'],
		],
		'livraison_cents' => $livraisonCents,
		'livraison_modes' => livraisonModesPublics(),
		'total_cents' => $sousTotal + $livraisonCents,
		'provisoire' => pricingProvisoire(),
		'mention' => pricingMention(),
		'mention_prix' => pricingMentionPrix(),
	];
}
