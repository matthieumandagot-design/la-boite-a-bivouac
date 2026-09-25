<?php
declare(strict_types=1);

/**
 * Connexion d'un client (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-connexion.php
 *   { "email": "…", "motdepasse": "…" }
 *   → 200 {"ok": true, "client": {…}, "csrf": "…"}
 *   → 401 {"error": "Identifiants incorrects."}
 *
 * Un seul message d'erreur pour tous les échecs : adresse inconnue, mot de passe
 * faux, compte fermé. Distinguer ces cas reviendrait à confirmer l'existence
 * d'un compte à qui poserait la question (§6.1).
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';

apiExigerMethode('POST');
apiExigerBase();

$donnees = apiCorpsJson();
$email = is_string($donnees['email'] ?? null) ? normaliserEmail($donnees['email']) : '';
$motDePasse = is_string($donnees['motdepasse'] ?? null) ? $donnees['motdepasse'] : '';

// Deux limites complémentaires. Par IP : freine celui qui essaie beaucoup de
// mots de passe depuis une machine. Par compte : protège une adresse visée
// depuis plusieurs machines, ce que la limite par IP laisserait passer.
// L'email est haché pour ne pas écrire d'adresse en clair dans rate-limits.json.
apiLimiterDebit('connexion', 15, 900);
if ($email !== '') {
	apiLimiterDebit('connexion-compte', 8, 900, substr(hash('sha256', $email), 0, 16));
}

apiExigerCsrf();

$client = $email === '' || $motDePasse === '' ? null : authentifierClient($email, $motDePasse);

if ($client === null) {
	apiJson(401, ['error' => 'Identifiants incorrects.']);
}

connecterClient((int) $client['id']);

// connecterClient() a régénéré l'identifiant de session ; le jeton CSRF est
// renvoyé pour que le front reparte sur la bonne session sans second appel.
apiJson(200, [
	'ok' => true,
	'client' => clientPublic($client),
	'csrf' => jetonCsrfClient(),
]);
