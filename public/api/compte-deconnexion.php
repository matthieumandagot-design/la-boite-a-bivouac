<?php
declare(strict_types=1);

/**
 * Déconnexion d'un client (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-deconnexion.php → 200 {"ok": true, "csrf": "…"}
 *
 * POST et non GET : une déconnexion change l'état de la session. En GET, une
 * simple image pointant vers cette URL déconnecterait le visiteur.
 *
 * Répond 200 même si personne n'était connecté. L'appelant voulait une session
 * fermée, elle l'est : renvoyer une erreur n'apprendrait rien d'utile et
 * compliquerait le front pour rien.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';

apiExigerMethode('POST');
apiExigerCsrf();

deconnecterClient();

// La session a changé d'identifiant et perdu son jeton : le front repart avec
// le nouveau, sinon sa prochaine requête serait refusée.
apiJson(200, ['ok' => true, 'csrf' => jetonCsrfClient()]);
