<?php
declare(strict_types=1);

/**
 * Administration — Commandes (docs/SPEC-module-commercial.md §6.6).
 *
 * Liste filtrable par statut et par mode, détail d'une commande, changement de
 * statut avec note interne, export CSV.
 *
 * Le badge « TEST » n'est pas décoratif : une commande passée en mode test n'a
 * donné lieu à aucun encaissement. La confondre avec une vraie vente fausserait
 * la comptabilité, et le badge est donc affiché partout où un montant apparaît.
 */

define('BAB_ADMIN', true);

require_once __DIR__ . '/../lib/admin-session.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/commandes.php';
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/clients.php';
require_once __DIR__ . '/../lib/emails-commande.php';

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

$baseUtilisable = bdExiste();
if ($baseUtilisable) {
	try {
		bdCommerce()->query('SELECT 1 FROM orders LIMIT 1');
	} catch (PDOException $e) {
		$baseUtilisable = false;
	}
}

$message = null;
$messageType = 'succes';

if ($baseUtilisable) {
	// Nettoyage des paniers abandonnés (§6.4). Fait ici faute de tâche planifiée
	// fiable sur l'hébergement mutualisé : l'administration est ouverte assez
	// souvent, et une commande abandonnée qui traîne une heure ne coûte rien.
	$expirees = commandesExpirer();
	if ($expirees > 0) {
		$message = $expirees . ' commande(s) restée(s) sans paiement depuis plus de 24 h ont été annulées.';
	}
}

if ($baseUtilisable && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$jeton = $_POST['csrf_token'] ?? '';
	if (!is_string($jeton) || !hash_equals($csrfToken, $jeton)) {
		http_response_code(400);
		$message = 'Jeton de sécurité invalide. Rechargez la page et réessayez.';
		$messageType = 'erreur';
	} else {
		$id = (int) ($_POST['id'] ?? 0);
		$statut = (string) ($_POST['statut'] ?? '');
		$note = trim((string) ($_POST['note'] ?? ''));
		$prevenir = ($_POST['prevenir'] ?? '') === '1';

		$avant = commandeParId($id);

		if ($avant === null || !commandeChangerStatut($id, $statut, $note)) {
			$message = 'Statut inconnu ou commande introuvable.';
			$messageType = 'erreur';
		} else {
			$message = 'Commande mise à jour.';

			// Email au client (§6.6) seulement si le statut a réellement changé :
			// corriger une note interne ne doit rien envoyer. La case permet de
			// rattraper une erreur de manipulation sans alerter le client.
			if ($avant['statut'] !== $statut && statutANotifier($statut)) {
				$client = clientParId((int) $avant['customer_id']);
				if (!$prevenir || $client === null || $client['anonymise_a'] !== null) {
					$message .= ' Client non prévenu.';
				} elseif (envoyerEmailChangementStatut(commandeParId($id), $client)) {
					$message .= ' Email envoyé à ' . $client['email'] . '.';
				} else {
					$message .= ' L\'email au client n\'a pas pu partir : prévenez-le directement.';
					$messageType = 'erreur';
				}
			}
		}
	}
}

$filtres = [
	'statut' => (string) ($_GET['statut'] ?? ''),
	'mode' => (string) ($_GET['mode'] ?? ''),
];

// Export CSV : envoyé avant tout HTML, le fichier ne doit contenir que les
// données.
if ($baseUtilisable && ($_GET['export'] ?? '') === 'csv') {
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="commandes-' . gmdate('Y-m-d') . '.csv"');

	$sortie = fopen('php://output', 'w');
	// BOM UTF-8 : sans lui, Excel ouvre le fichier en ANSI et massacre les
	// accents.
	fwrite($sortie, "\u{feff}");
	fputcsv($sortie, ['Numero', 'Date', 'Client', 'Email', 'Statut', 'Mode', 'Total EUR'], ';');

	foreach (commandesLister($filtres) as $commande) {
		fputcsv($sortie, [
			$commande['numero'],
			$commande['created_at'],
			trim($commande['client_prenom'] . ' ' . $commande['client_nom']),
			$commande['client_email'],
			commandeStatutLibelle((string) $commande['statut']),
			$commande['payment_mode'],
			number_format((int) $commande['total_cents'] / 100, 2, ',', ''),
		], ';');
	}

	fclose($sortie);
	exit;
}

$detail = null;
$lignes = [];
$idDetail = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($baseUtilisable && $idDetail) {
	$detail = commandeParId((int) $idDetail);
	if ($detail !== null) {
		$lignes = commandeLignes((int) $detail['id']);
	}
}

$commandes = $baseUtilisable ? commandesLister($filtres) : [];

/** Date ISO-8601 UTC rendue lisible en heure de Paris. */
function dateLisible(?string $iso): string
{
	if (!is_string($iso) || $iso === '') {
		return '—';
	}
	$date = date_create($iso);

	return $date === false ? '—' : $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H\hi');
}
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Commandes — Administration · La Boîte à Bivouac</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
		<style>
			.carte { background:#fff; border:1px solid #e3ded2; border-radius:.75rem; padding:1.5rem; margin-bottom:1.25rem; }
			.titre-carte { margin:0 0 .35rem; font-size:1.05rem; font-weight:700; }
			.aide { margin:0; color:#5d6b60; font-size:.87rem; line-height:1.6; }
			.bouton { border:0; border-radius:.5rem; padding:.6rem 1.2rem; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
			.bouton--principal { background:#122218; color:#f4f2ee; }
			.bouton--neutre { background:#e3ded2; color:#122218; }
			.etiquette { display:inline-flex; align-items:center; padding:.2rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
			.etiquette--attente { background:#fff4e0; color:#7a4e00; }
			.etiquette--payee { background:#1f7a4d; color:#fff; }
			.etiquette--fin { background:#e3ded2; color:#4a5a4e; }
			.etiquette--test { background:#8c2020; color:#fff; }
			table { width:100%; border-collapse:collapse; font-size:.9rem; }
			th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6b60; padding:.4rem .5rem; }
			td { padding:.6rem .5rem; border-top:1px solid #f0ece2; vertical-align:top; }
			select, textarea, input[type=text] { padding:.5rem .65rem; border:1px solid #c9c4b6; border-radius:.5rem; font:inherit; background:#fff; }
		</style>
	</head>
	<body style="margin:0;font-family:'Inter',system-ui,sans-serif;background:#f4f2ee;color:#122218;">
		<div style="min-height:100vh;display:flex;">
			<?php renderAdminSidebar('/admin/commandes.php'); ?>

			<main style="flex:1;margin-left:220px;padding:2.5rem clamp(1.25rem,3vw,2.5rem);max-width:70rem;">
				<h1 style="margin:0 0 .35rem;font-size:1.6rem;font-weight:700;">Commandes</h1>
				<p class="aide" style="margin-bottom:2rem;">
					Les commandes en mode test ne correspondent à aucun encaissement : elles portent
					un badge rouge. Une commande sans paiement depuis plus de 24 h est annulée
					automatiquement.
				</p>

				<?php if ($message !== null) : ?>
					<div role="status" style="margin-bottom:1.25rem;padding:.9rem 1.1rem;border-radius:.5rem;font-size:.9rem;<?= $messageType === 'erreur' ? 'background:#fdeaea;color:#8c2020;border:1px solid #f3c2c2;' : 'background:#e7f5ed;color:#17603c;border:1px solid #bde3cd;' ?>">
						<?= h($message) ?>
					</div>
				<?php endif; ?>

				<?php if (!$baseUtilisable) : ?>
					<section class="carte">
						<h2 class="titre-carte">Base de données non initialisée</h2>
						<p class="aide">Ouvrez <a href="/admin/base.php">Base de données</a> et appliquez les migrations.</p>
					</section>
				<?php else : ?>

				<?php if ($detail !== null) : ?>
					<section class="carte">
						<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
							<div>
								<h2 class="titre-carte"><?= h((string) $detail['numero']) ?></h2>
								<p class="aide">Passée le <?= h(dateLisible((string) $detail['created_at'])) ?></p>
								<p class="aide"><a href="/admin/clients.php?id=<?= (int) $detail['customer_id'] ?>">Fiche client</a></p>
							</div>
							<div style="text-align:right;">
								<span class="etiquette <?= $detail['statut'] === 'payee' ? 'etiquette--payee' : ($detail['statut'] === 'en_attente_paiement' ? 'etiquette--attente' : 'etiquette--fin') ?>">
									<?= h(commandeStatutLibelle((string) $detail['statut'])) ?>
								</span>
								<?php if ($detail['payment_mode'] === 'test') : ?>
									<span class="etiquette etiquette--test" style="margin-left:.35rem;">TEST</span>
								<?php endif; ?>
							</div>
						</div>

						<div style="display:grid;gap:1.5rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));margin-top:1.5rem;">
							<div>
								<p style="font-weight:700;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:#5d6b60;margin:0 0 .4rem;">Livraison</p>
								<p class="aide" style="white-space:pre-line;"><?= h((string) $detail['adresse_livraison']) ?></p>
							</div>
							<div>
								<p style="font-weight:700;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:#5d6b60;margin:0 0 .4rem;">Conditions acceptées</p>
								<p class="aide"><?= h((string) $detail['cgv_version']) ?></p>
								<?php if ($detail['stripe_payment_intent_id'] !== null && $detail['stripe_payment_intent_id'] !== '') : ?>
									<p class="aide" style="margin-top:.4rem;">Paiement Stripe : <code><?= h((string) $detail['stripe_payment_intent_id']) ?></code></p>
								<?php endif; ?>
							</div>
						</div>

						<table style="margin-top:1.5rem;">
							<thead><tr><th>Article</th><th>Qté</th><th style="text-align:right;">Total</th></tr></thead>
							<tbody>
								<?php foreach ($lignes as $ligne) : ?>
									<tr>
										<td><?= h((string) $ligne['libelle']) ?></td>
										<td><?= (int) $ligne['quantite'] ?></td>
										<td style="text-align:right;"><?= h(formaterCents((int) $ligne['total_cents'])) ?></td>
									</tr>
								<?php endforeach; ?>
								<tr>
									<td colspan="2" style="font-weight:700;">Total</td>
									<td style="text-align:right;font-weight:700;"><?= h(formaterCents((int) $detail['total_cents'])) ?></td>
								</tr>
							</tbody>
						</table>

						<form method="post" style="margin-top:1.5rem;display:grid;gap:.75rem;max-width:32rem;">
							<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
							<input type="hidden" name="id" value="<?= (int) $detail['id'] ?>" />
							<label>
								<span style="display:block;font-weight:600;font-size:.85rem;margin-bottom:.3rem;">Statut</span>
								<select name="statut">
									<?php foreach (commandeStatuts() as $valeur => $libelle) : ?>
										<option value="<?= h($valeur) ?>" <?= $detail['statut'] === $valeur ? 'selected' : '' ?>><?= h($libelle) ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.9rem;">
								<input type="checkbox" name="prevenir" value="1" checked style="margin-top:.2rem;" />
								<span>
									Prévenir le client par email si le statut change
									<span class="aide" style="display:block;">
										Pour En fabrication, Prête, Expédiée, Livrée, Annulée et Remboursée.
										Décochez pour corriger une erreur de saisie sans rien envoyer.
									</span>
								</span>
							</label>
							<label>
								<span style="display:block;font-weight:600;font-size:.85rem;margin-bottom:.3rem;">Note interne</span>
								<textarea name="note" rows="2" style="width:100%;"><?= h((string) $detail['note_interne']) ?></textarea>
								<span class="aide">Jamais affichée au client. Sert par exemple à consigner un remboursement fait depuis Stripe.</span>
							</label>
							<div style="display:flex;gap:.6rem;">
								<button type="submit" class="bouton bouton--principal">Enregistrer</button>
								<a href="/admin/commandes.php" class="bouton bouton--neutre">Retour à la liste</a>
							</div>
						</form>
					</section>
				<?php endif; ?>

				<section class="carte">
					<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
						<h2 class="titre-carte">Liste (<?= count($commandes) ?>)</h2>
						<form method="get" style="display:flex;gap:.5rem;align-items:center;">
							<select name="statut">
								<option value="">Tous les statuts</option>
								<?php foreach (commandeStatuts() as $valeur => $libelle) : ?>
									<option value="<?= h($valeur) ?>" <?= $filtres['statut'] === $valeur ? 'selected' : '' ?>><?= h($libelle) ?></option>
								<?php endforeach; ?>
							</select>
							<select name="mode">
								<option value="">Tous les modes</option>
								<option value="test" <?= $filtres['mode'] === 'test' ? 'selected' : '' ?>>Test</option>
								<option value="live" <?= $filtres['mode'] === 'live' ? 'selected' : '' ?>>Réel</option>
							</select>
							<button type="submit" class="bouton bouton--neutre" style="padding:.45rem .9rem;">Filtrer</button>
							<a class="bouton bouton--neutre" style="padding:.45rem .9rem;"
								href="?export=csv&amp;statut=<?= urlencode($filtres['statut']) ?>&amp;mode=<?= urlencode($filtres['mode']) ?>">Export CSV</a>
						</form>
					</div>

					<?php if ($commandes === []) : ?>
						<p class="aide" style="margin-top:1rem;">Aucune commande.</p>
					<?php else : ?>
						<table style="margin-top:1rem;">
							<thead><tr><th>Numéro</th><th>Date</th><th>Client</th><th>Total</th><th>Statut</th><th></th></tr></thead>
							<tbody>
								<?php foreach ($commandes as $commande) : ?>
									<tr>
										<td>
											<strong><?= h((string) $commande['numero']) ?></strong>
											<?php if ($commande['payment_mode'] === 'test') : ?>
												<span class="etiquette etiquette--test" style="margin-left:.35rem;">TEST</span>
											<?php endif; ?>
										</td>
										<td class="aide"><?= h(dateLisible((string) $commande['created_at'])) ?></td>
										<td>
											<?= h(trim($commande['client_prenom'] . ' ' . $commande['client_nom'])) ?>
											<br /><span class="aide"><?= h((string) $commande['client_email']) ?></span>
										</td>
										<td><?= h(formaterCents((int) $commande['total_cents'])) ?></td>
										<td>
											<span class="etiquette <?= $commande['statut'] === 'payee' ? 'etiquette--payee' : ($commande['statut'] === 'en_attente_paiement' ? 'etiquette--attente' : 'etiquette--fin') ?>">
												<?= h(commandeStatutLibelle((string) $commande['statut'])) ?>
											</span>
										</td>
										<td style="text-align:right;">
											<a href="?id=<?= (int) $commande['id'] ?>" class="bouton bouton--neutre" style="padding:.35rem .75rem;font-size:.85rem;">Ouvrir</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</section>

				<?php endif; ?>
			</main>
		</div>
	</body>
</html>
