<?php
declare(strict_types=1);

/**
 * Accessoires du catalogue Équipements, avec leur prix de vente (27/09/2026).
 *
 *   GET /api/accessoires.php → {"accessoires": [{id, domaine, titre, description,
 *                              medias, prix_cents, port_cents, precision}, …]}
 *
 * Public et sans verrou : c'est la vitrine, lue par la page Équipements et la
 * fenêtre d'accessoires du configurateur. Elle remplace la lecture directe de
 * /data/equipements.json, désormais interdite par .htaccess puisque le fichier
 * contient les prix d'achat estimés. Ne sort d'ici que ce que
 * equipementPublic() autorise.
 */

require_once __DIR__ . '/../lib/equipements.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
	header('Allow: GET');
	http_response_code(405);
	echo json_encode(['error' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
	exit;
}

echo json_encode(
	['accessoires' => array_map('equipementPublic', equipementsCatalogue())],
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
