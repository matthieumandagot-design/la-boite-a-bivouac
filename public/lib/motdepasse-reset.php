<?php
declare(strict_types=1);

/**
 * Réinitialisation de mot de passe (docs/SPEC-module-commercial.md §6.1).
 *
 * Le jeton n'est JAMAIS stocké en clair. La base ne garde que son empreinte
 * SHA-256 : quelqu'un qui obtiendrait une copie du fichier SQLite ne pourrait
 * pas s'en servir pour prendre la main sur des comptes. Le jeton en clair
 * n'existe qu'une fois, dans l'email envoyé au client.
 *
 * SHA-256 sans sel ni coût, contrairement aux mots de passe : un jeton de
 * 32 octets tirés au hasard n'est pas devinable par force brute, et le hachage
 * lent n'apporterait rien ici — il ralentirait seulement la vérification.
 *
 * Validité d'une heure, usage unique (§6.1). Demander un nouveau lien annule les
 * précédents : sinon un lien oublié dans une boîte mail resterait une porte
 * ouverte pendant l'heure entière.
 */

require_once __DIR__ . '/db.php';

/** Durée de validité d'un lien, en secondes. */
const RESET_VALIDITE = 3600;

/** Empreinte stockée en base pour un jeton donné. */
function resetEmpreinte(string $jeton): string
{
	return hash('sha256', $jeton);
}

/**
 * Crée un jeton pour ce client et retourne sa valeur en clair.
 *
 * Les jetons précédents encore valables sont marqués comme utilisés : un client
 * qui redemande un lien s'attend à ce que l'ancien ne marche plus.
 */
function resetCreerJeton(int $clientId): string
{
	$pdo = bdCommerce();
	$maintenant = gmdate('c');

	$pdo->prepare('UPDATE password_resets SET used_at = :maj WHERE customer_id = :id AND used_at IS NULL')
		->execute([':maj' => $maintenant, ':id' => $clientId]);

	$jeton = bin2hex(random_bytes(32));

	$pdo->prepare(
		'INSERT INTO password_resets (customer_id, token_hash, expires_at, created_at)
		 VALUES (:id, :hash, :expire, :cree)'
	)->execute([
		':id' => $clientId,
		':hash' => resetEmpreinte($jeton),
		':expire' => gmdate('c', time() + RESET_VALIDITE),
		':cree' => $maintenant,
	]);

	return $jeton;
}

/**
 * Retrouve la demande correspondant à un jeton, si elle est encore utilisable.
 *
 * La comparaison porte sur l'empreinte, calculée à partir du jeton reçu : c'est
 * une recherche par égalité sur une valeur déjà hachée, il n'y a rien à
 * comparer en temps constant.
 *
 * @return array<string, mixed>|null
 */
function resetDemandeValide(string $jeton): ?array
{
	if ($jeton === '' || strlen($jeton) !== 64) {
		return null;
	}

	$requete = bdCommerce()->prepare(
		'SELECT * FROM password_resets WHERE token_hash = :hash AND used_at IS NULL LIMIT 1'
	);
	$requete->execute([':hash' => resetEmpreinte($jeton)]);
	$demande = $requete->fetch();

	if ($demande === false) {
		return null;
	}

	// Comparaison de chaînes ISO-8601 en UTC : l'ordre alphabétique y est
	// l'ordre chronologique, c'est pour cela que le schéma les stocke ainsi.
	if ((string) $demande['expires_at'] < gmdate('c')) {
		return null;
	}

	return $demande;
}

/** Marque une demande comme consommée. */
function resetMarquerUtilise(int $demandeId): void
{
	bdCommerce()->prepare('UPDATE password_resets SET used_at = :maj WHERE id = :id')
		->execute([':maj' => gmdate('c'), ':id' => $demandeId]);
}

/** Lien à envoyer par email. */
function resetLien(string $jeton): string
{
	return 'https://laboiteabivouac.fr/compte/?reinit=' . urlencode($jeton);
}

/**
 * Corps de l'email de réinitialisation.
 *
 * Volontairement sobre et sans image : un message de sécurité doit ressembler à
 * un message de sécurité, pas à une newsletter.
 */
function resetCorpsEmail(string $prenom, string $lien): string
{
	$bonjour = $prenom === '' ? 'Bonjour,' : 'Bonjour ' . htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') . ',';

	return <<<HTML
	<p>{$bonjour}</p>
	<p>Vous avez demandé à réinitialiser le mot de passe de votre compte La Boîte à Bivouac.</p>
	<p><a href="{$lien}">Choisir un nouveau mot de passe</a></p>
	<p>Ce lien est valable une heure et ne fonctionne qu'une fois.</p>
	<p>Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.</p>
	<p>— La Boîte à Bivouac</p>
	HTML;
}
