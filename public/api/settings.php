<?php
declare(strict_types=1);

/**
 * État du module commercial, lu par le front
 * (docs/SPEC-module-commercial.md §5.1 et §5.3).
 *
 *   GET /api/settings.php → {"commerce": bool, "preview": bool}
 *
 * Volontairement HORS du commerce-guard : c'est précisément l'endpoint qui
 * répond « le module est-il ouvert ? ». Le passer derrière le verrou le rendrait
 * inutilisable, et un 404 ne se distinguerait pas d'un module désactivé.
 *
 * Il ne divulgue que deux booléens. Pas le mode de paiement, pas la date de
 * modification, rien qui renseigne un visiteur sur l'état interne du site.
 */

require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/admin-session.php';

header('Content-Type: application/json; charset=utf-8');
// Jamais de cache : un admin qui bascule l'interrupteur doit voir l'effet au
// rechargement suivant, sans dépendre d'un intermédiaire.
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
	http_response_code(405);
	header('Allow: GET');
	echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
	exit;
}

$commerce = commerceActif();

// Aperçu : le module dort pour le public, mais un admin authentifié le voit.
// Faux quand le module est déjà ouvert à tous — il n'y a alors plus rien à
// prévisualiser, et le bandeau d'aperçu n'aurait pas de sens.
$apercu = !$commerce && sessionAdminOuverte();

echo json_encode(
	['commerce' => $commerce, 'preview' => $apercu],
	JSON_UNESCAPED_UNICODE
);
