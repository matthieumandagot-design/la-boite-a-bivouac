<?php
declare(strict_types=1);

/**
 * Administration — Base de données du module commercial
 * (docs/SPEC-module-commercial.md §5.4).
 *
 * Applique les migrations SQL versionnées. L'opération est idempotente :
 * la relancer ne fait rien de plus, chaque fichier étant enregistré une fois
 * appliqué. Aucune migration ne part automatiquement au déploiement — c'est
 * un geste délibéré, déclenché ici.
 */

define('BAB_ADMIN', true);

require_once __DIR__ . '/../lib/admin-session.php';

demarrerSessionAdmin();
header('X-Robots-Tag: noindex, nofollow', true);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
	http_response_code(503);
	echo 'Configuration admin manquante.';
	exit;
}
require $configPath;

require_once __DIR__ . '/../api/lib/admin-auth.php';
require_once __DIR__ . '/nav.php';

// Mot de passe ou session admin ouverte ; sinon, retour au formulaire de
// connexion de /admin/ (voir api/lib/admin-auth.php).
exigerAdminPage();

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../lib/db.php';

$message = null;
$messageType = 'succes';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$jeton = $_POST['csrf_token'] ?? '';
	if (!is_string($jeton) || !hash_equals($csrfToken, $jeton)) {
		http_response_code(400);
		$message = 'Jeton de sécurité invalide. Rechargez la page et réessayez.';
		$messageType = 'erreur';
	} else {
		try {
			$resultat = bdMigrer();
			if ($resultat['erreur'] !== null) {
				http_response_code(500);
				$message = 'Migration interrompue — ' . $resultat['erreur'];
				$messageType = 'erreur';
			} elseif ($resultat['appliquees'] === []) {
				$message = 'Aucune migration à appliquer : la base est déjà à jour.';
			} else {
				$message = count($resultat['appliquees']) . ' migration(s) appliquée(s) : '
					. implode(', ', $resultat['appliquees']);
			}
		} catch (Throwable $e) {
			http_response_code(500);
			$message = 'Base inaccessible — ' . $e->getMessage();
			$messageType = 'erreur';
		}
	}
}

// L'état est lu après une éventuelle migration, pour refléter le résultat.
$pilote = extension_loaded('pdo_sqlite');
$existe = bdExiste();
$taille = bdTaille();
$disponibles = migrationsDisponibles();
$appliquees = [];
$tables = [];
$erreurLecture = null;

if ($pilote) {
	try {
		$appliquees = migrationsAppliquees(bdCommerce());
		$tables = bdTables();
	} catch (Throwable $e) {
		$erreurLecture = $e->getMessage();
	}
}

$enRetard = array_values(array_diff($disponibles, $appliquees));
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Base de données — Administration · La Boîte à Bivouac</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
		<style>
			.carte { background:#fff; border:1px solid #e3ded2; border-radius:.75rem; padding:1.5rem; margin-bottom:1.25rem; }
			.titre-carte { margin:0 0 .35rem; font-size:1.05rem; font-weight:700; }
			.aide { margin:0; color:#5d6b60; font-size:.87rem; line-height:1.6; }
			.bouton { border:0; border-radius:.5rem; padding:.7rem 1.4rem; font:inherit; font-weight:600; cursor:pointer; background:#122218; color:#f4f2ee; }
			.bouton[disabled] { opacity:.45; cursor:not-allowed; }
			table { border-collapse:collapse; width:100%; font-size:.88rem; }
			th, td { text-align:left; padding:.5rem .6rem; border-bottom:1px solid #f0ece2; }
			th { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6b60; }
			code { background:#f0ece2; padding:.1rem .35rem; border-radius:.25rem; font-size:.85em; }
		</style>
	</head>
	<body style="margin:0;font-family:'Inter',system-ui,sans-serif;background:#f4f2ee;color:#122218;">
		<div style="min-height:100vh;display:flex;">
			<?php renderAdminSidebar('/admin/base.php'); ?>

			<main style="flex:1;margin-left:220px;padding:2.5rem clamp(1.25rem,3vw,2.5rem);max-width:60rem;">
				<h1 style="margin:0 0 .35rem;font-size:1.6rem;font-weight:700;">Base de données</h1>
				<p class="aide" style="margin-bottom:2rem;">
					Stockage du module commercial : comptes clients, catalogue et commandes.
				</p>

				<?php if ($message !== null) : ?>
					<div role="status" style="margin-bottom:1.25rem;padding:.9rem 1.1rem;border-radius:.5rem;font-size:.9rem;<?= $messageType === 'erreur' ? 'background:#fdeaea;color:#8c2020;border:1px solid #f3c2c2;' : 'background:#e7f5ed;color:#17603c;border:1px solid #bde3cd;' ?>">
						<?= h($message) ?>
					</div>
				<?php endif; ?>

				<?php if (!$pilote) : ?>
					<div role="alert" style="margin-bottom:1.25rem;padding:1rem 1.15rem;border-radius:.5rem;background:#fdeaea;border:1px solid #f3c2c2;color:#8c2020;font-size:.9rem;">
						<strong>pdo_sqlite est absent de cet hébergement.</strong> Le module commercial ne peut pas
						fonctionner en l'état : il faudra basculer sur MySQL, comme le prévoit la spécification.
					</div>
				<?php endif; ?>

				<section class="carte">
					<h2 class="titre-carte">État</h2>
					<table>
						<tbody>
							<tr><th>Moteur</th><td><?= $pilote ? 'SQLite via PDO' : '—' ?></td></tr>
							<tr><th>Version SQLite</th><td><?= $pilote ? h((string) (bdCommerce()->query('SELECT sqlite_version()')->fetchColumn())) : '—' ?></td></tr>
							<tr><th>Fichier</th><td><code>data/private/commerce.sqlite</code><?= $existe ? '' : ' — pas encore créé' ?></td></tr>
							<tr><th>Taille</th><td><?= $existe ? number_format($taille / 1024, 1, ',', ' ') . ' Ko' : '—' ?></td></tr>
							<tr><th>Tables</th><td><?= $tables === [] ? 'aucune' : h(implode(', ', $tables)) ?></td></tr>
						</tbody>
					</table>
					<?php if ($erreurLecture !== null) : ?>
						<p class="aide" style="margin-top:.9rem;color:#8c2020;">Lecture impossible : <?= h($erreurLecture) ?></p>
					<?php endif; ?>
				</section>

				<section class="carte">
					<h2 class="titre-carte">Migrations</h2>
					<p class="aide" style="margin-bottom:1rem;">
						Chaque migration s'exécute dans sa propre transaction et n'est appliquée qu'une fois.
						Relancer l'opération est sans effet si tout est à jour.
					</p>

					<?php if ($disponibles === []) : ?>
						<p class="aide">Aucun fichier de migration trouvé.</p>
					<?php else : ?>
						<table style="margin-bottom:1.25rem;">
							<thead><tr><th>Fichier</th><th>État</th></tr></thead>
							<tbody>
								<?php foreach ($disponibles as $nom) : ?>
									<?php $fait = in_array($nom, $appliquees, true); ?>
									<tr>
										<td><code><?= h($nom) ?></code></td>
										<td><?= $fait ? '<span style="color:#17603c;font-weight:600;">appliquée</span>' : '<span style="color:#8a6d1f;font-weight:600;">en attente</span>' ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<form method="post">
						<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
						<button type="submit" class="bouton" <?= ($pilote && $enRetard !== []) ? '' : 'disabled' ?>>
							<?= $enRetard === [] ? 'Base à jour' : 'Appliquer ' . count($enRetard) . ' migration(s)' ?>
						</button>
					</form>
				</section>

				<section class="carte">
					<h2 class="titre-carte">Sauvegarde</h2>
					<p class="aide">
						Le fichier de base est exclu de la synchronisation FTP : un déploiement ne l'écrase pas.
						Ce n'est pas une sauvegarde pour autant — rien ne le copie ailleurs aujourd'hui.
						À traiter avant la première commande réelle.
					</p>
				</section>
			</main>
		</div>
	</body>
</html>
