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
 *
 * PUBLIC DEPUIS LE 27/09/2026 : prix du configurateur et panier sont visibles
 * de tous, même boutique fermée — seule la COMMANDE reste verrouillée
 * (commande-creer.php, checkout.php). Plus de jeton CSRF non plus : cet
 * endpoint ne lit ni n'écrit rien de la session et n'enregistre rien. Un site
 * tiers qui l'appellerait apprendrait un prix déjà public. L'exiger obligeait
 * en revanche à poser un cookie de session à chaque visiteur du configurateur.
 */

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/panier.php';

apiExigerMethode('POST');
apiExigerBase();

$donnees = apiCorpsJson();

// Mode de livraison facultatif : le tunnel l'envoie pour afficher le total
// livraison comprise, le panier et le configurateur non.
$livraison = is_string($donnees['livraison'] ?? null) ? $donnees['livraison'] : null;

apiJson(200, panierRecalcule($donnees['lignes'] ?? [], $livraison));
