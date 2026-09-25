<?php
declare(strict_types=1);

/**
 * Catalogue produits (docs/SPEC-module-commercial.md §6.2).
 *
 * Deux types : « boite », le produit sur mesure, et « accessoire », un article
 * standard vendu à l'unité. La Boîte n'a pas de prix en base — il dépend des
 * dimensions et se calcule dans pricing.php ; sa fiche sert au libellé et à la
 * description.
 *
 * Deux drapeaux, qui ne disent pas la même chose :
 *   visible : l'article apparaît dans la boutique ;
 *   actif   : l'article peut être commandé.
 * Un article retiré de la vente garde actif = 0 pour toujours : les commandes
 * passées le référencent, et order_items conserve de toute façon un instantané
 * de son libellé et de son prix.
 */

require_once __DIR__ . '/db.php';

/** Longueur maximale d'un nom, en octets (mbstring n'est pas garantie). */
const PRODUIT_NOM_MAX = 160;

/**
 * Liste du catalogue.
 *
 * @param bool $vendablesSeulement ne garde que ce qui est visible ET actif
 * @return array<int, array<string, mixed>>
 */
function produitsLister(bool $vendablesSeulement = false, ?string $type = null): array
{
	$conditions = [];
	$parametres = [];

	if ($vendablesSeulement) {
		$conditions[] = 'visible = 1 AND actif = 1';
	}
	if ($type !== null) {
		$conditions[] = 'type = :type';
		$parametres[':type'] = $type;
	}

	$sql = 'SELECT * FROM products';
	if ($conditions !== []) {
		$sql .= ' WHERE ' . implode(' AND ', $conditions);
	}
	// Les accessoires d'abord dans l'ordre alphabétique : le catalogue est petit
	// et un ordre stable vaut mieux qu'un ordre d'insertion.
	$sql .= ' ORDER BY type, nom COLLATE NOCASE';

	$requete = bdCommerce()->prepare($sql);
	$requete->execute($parametres);

	return $requete->fetchAll();
}

/** @return array<string, mixed>|null */
function produitParId(int $id): ?array
{
	$requete = bdCommerce()->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
	$requete->execute([':id' => $id]);
	$ligne = $requete->fetch();

	return $ligne === false ? null : $ligne;
}

/**
 * Valide les champs d'un produit.
 *
 * @param array<string, mixed> $donnees
 * @return array<string, string>
 */
function validerProduit(array $donnees): array
{
	$erreurs = [];

	$type = $donnees['type'] ?? '';
	if ($type !== 'boite' && $type !== 'accessoire') {
		$erreurs['type'] = 'Type inconnu.';
	}

	$nom = is_string($donnees['nom'] ?? null) ? trim($donnees['nom']) : '';
	if ($nom === '') {
		$erreurs['nom'] = 'Le nom est obligatoire.';
	} elseif (strlen($nom) > PRODUIT_NOM_MAX) {
		$erreurs['nom'] = 'Le nom est trop long.';
	}

	$prix = $donnees['prix_cents'] ?? null;
	if (!is_int($prix) || $prix < 0) {
		$erreurs['prix_cents'] = 'Le prix doit être un montant positif.';
	} elseif ($prix > 100000000) {
		// 1 000 000 € : personne ne vend ça ici, c'est une faute de frappe.
		$erreurs['prix_cents'] = 'Le prix dépasse le plafond de saisie.';
	}

	return $erreurs;
}

/** Crée un produit et retourne son identifiant. */
function produitCreer(array $donnees): int
{
	$pdo = bdCommerce();
	$requete = $pdo->prepare(
		'INSERT INTO products (type, nom, description, prix_cents, photo, visible, actif, created_at)
		 VALUES (:type, :nom, :description, :prix, :photo, :visible, 1, :cree)'
	);
	$requete->execute([
		':type' => $donnees['type'],
		':nom' => trim((string) $donnees['nom']),
		':description' => trim((string) ($donnees['description'] ?? '')),
		':prix' => (int) $donnees['prix_cents'],
		':photo' => (string) ($donnees['photo'] ?? ''),
		':visible' => !empty($donnees['visible']) ? 1 : 0,
		':cree' => gmdate('c'),
	]);

	return (int) $pdo->lastInsertId();
}

/** Met à jour un produit. La photo n'est remplacée que si une nouvelle est donnée. */
function produitModifier(int $id, array $donnees): void
{
	$champs = [
		'type = :type',
		'nom = :nom',
		'description = :description',
		'prix_cents = :prix',
		'visible = :visible',
		'updated_at = :maj',
	];
	$parametres = [
		':type' => $donnees['type'],
		':nom' => trim((string) $donnees['nom']),
		':description' => trim((string) ($donnees['description'] ?? '')),
		':prix' => (int) $donnees['prix_cents'],
		':visible' => !empty($donnees['visible']) ? 1 : 0,
		':maj' => gmdate('c'),
		':id' => $id,
	];

	if (isset($donnees['photo']) && $donnees['photo'] !== '') {
		$champs[] = 'photo = :photo';
		$parametres[':photo'] = (string) $donnees['photo'];
	}

	$requete = bdCommerce()->prepare('UPDATE products SET ' . implode(', ', $champs) . ' WHERE id = :id');
	$requete->execute($parametres);
}

/** Retire ou remet un produit à la vente. */
function produitDefinirActif(int $id, bool $actif): void
{
	$requete = bdCommerce()->prepare('UPDATE products SET actif = :actif, updated_at = :maj WHERE id = :id');
	$requete->execute([':actif' => $actif ? 1 : 0, ':maj' => gmdate('c'), ':id' => $id]);
}

/**
 * Réduit un produit à ce qui peut sortir en JSON.
 *
 * Liste blanche : une colonne ajoutée au schéma ne fuite pas d'elle-même.
 *
 * @param array<string, mixed> $produit
 */
function produitPublic(array $produit): array
{
	return [
		'id' => (int) $produit['id'],
		'type' => (string) $produit['type'],
		'nom' => (string) $produit['nom'],
		'description' => (string) $produit['description'],
		'prix_cents' => (int) $produit['prix_cents'],
		'photo' => (string) $produit['photo'],
	];
}
