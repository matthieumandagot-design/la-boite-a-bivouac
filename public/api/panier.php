<?php
declare(strict_types=1);

/**
 * Recalcul du panier (docs/SPEC-module-commercial.md §6.3).
 *
 *   POST /api/panier.php
 *   { "lignes": [ {"type":"boite","remorque":"Erdé|Classic 163","hauteur":90},
 *                 {"type":"accessoire","produit_id":3,"quantite":2} ] }
 *
 *   → { "lignes": [...], "refusees": [...], "total_cents": …, "mention": "À DÉFINIR" }
 *
 * Le navigateur envoie son panier, le serveur renvoie ce qu'il coûte vraiment.
 * Aucun prix transmis n'est lu — voir l'en-tête de lib/panier.php. C'est ce
 * point que vérifie le critère d'acceptation « un prix modifié dans le
 * localStorage est ignoré » (§10).
 *
 * POST et non GET : le panier peut être long, et une URL n'est pas un endroit
 * pour la configuration d'une commande. Sans effet de bord malgré tout — rien
 * n'est enregistré ici.
 *
 * Pas d'authentification : on remplit son panier avant d'avoir un compte (§6.3).
 * Le jeton CSRF reste exigé, comme sur tout POST du module.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/panier.php';

apiExigerMethode('POST');
apiExigerBase();
apiExigerCsrf();

$donnees = apiCorpsJson();

// Mode de livraison facultatif : le tunnel l'envoie pour afficher le total
// livraison comprise, le panier et le configurateur non.
$livraison = is_string($donnees['livraison'] ?? null) ? $donnees['livraison'] : null;

apiJson(200, panierRecalcule($donnees['lignes'] ?? [], $livraison));
