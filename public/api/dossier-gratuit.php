<?php
declare(strict_types=1);

/**
 * API du dossier de fabrication offert (voir lib/dossier-gratuit.php).
 *
 * POST JSON, deux actions :
 *   { "action": "demander", "email": "...", "accord": true, "site_web": "",
 *     "configuration": { "remorque": "Marque|Modèle" } ou { "longueur", "largeur", "ridelle" },
 *     ... + "hauteur" }
 *       → envoie le lien de confirmation.
 *   { "action": "confirmer", "jeton": "..." }
 *       → lance la génération ; le dossier arrive par email.
 *
 * Réponses : { ok: bool, message: string, details?: string[] }.
 */

require_once __DIR__ . '/../lib/dossier-gratuit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function dgRepondre(int $statut, array $corps): void
{
	http_response_code($statut);
	echo json_encode($corps, JSON_UNESCAPED_UNICODE);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	dgRepondre(405, ['ok' => false, 'message' => 'POST uniquement.']);
}

$brut = (string) file_get_contents('php://input', false, null, 0, 20000);
$entree = json_decode($brut, true);
if (!is_array($entree)) {
	dgRepondre(400, ['ok' => false, 'message' => 'Requête illisible.']);
}

try {
	$action = (string) ($entree['action'] ?? '');
	if ($action === 'demander') {
		dgRepondre(200, dgDemander($entree));
	}
	if ($action === 'confirmer') {
		dgRepondre(202, dgConfirmer((string) ($entree['jeton'] ?? '')));
	}
	dgRepondre(400, ['ok' => false, 'message' => 'Action inconnue.']);
} catch (DossierGratuitRefus $refus) {
	dgRepondre($refus->statut, ['ok' => false, 'message' => $refus->getMessage(), 'details' => $refus->details]);
} catch (Throwable $e) {
	error_log('[dossier-gratuit] ' . $e->getMessage());
	dgRepondre(503, [
		'ok' => false,
		'message' => 'Le service est momentanément indisponible. Réessayez plus tard ou écrivez-nous à contact@laboiteabivouac.fr.',
	]);
}
