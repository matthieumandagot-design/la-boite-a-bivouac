<?php
declare(strict_types=1);

/**
 * Déconnexion de l'administration : referme la session admin.
 *
 * POST et en-tête X-Admin-Requete obligatoires, comme toute écriture
 * authentifiée par session (voir lib/admin-auth.php) : sans eux, une image ou
 * un lien posé sur un autre site suffirait à déconnecter l'administrateur. Pas
 * de mot de passe demandé, en revanche : se déconnecter n'ouvre rien.
 */

require_once __DIR__ . '/../lib/admin-session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	header('Allow: POST');
	http_response_code(405);
	echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
	exit;
}

if (($_SERVER['HTTP_X_ADMIN_REQUETE'] ?? '') !== '1') {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Requête invalide.'], JSON_UNESCAPED_UNICODE);
	exit;
}

fermerSessionAdmin();

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
