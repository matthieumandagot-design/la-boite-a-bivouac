<?php
declare(strict_types=1);

/**
 * Barre latérale de l'administration, partagée par les pages PHP récentes.
 *
 * Les pages historiques (faq.php, equipements.php) portent chacune leur propre
 * copie de ce balisage. Elles n'ont pas été réécrites pour l'utiliser : ce sont
 * des pages en service, et un remaniement cosmétique n'y vaut pas le risque.
 * La dette est connue — trois copies aujourd'hui, à regrouper ici le jour où
 * l'une d'elles sera de toute façon rouverte.
 */

// Inclus par une page admin, jamais servi directement : sans cette garde, une
// requête sur /admin/nav.php afficherait la structure de l'administration à
// n'importe qui, sans authentification.
if (!defined('BAB_ADMIN')) {
	http_response_code(404);
	exit;
}

const ADMIN_LIENS = [
	['/admin', '🏠', 'Tableau de bord'],
	['/admin/subscribers', '📧', 'Abonnés'],
	['/admin/send-news', '📨', 'Envoyer une news'],
	['/admin/equipements.php', '🧰', 'Équipements'],
	['/admin/faq.php', '❓', 'FAQ'],
	['/admin/parametres.php', '⚙️', 'Paramètres'],
	['/admin/base.php', '🗄️', 'Base de données'],
];

const ADMIN_OUTILS = [
	['https://cloud.umami.is/analytics/eu/websites', '📊', 'Umami'],
	['https://search.google.com/search-console?hl=fr&resource_id=https://laboiteabivouac.fr/', '🔍', 'Search Console'],
	['https://app.brevo.com/', '✉️', 'Brevo'],
	// Tableau de bord du compte Stripe, en MODE TEST. Au passage en paiement
	// réel, retirer « /test » de cette URL — ici et dans les trois autres barres
	// latérales (AdminLayout.astro, faq.php, equipements.php). Le laisser
	// afficherait un tableau de bord vide alors que de vrais paiements arrivent.
	['https://dashboard.stripe.com/acct_1UJZzZ1YjormFH5b/test/dashboard', '💳', 'Stripe'],
];

function h(string $valeur): string
{
	return htmlspecialchars($valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderAdminSidebar(string $actif): void
{
	$base = 'display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;';
	?>
	<aside style="width:220px;flex-shrink:0;background:#122218;color:#e8e0c8;display:flex;flex-direction:column;position:fixed;top:0;bottom:0;left:0;">
		<div style="padding:1.5rem 1.25rem;border-bottom:1px solid rgba(232,224,200,0.12);">
			<p style="text-transform:uppercase;letter-spacing:.08em;font-size:.65rem;font-weight:700;color:#e8b428;margin:0 0 .2rem;">
				La Boîte à Bivouac
			</p>
			<p style="font-weight:700;font-size:1rem;margin:0;">Administration</p>
		</div>
		<nav style="flex:1;overflow-y:auto;padding:1rem 0.75rem;">
			<?php foreach (ADMIN_LIENS as [$href, $icone, $libelle]) : ?>
				<?php $estActif = $href === $actif; ?>
				<a
					href="<?= h($href) ?>"
					<?= $estActif ? 'aria-current="page"' : '' ?>
					style="<?= $base ?><?= $estActif ? 'color:#0a1610;background:#e8b428;' : 'color:#e8e0c8;' ?>"
				>
					<span aria-hidden="true"><?= $icone ?></span> <?= h($libelle) ?>
				</a>
			<?php endforeach; ?>

			<a href="/" target="_blank" rel="noopener" style="<?= $base ?>color:#babba8;margin-top:.75rem;border-top:1px solid rgba(232,224,200,0.12);padding-top:1rem;">
				<span aria-hidden="true">🌐</span> Voir le site
			</a>

			<p style="text-transform:uppercase;letter-spacing:.1em;font-size:.65rem;font-weight:700;color:#7f8a77;margin:1.25rem 0 .35rem;padding:0 .75rem;">
				Outils
			</p>
			<?php foreach (ADMIN_OUTILS as [$href, $icone, $libelle]) : ?>
				<a href="<?= h($href) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;">
					<span aria-hidden="true"><?= $icone ?></span> <?= h($libelle) ?>
				</a>
			<?php endforeach; ?>
		</nav>
	</aside>
	<?php
}
