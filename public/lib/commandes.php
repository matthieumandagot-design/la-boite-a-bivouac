<?php
declare(strict_types=1);

/**
 * Commandes (docs/SPEC-module-commercial.md §6.4).
 *
 * UNE COMMANDE EST UN INSTANTANÉ, PAS UNE VUE
 * Chaque ligne enregistre le libellé, le prix unitaire et la configuration tels
 * qu'ils étaient au moment de la validation. Le catalogue peut ensuite changer
 * de prix, l'article être retiré de la vente, la règle de dimensionnement être
 * corrigée : la commande, elle, ne bouge plus. C'est ce que le client a accepté
 * et ce que la comptabilité devra retrouver dans dix ans.
 *
 * Les montants ne viennent jamais du navigateur : commandeCreer() reçoit un
 * panier DÉJÀ recalculé par lib/panier.php, et refuse tout ce qui ne vient pas
 * de là.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/panier.php';
require_once __DIR__ . '/settings.php';

/**
 * Version des CGV acceptée, enregistrée avec chaque commande.
 *
 * À CHANGER DÈS QUE LES CGV SONT PUBLIÉES, puis à chaque révision. Tant que la
 * valeur commence par « brouillon- », le tunnel affiche un avertissement : on
 * ne peut pas demander à quelqu'un d'accepter un texte qui n'existe pas.
 * Publier les CGV est d'ailleurs un prérequis à l'ouverture (§9).
 */
const CGV_VERSION = 'brouillon-2026-09';

/** Les CGV sont-elles réellement publiées ? */
function cgvPubliees(): bool
{
	return !str_starts_with(CGV_VERSION, 'brouillon-');
}

/** Statuts possibles, dans l'ordre du cycle de vie, avec leur libellé. */
function commandeStatuts(): array
{
	return [
		'en_attente_paiement' => 'En attente de paiement',
		'payee' => 'Payée',
		'en_fabrication' => 'En fabrication',
		'prete' => 'Prête',
		'expediee' => 'Expédiée',
		'livree' => 'Livrée',
		'annulee' => 'Annulée',
		'remboursee' => 'Remboursée',
	];
}

function commandeStatutLibelle(string $statut): string
{
	return commandeStatuts()[$statut] ?? $statut;
}

/**
 * Prochain numéro de commande, au format BAB-AAAA-NNNN.
 *
 * Le compteur repart à 1 chaque année. Il est calculé au plus près de
 * l'insertion et l'unicité est garantie par la contrainte UNIQUE de la table,
 * pas par ce calcul : deux commandes simultanées liraient le même compteur, et
 * c'est la base qui tranche. commandeCreer() réessaie dans ce cas.
 */
function commandeProchainNumero(PDO $pdo): string
{
	$annee = gmdate('Y');
	$prefixe = 'BAB-' . $annee . '-';

	$requete = $pdo->prepare('SELECT numero FROM orders WHERE numero LIKE :prefixe ORDER BY numero DESC LIMIT 1');
	$requete->execute([':prefixe' => $prefixe . '%']);
	$dernier = $requete->fetchColumn();

	$rang = $dernier === false ? 0 : (int) substr((string) $dernier, strlen($prefixe));

	return $prefixe . str_pad((string) ($rang + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Met une adresse à plat pour l'instantané.
 *
 * Le client peut supprimer son adresse après coup, ou la modifier : la commande
 * doit garder celle qui a servi, mot pour mot.
 */
function commandeFormaterAdresse(array $adresse): string
{
	$lignes = array_filter([
		trim((string) ($adresse['ligne1'] ?? '')),
		trim((string) ($adresse['ligne2'] ?? '')),
		trim(((string) ($adresse['code_postal'] ?? '')) . ' ' . ((string) ($adresse['ville'] ?? ''))),
		strtoupper(trim((string) ($adresse['pays'] ?? 'FR'))),
		trim((string) ($adresse['telephone'] ?? '')),
	], static fn (string $ligne): bool => $ligne !== '');

	return implode("\n", $lignes);
}

/**
 * Crée une commande à partir d'un panier recalculé.
 *
 * @param array $panier sortie de panierRecalcule(), et rien d'autre
 * @param array $adresse ligne de la table addresses, appartenant au client
 * @return array{id: int, numero: string}
 *
 * @throws RuntimeException si le panier est vide ou comporte des lignes écartées
 */
function commandeCreer(int $clientId, array $panier, array $adresse): array
{
	// Les lignes écartées d'abord : quand elles l'ont toutes été, le panier est
	// techniquement vide, mais dire « votre panier est vide » à quelqu'un qui
	// vient d'y mettre trois articles retirés de la vente n'explique rien.
	if (($panier['refusees'] ?? []) !== []) {
		throw new RuntimeException('Le panier contient des lignes qui ne sont plus disponibles.');
	}
	if (($panier['lignes'] ?? []) === []) {
		throw new RuntimeException('Le panier est vide.');
	}

	$pdo = bdCommerce();
	$maintenant = gmdate('c');
	$mode = lireSettings()['payment_mode'];

	// Deux essais : le seul échec attendu est la collision de numéro entre deux
	// commandes simultanées, et le second calcul repart du numéro à jour.
	for ($essai = 0; $essai < 3; $essai++) {
		$pdo->beginTransaction();
		try {
			$numero = commandeProchainNumero($pdo);

			$pdo->prepare(
				'INSERT INTO orders (numero, customer_id, statut, total_cents, cgv_version, payment_mode, adresse_livraison, created_at)
				 VALUES (:numero, :client, :statut, :total, :cgv, :mode, :adresse, :cree)'
			)->execute([
				':numero' => $numero,
				':client' => $clientId,
				':statut' => 'en_attente_paiement',
				':total' => (int) $panier['total_cents'],
				':cgv' => CGV_VERSION,
				':mode' => $mode,
				':adresse' => commandeFormaterAdresse($adresse),
				':cree' => $maintenant,
			]);

			$commandeId = (int) $pdo->lastInsertId();

			$insertion = $pdo->prepare(
				'INSERT INTO order_items (order_id, product_id, libelle, prix_unitaire_cents, quantite, total_cents, configuration, created_at)
				 VALUES (:commande, :produit, :libelle, :prix, :quantite, :total, :config, :cree)'
			);

			foreach ($panier['lignes'] as $ligne) {
				$insertion->execute([
					':commande' => $commandeId,
					':produit' => $ligne['produit_id'] ?? null,
					// Le détail rejoint le libellé : « 153 × 113 × 90 cm » n'a de
					// sens qu'à côté du nom, et doit survivre au catalogue.
					':libelle' => trim($ligne['libelle'] . ($ligne['detail'] !== '' ? ' — ' . $ligne['detail'] : '')),
					':prix' => (int) $ligne['prix_unitaire_cents'],
					':quantite' => (int) $ligne['quantite'],
					':total' => (int) $ligne['total_cents'],
					':config' => isset($ligne['configuration'])
						? (string) json_encode($ligne['configuration'], JSON_UNESCAPED_UNICODE)
						: '',
					':cree' => $maintenant,
				]);
			}

			$pdo->commit();

			return ['id' => $commandeId, 'numero' => $numero];
		} catch (PDOException $e) {
			$pdo->rollBack();
			// 23000 : collision sur le numéro. Tout le reste est une vraie erreur.
			if ($e->getCode() !== '23000' || $essai === 2) {
				throw $e;
			}
		}
	}

	throw new RuntimeException('Numéro de commande indisponible.');
}

/** @return array<string, mixed>|null */
function commandeParId(int $id): ?array
{
	$requete = bdCommerce()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
	$requete->execute([':id' => $id]);
	$ligne = $requete->fetch();

	return $ligne === false ? null : $ligne;
}

/** @return array<string, mixed>|null */
function commandeParNumero(string $numero): ?array
{
	$requete = bdCommerce()->prepare('SELECT * FROM orders WHERE numero = :numero LIMIT 1');
	$requete->execute([':numero' => $numero]);
	$ligne = $requete->fetch();

	return $ligne === false ? null : $ligne;
}

/** Lignes d'une commande, dans l'ordre d'enregistrement. */
function commandeLignes(int $commandeId): array
{
	$requete = bdCommerce()->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
	$requete->execute([':id' => $commandeId]);

	return $requete->fetchAll();
}

/** Commandes d'un client, la plus récente d'abord. */
function commandesDuClient(int $clientId): array
{
	$requete = bdCommerce()->prepare('SELECT * FROM orders WHERE customer_id = :id ORDER BY id DESC');
	$requete->execute([':id' => $clientId]);

	return $requete->fetchAll();
}

/**
 * Toutes les commandes, filtrables. Réservé à l'administration.
 *
 * @param array{statut?: string, mode?: string} $filtres
 */
function commandesLister(array $filtres = []): array
{
	$conditions = [];
	$parametres = [];

	if (isset($filtres['statut']) && $filtres['statut'] !== '') {
		$conditions[] = 'statut = :statut';
		$parametres[':statut'] = $filtres['statut'];
	}
	if (isset($filtres['mode']) && $filtres['mode'] !== '') {
		$conditions[] = 'payment_mode = :mode';
		$parametres[':mode'] = $filtres['mode'];
	}

	$sql = 'SELECT o.*, c.email AS client_email, c.prenom AS client_prenom, c.nom AS client_nom
	        FROM orders o JOIN customers c ON c.id = o.customer_id';
	if ($conditions !== []) {
		$sql .= ' WHERE ' . implode(' AND ', $conditions);
	}
	$sql .= ' ORDER BY o.id DESC';

	$requete = bdCommerce()->prepare($sql);
	$requete->execute($parametres);

	return $requete->fetchAll();
}

/** Change le statut d'une commande. Retourne false si le statut est inconnu. */
function commandeChangerStatut(int $id, string $statut, ?string $note = null): bool
{
	if (!array_key_exists($statut, commandeStatuts())) {
		return false;
	}

	$champs = ['statut = :statut', 'updated_at = :maj'];
	$parametres = [':statut' => $statut, ':maj' => gmdate('c'), ':id' => $id];

	if ($note !== null) {
		$champs[] = 'note_interne = :note';
		$parametres[':note'] = $note;
	}
	// paid_at n'est posé qu'une fois : il date l'encaissement, pas le dernier
	// passage par ce statut.
	if ($statut === 'payee') {
		$champs[] = 'paid_at = COALESCE(paid_at, :maj)';
	}

	$requete = bdCommerce()->prepare('UPDATE orders SET ' . implode(', ', $champs) . ' WHERE id = :id');
	$requete->execute($parametres);

	return $requete->rowCount() === 1;
}

/**
 * Annule les commandes restées en attente de paiement depuis plus de 24 h (§6.4).
 *
 * Appelé à l'ouverture de l'administration plutôt que par une tâche planifiée :
 * l'hébergement mutualisé n'en offre pas de fiable, et une commande abandonnée
 * qui traîne une heure de plus ne coûte rien.
 *
 * @return int nombre de commandes annulées
 */
function commandesExpirer(): int
{
	$limite = gmdate('c', time() - 24 * 3600);

	$requete = bdCommerce()->prepare(
		"UPDATE orders SET statut = 'annulee', updated_at = :maj
		 WHERE statut = 'en_attente_paiement' AND created_at < :limite"
	);
	$requete->execute([':maj' => gmdate('c'), ':limite' => $limite]);

	return $requete->rowCount();
}

/**
 * Réduit une commande à ce qui peut sortir en JSON vers le client.
 *
 * Ni note interne, ni identifiants Stripe : ces colonnes regardent
 * l'administration, pas l'acheteur.
 */
function commandePublique(array $commande, array $lignes = []): array
{
	return [
		'numero' => (string) $commande['numero'],
		'statut' => (string) $commande['statut'],
		'statut_libelle' => commandeStatutLibelle((string) $commande['statut']),
		'total_cents' => (int) $commande['total_cents'],
		'payment_mode' => (string) $commande['payment_mode'],
		'created_at' => (string) $commande['created_at'],
		'adresse_livraison' => (string) $commande['adresse_livraison'],
		'lignes' => array_map(static fn (array $ligne): array => [
			'libelle' => (string) $ligne['libelle'],
			'quantite' => (int) $ligne['quantite'],
			'prix_unitaire_cents' => (int) $ligne['prix_unitaire_cents'],
			'total_cents' => (int) $ligne['total_cents'],
		], $lignes),
	];
}
