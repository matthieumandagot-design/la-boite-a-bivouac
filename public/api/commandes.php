<?php
declare(strict_types=1);

/**
 * Commandes du client connecté (docs/SPEC-module-commercial.md §6.1, §6.4).
 *
 *   GET /api/commandes.php                    → la liste, la plus récente d'abord
 *   GET /api/commandes.php?numero=BAB-2026-1  → une commande et ses lignes
 *
 * Toujours filtré sur le client de la session. Un numéro de commande est
 * devinable — BAB, l'année, un compteur — donc l'appartenance est vérifiée à
 * chaque fois, et un numéro qui n'est pas le sien répond 404 comme s'il
 * n'existait pas.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/commandes.php';

apiExigerMethode('GET');
apiExigerBase();

$client = apiExigerClient();
$clientId = (int) $client['id'];

$numero = $_GET['numero'] ?? null;

if (is_string($numero) && $numero !== '') {
	$commande = commandeParNumero($numero);
	if ($commande === null || (int) $commande['customer_id'] !== $clientId) {
		apiJson(404, ['error' => 'Commande introuvable.']);
	}

	apiJson(200, ['commande' => commandePublique($commande, commandeLignes((int) $commande['id']))]);
}

$commandes = array_map(
	static fn (array $commande): array => commandePublique($commande),
	commandesDuClient($clientId)
);

apiJson(200, ['commandes' => $commandes]);
