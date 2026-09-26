<?php
declare(strict_types=1);

/**
 * Renvoi du lien de vérification (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-verification-renvoyer.php → 200 {"ok": true}
 *
 * Réservé au client connecté, et pour sa propre adresse uniquement : l'adresse
 * vient de la session, jamais de la requête. Sans cela, l'endpoint deviendrait
 * une machine à envoyer des emails à n'importe qui.
 *
 * Trois envois par heure. Au-delà, ce n'est plus un lien perdu.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/verification-email.php';

apiExigerMethode('POST');
apiExigerBase();

$client = apiExigerClient();

apiLimiterDebit('verification-renvoi', 3, 3600, (string) $client['id']);
apiExigerCsrf();

if (emailVerifie($client)) {
	// Rien à faire, et le dire franchement : c'est son propre compte, aucune
	// information ne fuite.
	apiJson(200, ['ok' => true, 'deja_verifie' => true]);
}

$jeton = verifCreerJeton((int) $client['id']);

apiJson(200, ['ok' => true], static function () use ($client, $jeton): void {
	verifEnvoyerLien($client, $jeton);
});
