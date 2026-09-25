<?php
declare(strict_types=1);

/**
 * Barre latérale de l'administration, partagée par les pages PHP récentes.
 *
 * Les pages historiques (faq.php, equipements.php) portent chacune leur propre
 * copie de ce balisage, et src/layouts/AdminLayout.astro une quatrième pour les
 * pages Astro. Elles n'ont pas été réécrites pour utiliser ce fichier : ce sont
 * des pages en service, et un remaniement cosmétique n'y vaut pas le risque.
 * La dette est connue — quatre copies aujourd'hui, à regrouper ici le jour où
 * l'une d'elles sera de toute façon rouverte.
 *
 * En attendant, la règle est simple : cette liste doit rester identique à celle
 * d'AdminLayout.astro, qui fait référence. Un raccourci ajouté là-bas et oublié
 * ici donne exactement ce qu'on a constaté le 25/09/2026 — un menu amputé sur
 * Paramètres et Base de données, et une navigation qui change selon la page.
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
	['https://www.google.com/search?q=La+Bo%C3%AEte+%C3%A0+Bivouac&stick=H4sIAAAAAAAA_-NgU1I1qEhMSkk1TDJONU4zMDYyszS1MqgwMjW1TLY0TjQ2T05OSzYyWMQq4pOo4JR_eF1JqsLhBQpOmWX5pYnJAE6QNhdAAAAA&hl=fr&mat=CZ21dska8KCrElYBa0lj_zMH55jHjvES16LWm6uTi6l3nowGSeXXeYK8C2mZEzADs4zLYTBuJTBDpu9rjVR4AstE7uSgyyshKQxIZew91anM46AH6SPo0ADhovME3I66iQ&authuser=0&ved=2ahUKEwiQt9va1-yWAxUR5AIHHRojKggQ-MgIegQIFxAb', '📍', 'Fiche Google'],
	['https://app.brevo.com/', '📧', 'Brevo'],
	// Tableau de bord du compte Stripe, en MODE TEST. Au passage en paiement
	// réel, retirer « /test » de cette URL — ici et dans les trois autres barres
	// latérales (AdminLayout.astro, faq.php, equipements.php). Le laisser
	// afficherait un tableau de bord vide alors que de vrais paiements arrivent.
	['https://dashboard.stripe.com/acct_1UJZzZ1YjormFH5b/test/dashboard', '💳', 'Stripe'],
	['https://manager.eu.ovhcloud.com/', '☁️', 'OVH Manager'],
	['https://app.notion.com/p/La-Bo-te-Bivouac-Pilotage-3ac440dc05e5811a891fe579c98c9c56', '📓', 'Notion'],
	['https://zimbra1.mail.ovh.net/modern/email/Inbox/conversation/-258', '✉️', 'Webmail OVH'],
	['https://calendar.google.com/calendar/u/0/r?pli=1', '📅', 'Google Agenda'],
	['https://mail.google.com/mail/u/0/#inbox', '📬', 'Gmail'],
];

const ADMIN_RESEAUX = [
	['https://www.tiktok.com/@laboiteabivouac8', '🎵', 'TikTok'],
	['https://www.youtube.com/@LaBo%C3%AEte%C3%A0Bivouac', '▶️', 'YouTube'],
	['https://www.linkedin.com/company/136036436/admin/dashboard/', '💼', 'LinkedIn'],
	['https://www.facebook.com/laboiteabivouac?checkpoint_src=any', '👍', 'Facebook'],
	['https://www.instagram.com/laboiteabivouac/', '📷', 'Instagram'],
	['https://www.threads.com/@laboiteabivouac', '🧵', 'Threads'],
	['https://fr.pinterest.com/laboiteabivouac/', '📌', 'Pinterest'],
];

function h(string $valeur): string
{
	return htmlspecialchars($valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderAdminSidebar(string $actif): void
{
	$base = 'display:flex;align-items:center;gap:.6rem;padding:.65rem .75rem;border-radius:.5rem;margin-bottom:.25rem;text-decoration:none;font-size:.9rem;font-weight:500;';
	$externe = 'display:flex;align-items:center;gap:.6rem;padding:.45rem .75rem;border-radius:.5rem;text-decoration:none;font-size:.82rem;font-weight:500;color:#babba8;';
	$titreGroupe = 'text-transform:uppercase;letter-spacing:.1em;font-size:.65rem;font-weight:700;color:#7f8a77;margin:1.25rem 0 .35rem;padding:0 .75rem;';
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

			<p style="<?= $titreGroupe ?>">Admin</p>
			<?php foreach (ADMIN_OUTILS as [$href, $icone, $libelle]) : ?>
				<a href="<?= h($href) ?>" target="_blank" rel="noopener" style="<?= $externe ?>">
					<span aria-hidden="true"><?= $icone ?></span> <?= h($libelle) ?>
				</a>
			<?php endforeach; ?>

			<p style="<?= $titreGroupe ?>">Réseaux</p>
			<?php foreach (ADMIN_RESEAUX as [$href, $icone, $libelle]) : ?>
				<a href="<?= h($href) ?>" target="_blank" rel="noopener" style="<?= $externe ?>">
					<span aria-hidden="true"><?= $icone ?></span> <?= h($libelle) ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php // Pas de bouton de déconnexion : l'authentification est celle du
		      // navigateur (Basic Auth), qu'une page ne peut pas révoquer. ?>
		<div style="padding:1rem 1.25rem;border-top:1px solid rgba(232,224,200,0.12);font-size:.75rem;color:#babba8;">
			Fermez l'onglet pour vous déconnecter (authentification navigateur).
		</div>
	</aside>
	<?php
}
