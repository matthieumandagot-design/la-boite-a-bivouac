<?php
declare(strict_types=1);

/**
 * Plomberie commune aux endpoints du module commercial
 * (docs/SPEC-module-commercial.md §6).
 *
 * Objectif : que chaque endpoint se lise en quelques lignes et ne puisse pas
 * oublier une protection. Tout ce qui est répétitif et facile à omettre —
 * en-têtes, méthode autorisée, jeton CSRF, état de la base, authentification —
 * est ici, sous une forme qui interrompt la requête plutôt que de retourner un
 * booléen qu'on pourrait négliger de tester.
 *
 * Le verrou du module lui-même (commerce-guard.php) reste à appeler
 * explicitement en tête de chaque fichier : c'est la protection principale, elle
 * doit rester visible à la lecture et non cachée dans un utilitaire.
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/clients.php';
require_once __DIR__ . '/../../lib/client-session.php';
require_once __DIR__ . '/../../lib/rate-limit.php';

/**
 * Réponse JSON, puis fin de la requête.
 *
 * $apres permet d'exécuter un traitement lent APRÈS avoir rendu la main au
 * navigateur — un envoi d'email, typiquement. Ce n'est pas qu'un confort : si
 * l'inscription attendait le serveur SMTP, une adresse déjà prise répondrait
 * nettement plus vite qu'une adresse libre, et l'énumération des comptes se
 * ferait au chronomètre malgré des réponses identiques.
 *
 * fastcgi_finish_request() n'existe que sous PHP-FPM/FastCGI, ce qu'utilise
 * l'hébergement OVH. Ailleurs, le traitement se fait avant la fin de la
 * requête : plus lent, mais jamais omis.
 */
function apiJson(int $code, array $donnees, ?callable $apres = null): void
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	// Rien de ce que renvoient ces endpoints n'est mutualisable entre deux
	// visiteurs : une réponse mise en cache par un intermédiaire pourrait servir
	// le compte d'un client à un autre.
	header('Cache-Control: no-store, max-age=0');
	header('X-Robots-Tag: noindex, nofollow', true);

	echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	if ($apres !== null) {
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
		try {
			$apres();
		} catch (Throwable $e) {
			// La réponse est déjà partie : il ne reste qu'à laisser une trace.
			error_log('[apiJson] traitement différé en échec : ' . $e->getMessage());
		}
	}

	exit;
}

/** Refuse tout ce qui n'est pas la méthode attendue. */
function apiExigerMethode(string $methode): void
{
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === $methode) {
		return;
	}

	header('Allow: ' . $methode);
	apiJson(405, ['error' => 'Méthode non autorisée.']);
}

/**
 * Corps JSON de la requête.
 *
 * JSON et non formulaire : un formulaire HTML classique peut être soumis depuis
 * n'importe quel site, alors qu'une requête avec Content-Type application/json
 * est soumise à la politique d'origine du navigateur. C'est une protection CSRF
 * de plus, en amont du jeton.
 *
 * @return array<string, mixed>
 */
function apiCorpsJson(): array
{
	$brut = file_get_contents('php://input');
	if ($brut === false || $brut === '') {
		return [];
	}

	// 64 Ko : très au-dessus de ce qu'un formulaire de compte ou un panier
	// légitime peut peser, très en dessous de ce qui ferait souffrir le serveur.
	if (strlen($brut) > 65536) {
		apiJson(413, ['error' => 'Requête trop volumineuse.']);
	}

	$donnees = json_decode($brut, true);
	if (!is_array($donnees)) {
		apiJson(400, ['error' => 'Corps de requête invalide.']);
	}

	return $donnees;
}

/**
 * Exige un jeton CSRF valide, lu dans l'en-tête X-CSRF-Token.
 *
 * Dans un en-tête et non dans le corps : un en-tête personnalisé ne peut pas
 * être posé par un formulaire HTML d'un autre site, seulement par du JavaScript
 * soumis au contrôle d'origine.
 */
function apiExigerCsrf(): void
{
	$jeton = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
	if (jetonCsrfValide(is_string($jeton) ? $jeton : null)) {
		return;
	}

	apiJson(403, ['error' => 'Jeton de sécurité invalide. Rechargez la page.']);
}

/**
 * Exige une base initialisée.
 *
 * bdCommerce() créerait le fichier SQLite à la connexion : il faut donc vérifier
 * son existence AVANT d'ouvrir quoi que ce soit, sinon on se retrouve avec une
 * base vide et des erreurs « no such table » plus loin.
 *
 * Le 503 s'adresse à l'administrateur, seul à pouvoir voir ces pages tant que le
 * module dort : il dit quoi faire plutôt que d'échouer obscurément.
 */
function apiExigerBase(): void
{
	if (!bdExiste()) {
		apiJson(503, ['error' => 'Base de données non initialisée. Appliquez les migrations depuis /admin/base.php.']);
	}

	try {
		bdCommerce()->query('SELECT 1 FROM customers LIMIT 1');
	} catch (PDOException $e) {
		apiJson(503, ['error' => 'Migrations non appliquées. Ouvrez /admin/base.php.']);
	}
}

/**
 * Exige un client connecté et le retourne.
 *
 * Relit la ligne en base à chaque appel plutôt que de faire confiance à la
 * session : un compte anonymisé entre-temps ne doit plus rien pouvoir faire.
 *
 * @return array<string, mixed>
 */
function apiExigerClient(): array
{
	$id = clientConnecteId();
	$client = $id === null ? null : clientParId($id);

	if ($client === null || $client['anonymise_a'] !== null) {
		// La session porte un compte qui n'existe plus ou qui est fermé : on la
		// referme, sinon le client reste « connecté » à rien à chaque requête.
		if ($id !== null) {
			deconnecterClient();
		}
		apiJson(401, ['error' => 'Connexion requise.']);
	}

	return $client;
}

/**
 * Applique une limite de débit, ou coupe court avec un 429.
 *
 * La clé inclut l'IP : voir rate-limit.php, qui s'appuie sur REMOTE_ADDR et non
 * sur un en-tête que le client pourrait changer à chaque requête.
 */
function apiLimiterDebit(string $action, int $maxTentatives, int $fenetreSecondes, string $complement = ''): void
{
	$cle = $action . ':' . clientIp() . ($complement === '' ? '' : ':' . $complement);

	if (isRateLimited($cle, $maxTentatives, $fenetreSecondes)) {
		apiJson(429, ['error' => 'Trop de tentatives. Réessayez dans quelques minutes.']);
	}
}
