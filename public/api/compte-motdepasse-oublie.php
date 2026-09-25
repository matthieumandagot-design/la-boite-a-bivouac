<?php
declare(strict_types=1);

/**
 * Demande de réinitialisation de mot de passe
 * (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-motdepasse-oublie.php  { "email": "…" }
 *   → 200 {"ok": true}   — toujours, quoi qu'il arrive
 *
 * La réponse est la même que l'adresse existe ou non : c'est le seul moyen de ne
 * pas transformer ce formulaire en annuaire des clients. Elle est aussi la même
 * si l'envoi de l'email échoue — l'échec part dans le journal d'erreurs du
 * serveur, pas dans la réponse, qui trahirait l'existence du compte.
 *
 * Limitation de débit serrée : trois demandes par heure et par adresse. Au-delà,
 * ce n'est plus un oubli, c'est du harcèlement par email.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/motdepasse-reset.php';

apiExigerMethode('POST');
apiExigerBase();

$donnees = apiCorpsJson();
$email = is_string($donnees['email'] ?? null) ? normaliserEmail($donnees['email']) : '';

apiLimiterDebit('motdepasse-oublie', 10, 3600);
if ($email !== '') {
	apiLimiterDebit('motdepasse-oublie-compte', 3, 3600, substr(hash('sha256', $email), 0, 16));
}

apiExigerCsrf();

$client = $email === '' ? null : clientParEmail($email);

if ($client !== null && $client['anonymise_a'] === null) {
	try {
		$jeton = resetCreerJeton((int) $client['id']);

		// config.php n'existe pas en développement local : l'envoi est alors
		// impossible, et c'est normal. Le jeton reste créé, ce qui permet de
		// tester le reste du parcours sans envoyer d'email.
		$config = __DIR__ . '/config.php';
		if (is_file($config)) {
			require_once $config;
			require_once __DIR__ . '/lib/smtp.php';

			$smtp = new SimpleSmtp(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
			$smtp->connect();
			$smtp->sendMail(
				(string) $client['email'],
				'Réinitialisation de votre mot de passe — La Boîte à Bivouac',
				resetCorpsEmail((string) $client['prenom'], resetLien($jeton))
			);
			$smtp->quit();
		}
	} catch (Throwable $e) {
		// Ni le motif ni l'existence du compte ne doivent transparaître.
		error_log('[compte-motdepasse-oublie] envoi impossible : ' . $e->getMessage());
	}
}

apiJson(200, ['ok' => true]);
