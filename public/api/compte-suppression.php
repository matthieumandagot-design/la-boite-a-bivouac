<?php
declare(strict_types=1);

/**
 * Suppression du compte (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-suppression.php  { "motdepasse": "…" }
 *
 * ANONYMISATION PLUTÔT QUE SUPPRESSION
 * La ligne client n'est pas effacée : les commandes la référencent, et une
 * commande doit être conservée pour les obligations comptables. On efface donc
 * tout ce qui identifie la personne — email, nom, prénom, adresses — et on pose
 * une date d'anonymisation qui interdit définitivement la connexion.
 *
 * L'adresse email est remplacée par une valeur unique et invalide plutôt que
 * vidée : la colonne porte une contrainte d'unicité, et surtout la vraie adresse
 * doit redevenir disponible pour un nouveau compte.
 *
 * Le mot de passe est exigé : c'est une action sans retour, et un navigateur
 * laissé ouvert ne doit pas suffire à la déclencher.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';

apiExigerMethode('POST');
apiExigerBase();

$client = apiExigerClient();

apiLimiterDebit('compte-suppression', 5, 3600);
apiExigerCsrf();

$donnees = apiCorpsJson();
$motDePasse = is_string($donnees['motdepasse'] ?? null) ? $donnees['motdepasse'] : '';
$confirmation = is_string($donnees['email'] ?? null) ? normaliserEmail($donnees['email']) : '';

/*
 * Un compte créé par Google ou Facebook n'a pas de mot de passe : lui en
 * demander un rendrait la suppression impossible. On confirme alors en
 * retapant son adresse email — ce qui reste une action délibérée, et reste
 * protégé par la session et le jeton CSRF.
 */
if ((int) ($client['password_defini'] ?? 1) === 1) {
	if ($motDePasse === '' || authentifierClient((string) $client['email'], $motDePasse) === null) {
		apiJson(403, ['error' => 'Mot de passe incorrect.']);
	}
} elseif ($confirmation === '' || !hash_equals(normaliserEmail((string) $client['email']), $confirmation)) {
	apiJson(403, ['error' => 'Saisissez votre adresse email pour confirmer.']);
}

$clientId = (int) $client['id'];
$pdo = bdCommerce();

// Une transaction : un compte à moitié anonymisé serait pire que pas de
// suppression du tout — email effacé mais adresses conservées, par exemple.
$pdo->beginTransaction();
try {
	$pdo->prepare('DELETE FROM addresses WHERE customer_id = :id')->execute([':id' => $clientId]);
	$pdo->prepare('DELETE FROM password_resets WHERE customer_id = :id')->execute([':id' => $clientId]);

	$pdo->prepare(
		'UPDATE customers
		 SET email = :email, prenom = \'\', nom = \'\', password_hash = :hash,
		     anonymise_a = :date, updated_at = :date
		 WHERE id = :id'
	)->execute([
		// .invalid est réservé par la RFC 2606 : aucune adresse réelle ne peut
		// entrer en conflit, et rien ne partira jamais vers ce domaine.
		':email' => 'supprime-' . $clientId . '@compte.invalid',
		// Hash d'une valeur aléatoire immédiatement jetée : aucun mot de passe
		// ne peut le vérifier, et la colonne reste non nulle.
		':hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
		':date' => gmdate('c'),
		':id' => $clientId,
	]);

	$pdo->commit();
} catch (Throwable $e) {
	$pdo->rollBack();
	apiJson(500, ['error' => 'Suppression impossible. Réessayez plus tard.']);
}

deconnecterClient();

apiJson(200, ['ok' => true, 'csrf' => jetonCsrfClient()]);
