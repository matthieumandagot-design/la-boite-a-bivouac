<?php
declare(strict_types=1);

/**
 * Confirmation d'une adresse email (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-verifier-email.php  { "jeton": "…" }
 *   → 200 {"ok": true}
 *   → 400 {"error": "Lien expiré ou déjà utilisé."}
 *
 * Pas d'authentification : le lien arrive par email, souvent ouvert dans un
 * autre navigateur que celui de l'inscription. Exiger une session ferait
 * échouer le cas le plus courant. Le jeton lui-même fait preuve — 32 octets
 * aléatoires, adressés à cette boîte mail et à elle seule.
 *
 * POST et non GET malgré le lien : un GET serait déclenché par n'importe quel
 * aperçu de lien ou anti-virus de messagerie, qui consommerait le jeton avant
 * même que le client ne clique. La page /compte lit le paramètre d'URL et
 * poste ; les robots de messagerie n'exécutent pas ce JavaScript.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/verification-email.php';

apiExigerMethode('POST');
apiExigerBase();

// Un jeton ne se devine pas, mais rien n'empêche d'essayer en boucle.
apiLimiterDebit('verification-email', 20, 3600);
apiExigerCsrf();

$donnees = apiCorpsJson();
$jeton = is_string($donnees['jeton'] ?? null) ? trim($donnees['jeton']) : '';

$demande = $jeton === '' ? null : verifDemandeValide($jeton);
if ($demande === null) {
	apiJson(400, ['error' => 'Lien expiré ou déjà utilisé. Connectez-vous pour en demander un nouveau.']);
}

$client = clientParId((int) $demande['customer_id']);
if ($client === null || $client['anonymise_a'] !== null) {
	apiJson(400, ['error' => 'Lien expiré ou déjà utilisé. Connectez-vous pour en demander un nouveau.']);
}

try {
	verifConfirmer((int) $demande['id'], (int) $client['id']);
} catch (Throwable $e) {
	error_log('[compte-verifier-email] ' . $e->getMessage());
	apiJson(500, ['error' => 'Confirmation impossible. Réessayez dans un instant.']);
}

apiJson(200, ['ok' => true]);
