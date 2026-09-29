<?php
declare(strict_types=1);

/**
 * Administration — Paramètres du module commercial
 * (docs/SPEC-module-commercial.md §5.2).
 *
 * C'est ici, et nulle part ailleurs, que la boutique s'ouvre ou se ferme. Le
 * réglage est lu côté serveur par commerce-guard.php : le masquage visuel du
 * front n'est qu'un confort, jamais la protection.
 */

define('BAB_ADMIN', true);

require_once __DIR__ . '/../lib/admin-session.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/stripe.php';

// Avant toute sortie : les paramètres de cookie n'ont plus d'effet une fois la
// session démarrée, et les en-têtes d'authentification ne peuvent plus partir
// une fois le corps commencé.
demarrerSessionAdmin();

header('X-Robots-Tag: noindex, nofollow', true);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
	// Généré au déploiement par scripts/write-admin-config.mjs : absent en local.
	http_response_code(503);
	echo 'Configuration admin manquante.';
	exit;
}
require $configPath;

// Réutilise la lecture d'en-tête Authorization déjà éprouvée côté API : en
// CGI/FastCGI, PHP_AUTH_USER n'est pas renseigné et il faut décoder soi-même.
require_once __DIR__ . '/../api/lib/admin-auth.php';
require_once __DIR__ . '/nav.php';

// Mot de passe ou session admin ouverte ; sinon, retour au formulaire de
// connexion de /admin/ (voir api/lib/admin-auth.php).
exigerAdminPage();

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$message = null;
$messageType = 'succes';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$jeton = $_POST['csrf_token'] ?? '';
	if (!is_string($jeton) || !hash_equals($csrfToken, $jeton)) {
		http_response_code(400);
		$message = 'Jeton de sécurité invalide. Rechargez la page et réessayez.';
		$messageType = 'erreur';
	} else {
		$actif = ($_POST['commerce_enabled'] ?? '') === '1';
		$mode = ($_POST['payment_mode'] ?? 'test') === 'live' ? 'live' : 'test';

		try {
			ecrireSettings($actif, $mode);
			$message = 'Réglages enregistrés.';
		} catch (Throwable $e) {
			http_response_code(500);
			$message = 'Enregistrement impossible : ' . $e->getMessage();
			$messageType = 'erreur';
		}
	}
}

$reglages = lireSettings();
$commerceActif = $reglages['commerce_enabled'];
$modePaiement = $reglages['payment_mode'];
$derniereMaj = $reglages['updated_at'];

$majLisible = '—';
if (is_string($derniereMaj)) {
	$d = date_create($derniereMaj);
	if ($d !== false) {
		$majLisible = $d->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H\hi');
	}
}

// Clés Stripe présentes sur le serveur, par mode. Lues depuis
// api/config-stripe.php, écrit au déploiement à partir des secrets GitHub : rien
// n'est modifiable depuis cette page, c'est un diagnostic.
$clesParMode = [
	'test' => stripeDiagnostic('test'),
	'live' => stripeDiagnostic('live'),
];
$paiementPret = stripeConfigure($modePaiement);

// Les deux points que le serveur puisse trancher seul. Les autres relèvent d'un
// jugement humain : les afficher « validés » automatiquement donnerait une
// fausse assurance. Le troisième élément est la précision affichée quand le
// point n'est pas rempli — chaque ligne a la sienne.
$checklist = [
	['CGV publiées, avec l\'exception de rétractation pour un bien sur mesure', null, ''],
	['Mentions légales complétées : SIRET, adresse, régime de TVA', null, ''],
	['Politique de confidentialité à jour : comptes, commandes, durées de conservation', null, ''],
	['Prix renseignés dans le catalogue', null, ''],
	['Clés Stripe du mode réel déposées sur le serveur', stripeConfigure('live'), 'aucune clé « live » déployée'],
	['Paiement basculé en mode réel', $modePaiement === 'live', 'actuellement en test'],
];

$avertissementTest = $commerceActif && $modePaiement === 'test';
// Boutique ouverte sans clé pour le mode en cours : les visiteurs verraient un
// tunnel de commande qui échoue au moment de payer.
$avertissementCles = $commerceActif && !$paiementPret;
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Paramètres — Administration · La Boîte à Bivouac</title>
		<link rel="preconnect" href="https://fonts.googleapis.com" />
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
		<style>
			.carte { background:#fff; border:1px solid #e3ded2; border-radius:.75rem; padding:1.5rem; margin-bottom:1.25rem; }
			.titre-carte { margin:0 0 .35rem; font-size:1.05rem; font-weight:700; }
			.aide { margin:0; color:#5d6b60; font-size:.87rem; line-height:1.6; }
			.bouton { border:0; border-radius:.5rem; padding:.7rem 1.4rem; font:inherit; font-weight:600; cursor:pointer; }
			.bouton--principal { background:#122218; color:#f4f2ee; }
			.bouton--neutre { background:#e3ded2; color:#122218; }
			.etat { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .7rem; border-radius:999px; font-size:.78rem; font-weight:700; }
			.etat--on { background:#1f7a4d; color:#fff; }
			.etat--off { background:#e3ded2; color:#4a5a4e; }
			dialog { border:0; border-radius:.75rem; padding:1.75rem; max-width:32rem; box-shadow:0 1.5rem 3rem rgba(0,0,0,.25); }
			dialog::backdrop { background:rgba(10,22,16,.55); }
			fieldset { border:1px solid #e3ded2; border-radius:.5rem; padding:1rem; margin:0 0 1rem; }
			legend { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#5d6b60; padding:0 .4rem; }
			label.choix { display:flex; gap:.6rem; align-items:flex-start; padding:.5rem 0; cursor:pointer; }
		</style>
	</head>
	<body style="margin:0;font-family:'Inter',system-ui,sans-serif;background:#f4f2ee;color:#122218;">
		<div style="min-height:100vh;display:flex;">
			<?php renderAdminSidebar('/admin/parametres.php'); ?>

			<main style="flex:1;margin-left:220px;padding:2.5rem clamp(1.25rem,3vw,2.5rem);max-width:60rem;">
				<h1 style="margin:0 0 .35rem;font-size:1.6rem;font-weight:700;">Paramètres</h1>
				<p class="aide" style="margin-bottom:2rem;">Module commercial : comptes clients, panier, commandes et paiement.</p>

				<?php if ($message !== null) : ?>
					<div role="status" style="margin-bottom:1.25rem;padding:.9rem 1.1rem;border-radius:.5rem;font-size:.9rem;<?= $messageType === 'erreur' ? 'background:#fdeaea;color:#8c2020;border:1px solid #f3c2c2;' : 'background:#e7f5ed;color:#17603c;border:1px solid #bde3cd;' ?>">
						<?= h($message) ?>
					</div>
				<?php endif; ?>

				<?php if ($avertissementTest) : ?>
					<div role="alert" style="margin-bottom:1.25rem;padding:1rem 1.15rem;border-radius:.5rem;background:#fff4e0;border:1px solid #f0cf94;color:#7a4e00;font-size:.9rem;line-height:1.6;">
						<strong>Le module est ouvert au public alors que le paiement est en mode test.</strong><br />
						Les clients peuvent commander sans être débités. Basculez le paiement en mode réel, ou refermez le module.
					</div>
				<?php endif; ?>

				<?php if ($avertissementCles) : ?>
					<div role="alert" style="margin-bottom:1.25rem;padding:1rem 1.15rem;border-radius:.5rem;background:#fdeaea;border:1px solid #f3c2c2;color:#8c2020;font-size:.9rem;line-height:1.6;">
						<strong>Le module est ouvert mais le paiement n'est pas configuré en mode <?= h($modePaiement) ?>.</strong><br />
						Les clés manquantes sont listées plus bas. En l'état, un client irait jusqu'au paiement et se verrait refuser.
					</div>
				<?php endif; ?>

				<form method="post" id="form-reglages">
					<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />

					<section class="carte">
						<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
							<div>
								<h2 class="titre-carte">Module commercial</h2>
								<p class="aide">
									Désactivé, aucun élément commercial n'apparaît sur le site et les endpoints
									correspondants répondent 404. Vous continuez à tout voir, avec un bandeau
									« Mode aperçu », tant que vous êtes connecté ici.
								</p>
							</div>
							<span class="etat <?= $commerceActif ? 'etat--on' : 'etat--off' ?>">
								<?= $commerceActif ? 'Ouvert au public' : 'Désactivé' ?>
							</span>
						</div>

						<fieldset style="margin-top:1.25rem;">
							<legend>État</legend>
							<label class="choix">
								<input type="radio" name="commerce_enabled" value="0" <?= $commerceActif ? '' : 'checked' ?> />
								<span><strong>Désactivé</strong><br /><span class="aide">Rien n'est visible ni accessible pour les visiteurs.</span></span>
							</label>
							<label class="choix">
								<input type="radio" name="commerce_enabled" value="1" <?= $commerceActif ? 'checked' : '' ?> />
								<span><strong>Ouvert au public</strong><br /><span class="aide">La boutique est accessible à tous les visiteurs.</span></span>
							</label>
						</fieldset>

						<fieldset>
							<legend>Paiement</legend>
							<label class="choix">
								<input type="radio" name="payment_mode" value="test" <?= $modePaiement === 'live' ? '' : 'checked' ?> />
								<span><strong>Test</strong><br /><span class="aide">Cartes de test Stripe, aucun débit. Réservé aux sessions admin.</span></span>
							</label>
							<label class="choix">
								<input type="radio" name="payment_mode" value="live" <?= $modePaiement === 'live' ? 'checked' : '' ?> />
								<span><strong>Réel</strong><br /><span class="aide">Les clients sont réellement débités.</span></span>
							</label>
						</fieldset>

						<p class="aide" style="margin-bottom:1.1rem;">Dernière modification : <strong><?= h($majLisible) ?></strong></p>

						<button type="button" class="bouton bouton--principal" id="ouvrir-confirmation">Enregistrer</button>
					</section>
				</form>

				<section class="carte">
					<h2 class="titre-carte">Avant d'ouvrir au public</h2>
					<p class="aide" style="margin-bottom:1rem;">
						Rappel indicatif, sans effet sur l'interrupteur. Ces points relèvent d'une
						validation humaine — le site ne peut pas les vérifier à votre place.
					</p>
					<ul style="list-style:none;margin:0;padding:0;">
						<?php foreach ($checklist as [$libelle, $etat, $note]) : ?>
							<li style="display:flex;gap:.7rem;align-items:flex-start;padding:.45rem 0;border-top:1px solid #f0ece2;font-size:.9rem;">
								<span aria-hidden="true" style="flex-shrink:0;"><?= $etat === true ? '✅' : ($etat === false ? '⬜' : '•') ?></span>
								<span>
									<?= h($libelle) ?><?php if ($etat === false && $note !== '') : ?>
										<em style="color:#8a6d1f;">(<?= h($note) ?>)</em>
									<?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>

				<section class="carte">
					<h2 class="titre-carte">Clés Stripe</h2>
					<p class="aide" style="margin-bottom:1rem;">
						Déposées comme secrets du dépôt GitHub, puis écrites sur le serveur au
						déploiement. Elles ne sont ni modifiables ni lisibles ici : seuls le préfixe
						et les quatre derniers caractères sont affichés, de quoi vérifier que la bonne
						clé est arrivée. Pour en changer, modifiez le secret dans GitHub et relancez
						un déploiement.
					</p>

					<?php foreach ($clesParMode as $mode => $cles) : ?>
						<?php $complet = $cles['secret']['presente'] && $cles['webhook']['presente']; ?>
						<div style="border:1px solid #e3ded2;border-radius:.5rem;padding:.9rem 1rem;margin-bottom:.75rem;<?= $mode === $modePaiement ? 'border-color:#122218;' : '' ?>">
							<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:.6rem;">
								<strong style="font-size:.95rem;">
									Mode <?= $mode === 'live' ? 'réel' : 'test' ?>
									<?php if ($mode === $modePaiement) : ?>
										<span class="aide" style="font-weight:400;">— mode actif</span>
									<?php endif; ?>
								</strong>
								<span class="etat <?= $complet ? 'etat--on' : 'etat--off' ?>">
									<?= $complet ? 'Paiement possible' : 'Incomplet' ?>
								</span>
							</div>
							<ul style="list-style:none;margin:0;padding:0;">
								<?php foreach ($cles as $cle) : ?>
									<li style="display:flex;gap:.7rem;align-items:baseline;padding:.3rem 0;font-size:.88rem;">
										<span aria-hidden="true" style="flex-shrink:0;"><?= $cle['presente'] ? '✅' : '⬜' ?></span>
										<span>
											<?= h($cle['libelle']) ?>
											<code style="font-size:.82rem;color:#5d6b60;">(<?= h($cle['nom']) ?>)</code>
											<?php if ($cle['empreinte'] !== null) : ?>
												<br /><span class="aide"><?= h($cle['empreinte']) ?></span>
											<?php else : ?>
												<br /><span class="aide">Absente du serveur.</span>
											<?php endif; ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>

					<p class="aide">
						Les deux clés d'un mode vont ensemble. Une clé secrète sans secret de webhook
						permettrait d'encaisser un paiement que le site ne saurait pas rattacher à une
						commande : dans ce cas, le paiement est refusé plutôt qu'accepté à moitié.
					</p>
				</section>

				<dialog id="confirmation">
					<h2 style="margin:0 0 .75rem;font-size:1.1rem;">Confirmer le changement</h2>
					<p class="aide" id="resume-confirmation" style="margin-bottom:1.5rem;"></p>
					<div style="display:flex;gap:.6rem;justify-content:flex-end;">
						<button type="button" class="bouton bouton--neutre" id="annuler">Annuler</button>
						<button type="button" class="bouton bouton--principal" id="valider">Confirmer</button>
					</div>
				</dialog>
			</main>
		</div>

		<script>
			(function () {
				const formulaire = document.getElementById("form-reglages");
				const dialogue = document.getElementById("confirmation");
				const resume = document.getElementById("resume-confirmation");

				document.getElementById("ouvrir-confirmation").addEventListener("click", () => {
					const actif = formulaire.querySelector('input[name="commerce_enabled"]:checked').value === "1";
					const mode = formulaire.querySelector('input[name="payment_mode"]:checked').value;

					let texte = actif
						? "La boutique deviendra accessible à tous les visiteurs."
						: "La boutique sera refermée : plus aucun élément commercial visible, et les endpoints répondront 404.";

					if (actif && mode === "test") {
						texte += " Le paiement reste en mode test : les clients pourront commander sans être débités.";
					}
					if (mode === "live") {
						texte += " Le paiement est en mode réel : les cartes seront débitées.";
					}

					resume.textContent = texte;
					dialogue.showModal();
				});

				document.getElementById("annuler").addEventListener("click", () => dialogue.close());
				document.getElementById("valider").addEventListener("click", () => {
					dialogue.close();
					formulaire.submit();
				});
			})();
		</script>
	</body>
</html>
