<?php
declare(strict_types=1);

/**
 * Administration — Catalogue produits
 * (docs/SPEC-module-commercial.md §6.2).
 *
 * Deux types d'articles. « accessoire » : un article standard, vendu au prix
 * saisi ici. « boite » : le produit sur mesure, dont le prix ne vient PAS de
 * cette page mais de data/pricing.json, puisqu'il dépend des dimensions. La
 * fiche d'une boîte ne sert donc qu'au libellé, à la description et à la photo ;
 * le champ prix reste à zéro et le formulaire le dit.
 *
 * Retirer de la vente plutôt que supprimer : les commandes passées référencent
 * l'article, et order_items en garde de toute façon un instantané. Aucune
 * suppression n'est donc proposée ici.
 */

define('BAB_ADMIN', true);

require_once __DIR__ . '/../lib/admin-session.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/produits.php';
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/images.php';

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

$uploadsDir = __DIR__ . '/../uploads/produits';
$uploadsUrl = '/uploads/produits';

$message = null;
$messageType = 'succes';
$baseUtilisable = bdExiste();

if ($baseUtilisable) {
	try {
		bdCommerce()->query('SELECT 1 FROM products LIMIT 1');
	} catch (PDOException $e) {
		$baseUtilisable = false;
	}
}

/**
 * Lit un prix saisi en euros et le rend en centimes.
 *
 * Accepte « 49,90 », « 49.90 », « 49 ». Refuse tout le reste plutôt que de
 * deviner : un prix mal interprété se retrouve sur une facture.
 */
function prixSaisiEnCents(string $saisie): ?int
{
	$saisie = str_replace([' ', "\u{a0}", "\u{202f}", '€'], '', trim($saisie));
	$saisie = str_replace(',', '.', $saisie);

	if ($saisie === '') {
		return 0;
	}
	if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $saisie) !== 1) {
		return null;
	}

	return (int) round(((float) $saisie) * 100);
}

if ($baseUtilisable && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	$jeton = $_POST['csrf_token'] ?? '';
	if (!is_string($jeton) || !hash_equals($csrfToken, $jeton)) {
		http_response_code(400);
		$message = 'Jeton de sécurité invalide. Rechargez la page et réessayez.';
		$messageType = 'erreur';
	} else {
		$action = $_POST['action'] ?? '';

		if ($action === 'basculer') {
			$id = (int) ($_POST['id'] ?? 0);
			$produit = produitParId($id);
			if ($produit === null) {
				$message = 'Article introuvable.';
				$messageType = 'erreur';
			} else {
				$nouvel = (int) $produit['actif'] !== 1;
				produitDefinirActif($id, $nouvel);
				$message = $nouvel ? 'Article remis en vente.' : 'Article retiré de la vente.';
			}
		} elseif ($action === 'enregistrer') {
			$id = (int) ($_POST['id'] ?? 0);
			$type = $_POST['type'] ?? 'accessoire';
			$prix = prixSaisiEnCents((string) ($_POST['prix'] ?? ''));

			$donnees = [
				'type' => $type,
				'nom' => (string) ($_POST['nom'] ?? ''),
				'description' => (string) ($_POST['description'] ?? ''),
				// Le prix d'une boîte ne se saisit pas : il est calculé.
				'prix_cents' => $type === 'boite' ? 0 : $prix,
				'visible' => !empty($_POST['visible']),
			];

			$erreurs = $prix === null && $type !== 'boite'
				? ['prix_cents' => 'Prix illisible. Exemple : 49,90']
				: validerProduit($donnees);

			$photo = imgRanger($_FILES['photo'] ?? [], $uploadsDir, $uploadsUrl);
			if ($photo['erreur'] !== null) {
				$erreurs['photo'] = $photo['erreur'];
			}

			if ($erreurs !== []) {
				$message = 'Formulaire incomplet : ' . implode(' ', $erreurs);
				$messageType = 'erreur';
			} else {
				if ($photo['url'] !== null) {
					$donnees['photo'] = $photo['url'];
				}

				if ($id > 0 && produitParId($id) !== null) {
					$ancien = produitParId($id);
					produitModifier($id, $donnees);
					// L'ancienne photo n'est effacée qu'une fois la nouvelle
					// enregistrée : en cas d'échec, la fiche garde la sienne.
					if ($photo['url'] !== null) {
						imgSupprimer((string) $ancien['photo'], $uploadsDir, $uploadsUrl);
					}
					$message = 'Article mis à jour.';
				} else {
					produitCreer($donnees);
					$message = 'Article créé.';
				}
			}
		}
	}
}

$produits = $baseUtilisable ? produitsLister() : [];
$edition = null;
$idEdition = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($baseUtilisable && $idEdition) {
	$edition = produitParId((int) $idEdition);
}
?>
<!doctype html>
<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="robots" content="noindex, nofollow" />
		<title>Produits — Administration · La Boîte à Bivouac</title>
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
			.etiquette--on { background:#1f7a4d; color:#fff; }
			.etiquette--off { background:#e3ded2; color:#4a5a4e; }
			label span.intitule { display:block; font-weight:600; font-size:.85rem; margin-bottom:.3rem; }
			input[type=text], input[type=file], textarea, select { width:100%; padding:.55rem .7rem; border:1px solid #c9c4b6; border-radius:.5rem; font:inherit; background:#fff; }
			table { width:100%; border-collapse:collapse; font-size:.9rem; }
			th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#5d6b60; padding:.4rem .5rem; }
			td { padding:.6rem .5rem; border-top:1px solid #f0ece2; vertical-align:middle; }
		</style>
	</head>
	<body style="margin:0;font-family:'Inter',system-ui,sans-serif;background:#f4f2ee;color:#122218;">
		<div style="min-height:100vh;display:flex;">
			<?php renderAdminSidebar('/admin/produits.php'); ?>

			<main style="flex:1;margin-left:220px;padding:2.5rem clamp(1.25rem,3vw,2.5rem);max-width:64rem;">
				<h1 style="margin:0 0 .35rem;font-size:1.6rem;font-weight:700;">Produits</h1>
				<p class="aide" style="margin-bottom:2rem;">
					Catalogue du module commercial. Les accessoires sont vendus au prix saisi ici ;
					le prix de la Boîte, lui, dépend des dimensions et vient de <code>data/pricing.json</code>.
				</p>

				<?php if (pricingProvisoire()) : ?>
					<div role="status" style="margin-bottom:1.25rem;padding:.9rem 1.1rem;border-radius:.5rem;background:#fff4e0;border:1px solid #f0cf94;color:#7a4e00;font-size:.9rem;line-height:1.6;">
						<strong>Prix de la Boîte : <?= h(pricingMention()) ?>.</strong><br />
						Les montants du configurateur sont factices tant que le modèle de prix n'est pas arrêté
						(question 1 de la spécification). Ils sont signalés comme tels sur tout le site.
					</div>
				<?php endif; ?>

				<?php if ($message !== null) : ?>
					<div role="status" style="margin-bottom:1.25rem;padding:.9rem 1.1rem;border-radius:.5rem;font-size:.9rem;<?= $messageType === 'erreur' ? 'background:#fdeaea;color:#8c2020;border:1px solid #f3c2c2;' : 'background:#e7f5ed;color:#17603c;border:1px solid #bde3cd;' ?>">
						<?= h($message) ?>
					</div>
				<?php endif; ?>

				<?php if (!$baseUtilisable) : ?>
					<section class="carte">
						<h2 class="titre-carte">Base de données non initialisée</h2>
						<p class="aide">
							Le catalogue a besoin des tables du module commercial.
							Ouvrez <a href="/admin/base.php">Base de données</a> et appliquez les migrations.
						</p>
					</section>
				<?php else : ?>

				<section class="carte">
					<h2 class="titre-carte"><?= $edition ? 'Modifier l\'article' : 'Ajouter un article' ?></h2>
					<form method="post" enctype="multipart/form-data" style="margin-top:1rem;display:grid;gap:1rem;">
						<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
						<input type="hidden" name="action" value="enregistrer" />
						<input type="hidden" name="id" value="<?= $edition ? (int) $edition['id'] : 0 ?>" />

						<div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));">
							<label>
								<span class="intitule">Type</span>
								<select name="type" id="champ-type">
									<option value="accessoire" <?= ($edition['type'] ?? 'accessoire') === 'accessoire' ? 'selected' : '' ?>>Accessoire</option>
									<option value="boite" <?= ($edition['type'] ?? '') === 'boite' ? 'selected' : '' ?>>Boîte (sur mesure)</option>
								</select>
							</label>

							<label>
								<span class="intitule">Nom</span>
								<input type="text" name="nom" required maxlength="160" value="<?= h((string) ($edition['nom'] ?? '')) ?>" />
							</label>

							<label id="bloc-prix">
								<span class="intitule">Prix TTC (€)</span>
								<input type="text" name="prix" inputmode="decimal" placeholder="49,90"
									value="<?= $edition && (int) $edition['prix_cents'] > 0 ? h(number_format((int) $edition['prix_cents'] / 100, 2, ',', '')) : '' ?>" />
								<span class="aide" id="aide-prix">Laissé vide : gratuit. Le prix d'une Boîte est calculé, ce champ est alors ignoré.</span>
							</label>
						</div>

						<label>
							<span class="intitule">Description</span>
							<textarea name="description" rows="3" maxlength="2000"><?= h((string) ($edition['description'] ?? '')) ?></textarea>
						</label>

						<label>
							<span class="intitule">Photo</span>
							<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" />
							<span class="aide">
								JPEG, PNG ou WebP, 8 Mo maximum. Redimensionnée à 1600 px et recompressée.
								<?= $edition && $edition['photo'] !== '' ? 'Laisser vide conserve la photo actuelle.' : '' ?>
							</span>
						</label>

						<label style="display:flex;gap:.6rem;align-items:center;">
							<input type="checkbox" name="visible" value="1" <?= !$edition || (int) $edition['visible'] === 1 ? 'checked' : '' ?> style="width:auto;" />
							<span>Visible dans la boutique</span>
						</label>

						<div style="display:flex;gap:.6rem;">
							<button type="submit" class="bouton bouton--principal"><?= $edition ? 'Enregistrer' : 'Ajouter' ?></button>
							<?php if ($edition) : ?>
								<a href="/admin/produits.php" class="bouton bouton--neutre">Annuler</a>
							<?php endif; ?>
						</div>
					</form>
				</section>

				<section class="carte">
					<h2 class="titre-carte">Catalogue (<?= count($produits) ?>)</h2>
					<?php if ($produits === []) : ?>
						<p class="aide" style="margin-top:.75rem;">Aucun article pour l'instant.</p>
					<?php else : ?>
						<table style="margin-top:1rem;">
							<thead>
								<tr><th>Photo</th><th>Nom</th><th>Type</th><th>Prix</th><th>État</th><th></th></tr>
							</thead>
							<tbody>
								<?php foreach ($produits as $produit) : ?>
									<tr>
										<td style="width:64px;">
											<?php if ($produit['photo'] !== '') : ?>
												<img src="<?= h((string) $produit['photo']) ?>" alt="" width="48" height="48" style="width:48px;height:48px;object-fit:cover;border-radius:.4rem;" />
											<?php else : ?>
												<span aria-hidden="true" style="display:inline-flex;width:48px;height:48px;align-items:center;justify-content:center;background:#f0ece2;border-radius:.4rem;">—</span>
											<?php endif; ?>
										</td>
										<td>
											<strong><?= h((string) $produit['nom']) ?></strong>
											<?php if ($produit['description'] !== '') : ?>
												<br /><span class="aide"><?= h(mb_substr_compat((string) $produit['description'], 90)) ?></span>
											<?php endif; ?>
										</td>
										<td><?= $produit['type'] === 'boite' ? 'Boîte' : 'Accessoire' ?></td>
										<td>
											<?php if ($produit['type'] === 'boite') : ?>
												<span class="aide">calculé</span>
											<?php else : ?>
												<?= h(formaterCents((int) $produit['prix_cents'])) ?>
												<?php if (pricingProvisoire()) : ?>
													<br /><span class="aide"><?= h(pricingMention()) ?></span>
												<?php endif; ?>
											<?php endif; ?>
										</td>
										<td>
											<span class="etiquette <?= (int) $produit['actif'] === 1 ? 'etiquette--on' : 'etiquette--off' ?>">
												<?= (int) $produit['actif'] === 1 ? 'En vente' : 'Retiré' ?>
											</span>
											<?php if ((int) $produit['visible'] !== 1) : ?>
												<br /><span class="aide">non affiché</span>
											<?php endif; ?>
										</td>
										<td style="text-align:right;white-space:nowrap;">
											<a href="/admin/produits.php?edit=<?= (int) $produit['id'] ?>" class="bouton bouton--neutre" style="padding:.35rem .75rem;font-size:.85rem;">Modifier</a>
											<form method="post" style="display:inline;">
												<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />
												<input type="hidden" name="action" value="basculer" />
												<input type="hidden" name="id" value="<?= (int) $produit['id'] ?>" />
												<button type="submit" class="bouton bouton--neutre" style="padding:.35rem .75rem;font-size:.85rem;">
													<?= (int) $produit['actif'] === 1 ? 'Retirer' : 'Remettre' ?>
												</button>
											</form>
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

		<script>
			// Le prix d'une Boîte est calculé à partir de ses dimensions : afficher
			// le champ laisserait croire qu'on peut le fixer ici.
			(function () {
				const type = document.getElementById("champ-type");
				const bloc = document.getElementById("bloc-prix");
				if (!type || !bloc) return;
				const refleter = () => { bloc.style.display = type.value === "boite" ? "none" : ""; };
				type.addEventListener("change", refleter);
				refleter();
			})();
		</script>
	</body>
</html>
<?php

/** Tronque sans mbstring : coupe sur un octet, puis nettoie une éventuelle séquence UTF-8 brisée. */
function mb_substr_compat(string $texte, int $longueur): string
{
	if (strlen($texte) <= $longueur) {
		return $texte;
	}

	$coupe = substr($texte, 0, $longueur);
	// Retire les octets de continuation orphelins laissés par la coupe.
	while ($coupe !== '' && (ord($coupe[strlen($coupe) - 1]) & 0xC0) === 0x80) {
		$coupe = substr($coupe, 0, -1);
	}
	// Retire aussi l'octet d'amorce s'il ne lui reste plus de suite.
	if ($coupe !== '' && (ord($coupe[strlen($coupe) - 1]) & 0xC0) === 0xC0) {
		$coupe = substr($coupe, 0, -1);
	}

	return $coupe . '…';
}
