<?php
declare(strict_types=1);

/**
 * Catalogue vendable (docs/SPEC-module-commercial.md §6.2).
 *
 *   GET /api/produits.php            → tout ce qui est en vente
 *   GET /api/produits.php?type=accessoire
 *
 * Ne renvoie que les articles visibles ET actifs : le catalogue public n'a pas
 * à révéler les brouillons ni les articles retirés de la vente.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/produits.php';
require_once __DIR__ . '/../lib/pricing.php';

apiExigerMethode('GET');
apiExigerBase();

$type = $_GET['type'] ?? null;
if ($type !== null && $type !== 'boite' && $type !== 'accessoire') {
	apiJson(400, ['error' => 'Type inconnu.']);
}

$produits = array_map('produitPublic', produitsLister(true, is_string($type) ? $type : null));

apiJson(200, [
	'produits' => $produits,
	// Le front doit pouvoir marquer les montants comme factices sans rien
	// deviner : la mention vient d'ici, pas d'une chaîne recopiée côté client.
	'provisoire' => pricingProvisoire(),
	'mention' => pricingMention(),
]);
