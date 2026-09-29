<?php
declare(strict_types=1);

/**
 * Carnet d'adresses du client (docs/SPEC-module-commercial.md §6.1).
 *
 *   GET    /api/compte-adresses.php          → ses adresses
 *   POST   /api/compte-adresses.php          → en ajoute une
 *   PUT    /api/compte-adresses.php?id=12    → en modifie une (27/09/2026)
 *   DELETE /api/compte-adresses.php?id=12    → en supprime une
 *
 * Modifier une adresse ne touche pas aux commandes passées : chacune garde
 * l'adresse recopiée à plat au moment de la validation.
 *
 * Toutes les requêtes portent sur le client connecté, et sur lui seul :
 * l'identifiant du propriétaire vient de la session, jamais de la requête. Une
 * suppression vérifie donc l'appartenance dans la clause WHERE plutôt qu'après
 * coup — il n'y a pas de fenêtre où l'on pourrait toucher l'adresse d'un autre.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';

apiExigerBase();
$client = apiExigerClient();
$clientId = (int) $client['id'];
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Colonnes exposées : liste blanche, comme partout ailleurs. */
function adressePublique(array $ligne): array
{
	return [
		'id' => (int) $ligne['id'],
		'libelle' => (string) $ligne['libelle'],
		'ligne1' => (string) $ligne['ligne1'],
		'ligne2' => (string) $ligne['ligne2'],
		'code_postal' => (string) $ligne['code_postal'],
		'ville' => (string) $ligne['ville'],
		'pays' => (string) $ligne['pays'],
		'telephone' => (string) $ligne['telephone'],
	];
}

if ($methode === 'GET') {
	$requete = bdCommerce()->prepare('SELECT * FROM addresses WHERE customer_id = :id ORDER BY id');
	$requete->execute([':id' => $clientId]);

	apiJson(200, ['adresses' => array_map('adressePublique', $requete->fetchAll())]);
}

apiExigerCsrf();

if ($methode === 'DELETE') {
	$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
	if ($id === false || $id === null || $id <= 0) {
		apiJson(400, ['error' => 'Adresse non précisée.']);
	}

	$requete = bdCommerce()->prepare('DELETE FROM addresses WHERE id = :id AND customer_id = :client');
	$requete->execute([':id' => $id, ':client' => $clientId]);

	// Aucune ligne supprimée : soit elle n'existe pas, soit elle est à
	// quelqu'un d'autre. Même réponse dans les deux cas, on ne renseigne pas.
	apiJson($requete->rowCount() === 1 ? 200 : 404, $requete->rowCount() === 1
		? ['ok' => true]
		: ['error' => 'Adresse introuvable.']);
}

if ($methode !== 'POST' && $methode !== 'PUT') {
	header('Allow: GET, POST, PUT, DELETE');
	apiJson(405, ['error' => 'Méthode non autorisée.']);
}

$donnees = apiCorpsJson();
$champ = static fn (string $nom): string => is_string($donnees[$nom] ?? null) ? trim($donnees[$nom]) : '';

$erreurs = [];
foreach (['ligne1' => 'L\'adresse', 'code_postal' => 'Le code postal', 'ville' => 'La ville'] as $nom => $libelle) {
	if ($champ($nom) === '') {
		$erreurs[$nom] = $libelle . ' est obligatoire.';
	}
}
foreach (['libelle', 'ligne1', 'ligne2', 'ville', 'telephone'] as $nom) {
	if (strlen($champ($nom)) > 160) {
		$erreurs[$nom] = 'Cette valeur est trop longue.';
	}
}
if ($champ('code_postal') !== '' && strlen($champ('code_postal')) > 16) {
	$erreurs['code_postal'] = 'Code postal invalide.';
}

// Pays sur deux lettres, faute de quoi la France par défaut : la V1 ne livre
// pas à l'international (§3), ce champ n'est là que pour ne pas avoir à migrer
// le schéma le jour où ce sera le cas.
$pays = strtoupper($champ('pays'));
if (!preg_match('/^[A-Z]{2}$/', $pays)) {
	$pays = 'FR';
}

if ($erreurs !== []) {
	apiJson(422, ['error' => 'Adresse incomplète.', 'champs' => $erreurs]);
}

$valeurs = [
	':client' => $clientId,
	':libelle' => $champ('libelle'),
	':ligne1' => $champ('ligne1'),
	':ligne2' => $champ('ligne2'),
	':cp' => $champ('code_postal'),
	':ville' => $champ('ville'),
	':pays' => $pays,
	':tel' => $champ('telephone'),
];

if ($methode === 'PUT') {
	$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
	if ($id === false || $id === null || $id <= 0) {
		apiJson(400, ['error' => 'Adresse non précisée.']);
	}

	// Appartenance vérifiée dans le WHERE, comme pour la suppression.
	$requete = bdCommerce()->prepare(
		'UPDATE addresses
		 SET libelle = :libelle, ligne1 = :ligne1, ligne2 = :ligne2, code_postal = :cp,
		     ville = :ville, pays = :pays, telephone = :tel
		 WHERE id = :id AND customer_id = :client'
	);
	$requete->execute($valeurs + [':id' => $id]);

	// rowCount compte les lignes réellement changées : une adresse renvoyée
	// telle quelle donnerait 0. On vérifie donc l'existence à part.
	$existe = bdCommerce()->prepare('SELECT 1 FROM addresses WHERE id = :id AND customer_id = :client');
	$existe->execute([':id' => $id, ':client' => $clientId]);

	$trouvee = $existe->fetchColumn() !== false;

	apiJson($trouvee ? 200 : 404, $trouvee ? ['ok' => true, 'id' => $id] : ['error' => 'Adresse introuvable.']);
}

$requete = bdCommerce()->prepare(
	'INSERT INTO addresses (customer_id, libelle, ligne1, ligne2, code_postal, ville, pays, telephone, created_at)
	 VALUES (:client, :libelle, :ligne1, :ligne2, :cp, :ville, :pays, :tel, :cree)'
);
$requete->execute($valeurs + [':cree' => gmdate('c')]);

apiJson(201, ['ok' => true, 'id' => (int) bdCommerce()->lastInsertId()]);
