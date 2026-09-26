<?php
declare(strict_types=1);

/**
 * Administration — Clients (docs/SPEC-module-commercial.md §6.6, « P1 »).
 *
 * Liste et recherche des comptes clients, fiche d'un client avec ses adresses
 * et ses commandes. Lecture seule : rien ici ne modifie un compte. Changer
 * l'email ou supprimer un compte à la place du client relève de lui, depuis son
 * espace, et une modification faite par l'atelier n'aurait aucune trace de
 * consentement.
 */

define('BAB_ADMIN', true);

require_once __DIR__ . '/../lib/admin-session.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/clients.php';
require_once __DIR__ . '/../lib/commandes.php';
require_once __DIR__ . '/../lib/pricing.php';

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

$baseUtilisable = bdExiste();
if ($baseUtilisable) {
	try {
		bdCommerce()->query('SELECT email_verifie_a FROM customers LIMIT 1');
	} catch (PDOException $e) {
		$baseUtilisable = false;
	}
}

$recherche = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$resultat = $baseUtilisable ? clientsRechercher($recherche, $page) : ['clients' => [], 'total' => 0];
$pages = max(1, (int) ceil($resultat['total'] / CLIENTS_PAR_PAGE));

$fiche = null;
$adresses = [];
$commandes = [];
$idFiche = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($baseUtilisable && $idFiche) {
	$fiche = clientParId((int) $idFiche);
	if ($fiche !== null) {
		$adresses = clientAdresses((int) $fiche['id']);
		$commandes = commandesDuClient((int) $fiche['id']);
	}
}

/** Date ISO-8601 UTC rendue lisible en heure de Paris. */
function dateCourte(?string $iso): string
{
	if (!is_string($iso) || $iso === '') {
		return '—';
	}
	$date = date_create($iso);

	return $date === false ? '—' : $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y');
}

/** Lien vers une page de la liste, en gardant la recherche. */
function lienPage(string $recherche, int $page): string
{
	return '?' . http_build_query(array_filter(['q' => $recherche, 'page' => $page > 1 ? $page : null]));
}
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Clients — Administration · La Boîte à Bivouac</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
		<style>
			.carte { background:#fff; border:1px solid #e3ded2; border-radius:.75rem; padding:1.5rem; margin-bottom:1.25rem; }
			.titre-carte { margin:0 0 .35rem; font-size:1.05rem; font-weight:700; }
			.aide { margin:0; color:#5d6b60; font-size:.87rem; line-height:1.6; }
			.bouton { border:0; border-radius:.5rem; padding:.6rem 1.2rem; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
			.bouton--neutre { background:#e3ded2; color:#122218; }
			.etiquette { display:inline-flex; align-items:center; padding:.2rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
			.etiquette--ok { background:#e7f5ed; color:#17603c; }
			.etiquette--attente { background:#fff4e0; color:#7a4e00; }
			.etiquette--fin { background:#e3ded2; color:#4a5a4e; }
			.etiquette--test { background:#8c2020; color:#fff; }
			.intertitre { font-weight:700; font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6b60; margin:0 0 .4rem; }
			table { width:100%; border-collapse:collapse; font-size:.9rem; }
			th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6b60; padding:.4rem .5rem; }
			td { padding:.6rem .5rem; border-top:1px solid #f0ece2; vertical-align:top; }
			input[type=search] { padding:.5rem .65rem; border:1px solid #c9c4b6; border-radius:.5rem; font:inherit; background:#fff; min-width:16rem; }
		</style>
	</head>
	<body style="margin:0;font-family:'Inter',system-ui,sans-serif;background:#f4f2ee;color:#122218;">
		<div style="min-height:100vh;display:flex;">
			<?php renderAdminSidebar('/admin/clients.php'); ?>

			<main style="flex:1;margin-left:220px;padding:2.5rem clamp(1.25rem,3vw,2.5rem);max-width:70rem;">
				<h1 style="margin:0 0 .35rem;font-size:1.6rem;font-weight:700;">Clients</h1>
				<p class="aide" style="margin-bottom:2rem;">
					Comptes créés sur le site. Consultation seulement : un client modifie ou
					supprime son compte lui-même, depuis son espace. « Encaissé » ne compte que
					les paiements réels, hors commandes annulées ou remboursées.
				</p>

				<?php if (!$baseUtilisable) : ?>
					<section class="carte">
						<h2 class="titre-carte">Base de données non initialisée</h2>
						<p class="aide">Ouvrez <a href="/admin/base.php">Base de données</a> et appliquez les migrations.</p>
					</section>
				<?php else : ?>

				<?php if ($idFiche && $fiche === null) : ?>
					<section class="carte"><p class="aide">Client introuvable.</p></section>
				<?php endif; ?>

				<?php if ($fiche !== null) : ?>
					<section class="carte">
						<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
							<div>
								<?php if ($fiche['anonymise_a'] !== null) : ?>
									<h2 class="titre-carte">Compte supprimé</h2>
									<p class="aide">Supprimé le <?= h(dateCourte((string) $fiche['anonymise_a'])) ?> à la demande du client. Ses commandes sont conservées pour la comptabilité.</p>
								<?php else : ?>
									<h2 class="titre-carte"><?= h(trim($fiche['prenom'] . ' ' . $fiche['nom'])) ?></h2>
									<p class="aide"><a href="mailto:<?= h((string) $fiche['email']) ?>"><?= h((string) $fiche['email']) ?></a></p>
								<?php endif; ?>
								<p class="aide">Inscrit le <?= h(dateCourte((string) $fiche['created_at'])) ?></p>
							</div>
							<?php if ($fiche['anonymise_a'] === null) : ?>
								<?php if (is_string($fiche['email_verifie_a']) && $fiche['email_verifie_a'] !== '') : ?>
									<span class="etiquette etiquette--ok">Email vérifié</span>
								<?php else : ?>
									<span class="etiquette etiquette--attente">Email non vérifié</span>
								<?php endif; ?>
							<?php endif; ?>
						</div>

						<div style="margin-top:1.5rem;">
							<p class="intertitre">Adresses</p>
							<?php if ($adresses === []) : ?>
								<p class="aide">Aucune adresse enregistrée.</p>
							<?php else : ?>
								<div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));">
									<?php foreach ($adresses as $adresse) : ?>
										<p class="aide" style="white-space:pre-line;"><?php if ($adresse['libelle'] !== '') : ?><strong><?= h((string) $adresse['libelle']) ?></strong>
<?php endif; ?><?= h(commandeFormaterAdresse($adresse)) ?></p>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>

						<div style="margin-top:1.5rem;">
							<p class="intertitre">Commandes (<?= count($commandes) ?>)</p>
							<?php if ($commandes === []) : ?>
								<p class="aide">Aucune commande.</p>
							<?php else : ?>
								<table>
									<thead><tr><th>Numéro</th><th>Date</th><th>Total</th><th>Statut</th><th></th></tr></thead>
									<tbody>
										<?php foreach ($commandes as $commande) : ?>
											<tr>
												<td>
													<strong><?= h((string) $commande['numero']) ?></strong>
													<?php if ($commande['payment_mode'] === 'test') : ?>
														<span class="etiquette etiquette--test" style="margin-left:.35rem;">TEST</span>
													<?php endif; ?>
												</td>
												<td class="aide"><?= h(dateCourte((string) $commande['created_at'])) ?></td>
												<td><?= h(formaterCents((int) $commande['total_cents'])) ?></td>
												<td><span class="etiquette etiquette--fin"><?= h(commandeStatutLibelle((string) $commande['statut'])) ?></span></td>
												<td style="text-align:right;">
													<a href="/admin/commandes.php?id=<?= (int) $commande['id'] ?>" class="bouton bouton--neutre" style="padding:.35rem .75rem;font-size:.85rem;">Ouvrir</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>

						<p style="margin:1.5rem 0 0;">
							<a href="<?= h(lienPage($recherche, $page)) ?>" class="bouton bouton--neutre">Retour à la liste</a>
						</p>
					</section>
				<?php endif; ?>

				<section class="carte">
					<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
						<h2 class="titre-carte">
							<?= $recherche === '' ? 'Tous les clients' : 'Résultats' ?> (<?= (int) $resultat['total'] ?>)
						</h2>
						<form method="get" role="search" style="display:flex;gap:.5rem;align-items:center;">
							<label for="q" style="position:absolute;left:-9999px;">Rechercher un client</label>
							<input type="search" id="q" name="q" value="<?= h($recherche) ?>" placeholder="Email, prénom ou nom" />
							<button type="submit" class="bouton bouton--neutre" style="padding:.45rem .9rem;">Rechercher</button>
							<?php if ($recherche !== '') : ?>
								<a href="/admin/clients.php" class="bouton bouton--neutre" style="padding:.45rem .9rem;">Effacer</a>
							<?php endif; ?>
						</form>
					</div>

					<?php if ($resultat['clients'] === []) : ?>
						<p class="aide" style="margin-top:1rem;"><?= $recherche === '' ? 'Aucun client pour le moment.' : 'Aucun client ne correspond à cette recherche.' ?></p>
					<?php else : ?>
						<table style="margin-top:1rem;">
							<thead><tr><th>Client</th><th>Inscrit le</th><th>Email</th><th>Commandes</th><th>Encaissé</th><th></th></tr></thead>
							<tbody>
								<?php foreach ($resultat['clients'] as $client) : ?>
									<tr>
										<?php if ($client['anonymise_a'] !== null) : ?>
											<td><em class="aide">Compte supprimé</em></td>
										<?php else : ?>
											<td>
												<?= h(trim($client['prenom'] . ' ' . $client['nom'])) ?>
												<br /><span class="aide"><?= h((string) $client['email']) ?></span>
											</td>
										<?php endif; ?>
										<td class="aide"><?= h(dateCourte((string) $client['created_at'])) ?></td>
										<td>
											<?php if ($client['anonymise_a'] !== null) : ?>
												<span class="aide">—</span>
											<?php elseif (is_string($client['email_verifie_a']) && $client['email_verifie_a'] !== '') : ?>
												<span class="etiquette etiquette--ok">Vérifié</span>
											<?php else : ?>
												<span class="etiquette etiquette--attente">Non vérifié</span>
											<?php endif; ?>
										</td>
										<td><?= (int) $client['nb_commandes'] ?></td>
										<td><?= h(formaterCents((int) $client['encaisse_cents'])) ?></td>
										<td style="text-align:right;">
											<a href="<?= h(lienPage($recherche, $page) . ($recherche === '' && $page === 1 ? '' : '&') . 'id=' . (int) $client['id']) ?>" class="bouton bouton--neutre" style="padding:.35rem .75rem;font-size:.85rem;">Ouvrir</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<?php if ($pages > 1) : ?>
							<nav aria-label="Pagination" style="display:flex;gap:.5rem;align-items:center;margin-top:1rem;">
								<?php if ($page > 1) : ?>
									<a href="<?= h(lienPage($recherche, $page - 1)) ?>" class="bouton bouton--neutre" style="padding:.35rem .75rem;">← Précédents</a>
								<?php endif; ?>
								<span class="aide">Page <?= $page ?> sur <?= $pages ?></span>
								<?php if ($page < $pages) : ?>
									<a href="<?= h(lienPage($recherche, $page + 1)) ?>" class="bouton bouton--neutre" style="padding:.35rem .75rem;">Suivants →</a>
								<?php endif; ?>
							</nav>
						<?php endif; ?>
					<?php endif; ?>
				</section>

				<?php endif; ?>
			</main>
		</div>
	</body>
</html>
