<?php
declare(strict_types=1);

/**
 * Comptes clients : création, authentification, lecture
 * (docs/SPEC-module-commercial.md §6.1).
 *
 * Aucune sortie HTTP ici, aucune session : ce fichier ne parle qu'à la base.
 * Les endpoints décident du code de réponse et des messages, client-session.php
 * de l'identité. Cette séparation permet de tester toute la logique de compte
 * en ligne de commande, sans serveur web.
 */

require_once __DIR__ . '/db.php';

/**
 * Longueur minimale du mot de passe (§6.1).
 *
 * Dix caractères sans autre règle de composition : imposer majuscule, chiffre
 * et caractère spécial pousse aux mots de passe courts et prévisibles, là où la
 * longueur est ce qui compte réellement.
 */
const MDP_LONGUEUR_MIN = 10;

/** Au-delà, on refuse : password_hash() tronque à 72 octets avec bcrypt. */
const MDP_LONGUEUR_MAX = 72;

/**
 * Hash de leurre, utilisé quand l'email est inconnu.
 *
 * password_verify() coûte plusieurs dizaines de millisecondes. Sans ce leurre,
 * une réponse immédiate signalerait « cet email n'existe pas » et une réponse
 * lente « il existe, mot de passe faux » : l'énumération des comptes se ferait
 * au chronomètre, quels que soient les messages affichés.
 *
 * C'est le hash d'une chaîne aléatoire de 48 caractères, jetée aussitôt : aucun
 * mot de passe ne peut le vérifier.
 */
const MDP_HASH_LEURRE = '$2y$10$Tx0/P00r.alUefhWUIzqv.1frYTIco6Jbolt/tY3NGDQ0XuI8t.X6';

/**
 * Forme canonique d'une adresse : sans espaces, en minuscules.
 *
 * SQLite compare les TEXT octet par octet — « Jean@Exemple.fr » et
 * « jean@exemple.fr » y seraient deux comptes distincts, et la contrainte
 * UNIQUE ne l'empêcherait pas. La normalisation à l'entrée est donc ce qui
 * garantit l'unicité réelle, pas l'index.
 *
 * strtolower et non mb_strtolower : mbstring n'est pas garantie sur
 * l'hébergement mutualisé (voir public/ask-question.php). La fonction ne touche
 * qu'aux octets A-Z, donc une séquence UTF-8 la traverse intacte : au pire une
 * majuscule accentuée reste telle quelle, jamais de caractère corrompu. Sans
 * conséquence ici, la partie domaine étant toujours en ASCII.
 */
function normaliserEmail(string $email): string
{
	return strtolower(trim($email));
}

/** Contrôle de forme. La seule preuve qu'une adresse existe reste un envoi. */
function emailPlausible(string $email): bool
{
	// 254 octets : c'est bien la limite de la RFC 5321 pour une adresse
	// complète, comptée en octets et non en caractères.
	return $email !== ''
		&& strlen($email) <= 254
		&& filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valide les champs d'une inscription.
 *
 * @param array<string, mixed> $donnees
 * @return array<string, string> messages par champ, vide si tout est correct
 */
function validerInscription(array $donnees): array
{
	$erreurs = [];

	$email = is_string($donnees['email'] ?? null) ? normaliserEmail($donnees['email']) : '';
	if (!emailPlausible($email)) {
		$erreurs['email'] = 'Adresse email invalide.';
	}

	$mdp = is_string($donnees['motdepasse'] ?? null) ? $donnees['motdepasse'] : '';
	// strlen et non mb_strlen : bcrypt compte des octets, et c'est cette limite
	// qui tronquerait silencieusement un mot de passe avec des accents.
	if (strlen($mdp) < MDP_LONGUEUR_MIN) {
		$erreurs['motdepasse'] = sprintf('Le mot de passe doit faire au moins %d caractères.', MDP_LONGUEUR_MIN);
	} elseif (strlen($mdp) > MDP_LONGUEUR_MAX) {
		$erreurs['motdepasse'] = sprintf('Le mot de passe ne peut pas dépasser %d caractères.', MDP_LONGUEUR_MAX);
	}

	foreach (['prenom' => 'Le prénom', 'nom' => 'Le nom'] as $champ => $libelle) {
		$valeur = is_string($donnees[$champ] ?? null) ? trim($donnees[$champ]) : '';
		if ($valeur === '') {
			$erreurs[$champ] = $libelle . ' est obligatoire.';
		} elseif (strlen($valeur) > 160) {
			// 160 octets, faute de mbstring : soit 160 caractères en ASCII, et
			// encore 80 dans le pire des cas tout en accents. Large dans les
			// deux cas pour un prénom ou un nom, l'objectif n'étant que
			// d'empêcher qu'on stocke un roman dans ce champ.
			$erreurs[$champ] = $libelle . ' est trop long.';
		}
	}

	return $erreurs;
}

/** @return array<string, mixed>|null */
function clientParEmail(string $email): ?array
{
	$requete = bdCommerce()->prepare('SELECT * FROM customers WHERE email = :email LIMIT 1');
	$requete->execute([':email' => normaliserEmail($email)]);
	$ligne = $requete->fetch();

	return $ligne === false ? null : $ligne;
}

/** @return array<string, mixed>|null */
function clientParId(int $id): ?array
{
	$requete = bdCommerce()->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
	$requete->execute([':id' => $id]);
	$ligne = $requete->fetch();

	return $ligne === false ? null : $ligne;
}

/**
 * Crée un compte. Retourne son identifiant, ou null si l'adresse est déjà prise.
 *
 * Le doublon est détecté par la contrainte UNIQUE, pas par un SELECT préalable :
 * entre la lecture et l'écriture, une seconde inscription simultanée passerait
 * au travers. On laisse la base trancher, elle seule le peut sans course.
 *
 * L'appelant ne doit pas répercuter ce null tel quel dans sa réponse : dire
 * « cette adresse est déjà utilisée » révèle l'existence du compte (§6.1).
 */
function creerClient(string $email, string $motDePasse, string $prenom, string $nom): ?int
{
	$pdo = bdCommerce();
	$maintenant = gmdate('c');

	try {
		$requete = $pdo->prepare(
			'INSERT INTO customers (email, password_hash, prenom, nom, created_at)
			 VALUES (:email, :hash, :prenom, :nom, :cree)'
		);
		$requete->execute([
			':email' => normaliserEmail($email),
			':hash' => password_hash($motDePasse, PASSWORD_DEFAULT),
			':prenom' => trim($prenom),
			':nom' => trim($nom),
			':cree' => $maintenant,
		]);
	} catch (PDOException $e) {
		// 23000 = violation de contrainte. Ici, l'unicité de l'email : c'est le
		// seul index UNIQUE de la table. Toute autre erreur remonte.
		if ($e->getCode() === '23000') {
			return null;
		}
		throw $e;
	}

	return (int) $pdo->lastInsertId();
}

/**
 * Vérifie un couple email / mot de passe.
 *
 * Retourne la ligne du client, ou null. Le temps de réponse est le même que
 * l'adresse existe ou non (voir MDP_HASH_LEURRE), et l'appelant ne doit servir
 * qu'un seul message d'erreur pour les deux cas.
 *
 * Un compte anonymisé (suppression demandée par le client) ne peut plus se
 * connecter : la ligne ne subsiste que pour rattacher ses anciennes commandes.
 *
 * @return array<string, mixed>|null
 */
function authentifierClient(string $email, string $motDePasse): ?array
{
	$client = clientParEmail($email);

	$hash = is_string($client['password_hash'] ?? null) && $client['password_hash'] !== ''
		? $client['password_hash']
		: MDP_HASH_LEURRE;

	$correct = password_verify($motDePasse, $hash);

	if ($client === null || !$correct || $client['anonymise_a'] !== null) {
		return null;
	}

	// Le coût de bcrypt par défaut peut augmenter avec les versions de PHP : on
	// en profite pour remettre le hash à niveau, tant qu'on tient le mot de
	// passe en clair. Un échec ici ne doit pas empêcher la connexion.
	if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
		try {
			changerMotDePasse((int) $client['id'], $motDePasse);
		} catch (Throwable $e) {
			// Rien à faire : le hash actuel reste valide.
		}
	}

	return $client;
}

/**
 * Remplace le mot de passe d'un compte.
 *
 * Pose aussi password_defini : un compte créé par Google ou Facebook n'en avait
 * pas, et « mot de passe oublié » est précisément le chemin qui lui permet d'en
 * obtenir un — il peut ensuite se connecter sans le fournisseur.
 */
function changerMotDePasse(int $clientId, string $motDePasse): void
{
	$requete = bdCommerce()->prepare(
		'UPDATE customers SET password_hash = :hash, password_defini = 1, updated_at = :maj WHERE id = :id'
	);
	$requete->execute([
		':hash' => password_hash($motDePasse, PASSWORD_DEFAULT),
		':maj' => gmdate('c'),
		':id' => $clientId,
	]);
}

/**
 * Réduit une ligne client à ce qui peut sortir en JSON.
 *
 * Liste blanche et non liste noire : le jour où une colonne sensible est
 * ajoutée au schéma, elle ne fuite pas d'elle-même.
 *
 * @param array<string, mixed> $client
 * @return array{id: int, email: string, prenom: string, nom: string}
 */
function clientPublic(array $client): array
{
	return [
		'id' => (int) $client['id'],
		'email' => (string) $client['email'],
		'prenom' => (string) $client['prenom'],
		'nom' => (string) $client['nom'],
	];
}

/** Clients affichés par page dans l'administration. */
const CLIENTS_PAR_PAGE = 50;

/**
 * Liste et recherche des clients, pour l'administration (§6.6, « P1 »).
 *
 * La recherche porte sur l'email, le prénom et le nom. LIKE de SQLite ignore
 * la casse pour les lettres ASCII, ce qui suffit : les emails sont déjà
 * normalisés en minuscules, et « dupont » trouve « Dupont ». Les jokers % et _
 * saisis sont échappés : chercher « jean_dupont » ne doit pas trouver
 * « jean-dupont ».
 *
 * Les comptes supprimés restent listés, repérables par anonymise_a : leurs
 * commandes existent toujours et il faut pouvoir les retrouver.
 *
 * @return array{clients: list<array<string, mixed>>, total: int}
 */
function clientsRechercher(string $recherche = '', int $page = 1): array
{
	$recherche = trim($recherche);
	$condition = '';
	$parametres = [];

	if ($recherche !== '') {
		$motif = '%' . strtr($recherche, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
		$condition = " WHERE c.email LIKE :q ESCAPE '\\' OR c.prenom LIKE :q ESCAPE '\\' OR c.nom LIKE :q ESCAPE '\\'
		               OR (c.prenom || ' ' || c.nom) LIKE :q ESCAPE '\\'";
		$parametres[':q'] = $motif;
	}

	$pdo = bdCommerce();

	$compte = $pdo->prepare('SELECT COUNT(*) FROM customers c' . $condition);
	$compte->execute($parametres);
	$total = (int) $compte->fetchColumn();

	// Le chiffre d'affaires ne compte que les commandes réellement encaissées
	// en mode réel : une commande de test ou abandonnée n'est pas une vente.
	$requete = $pdo->prepare(
		"SELECT c.id, c.email, c.prenom, c.nom, c.created_at, c.email_verifie_a, c.anonymise_a,
		        COUNT(o.id) AS nb_commandes,
		        COALESCE(SUM(CASE WHEN o.payment_mode = 'live' AND o.paid_at IS NOT NULL
		                          AND o.statut NOT IN ('annulee', 'remboursee')
		                     THEN o.total_cents END), 0) AS encaisse_cents
		 FROM customers c LEFT JOIN orders o ON o.customer_id = c.id"
		. $condition .
		' GROUP BY c.id ORDER BY c.id DESC LIMIT :limite OFFSET :decalage'
	);
	foreach ($parametres as $cle => $valeur) {
		$requete->bindValue($cle, $valeur);
	}
	$requete->bindValue(':limite', CLIENTS_PAR_PAGE, PDO::PARAM_INT);
	$requete->bindValue(':decalage', (max(1, $page) - 1) * CLIENTS_PAR_PAGE, PDO::PARAM_INT);
	$requete->execute();

	return ['clients' => $requete->fetchAll(), 'total' => $total];
}

/** Adresses d'un client, pour l'administration. */
function clientAdresses(int $clientId): array
{
	$requete = bdCommerce()->prepare('SELECT * FROM addresses WHERE customer_id = :id ORDER BY id');
	$requete->execute([':id' => $clientId]);

	return $requete->fetchAll();
}
