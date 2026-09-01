<?php
declare(strict_types=1);

// Ce fichier documente les constantes attendues par public/api/*.php.
// Le vrai config.php n'est JAMAIS commité : il est généré au déploiement par
// scripts/write-api-config.mjs à partir des secrets GitHub Actions (voir
// .github/workflows/deploy.yml), exactement comme public/admin/config.php.

// Relais SMTP Brevo (ex-Sendinblue) plutôt que la boîte OVH : meilleure
// délivrabilité (réputation d'IP, DKIM/DMARC gérés par eux). SMTP_USER est
// l'identifiant SMTP généré dans Brevo (Settings > SMTP & API > SMTP),
// distinct de l'adresse d'expédition visible (SMTP_FROM).
define('SMTP_HOST', 'smtp-relay.brevo.com');
define('SMTP_PORT', 465);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_FROM', 'contact@laboiteabivouac.fr');
define('SMTP_NAME', 'La Boîte à Bivouac');

// Réutilisés depuis /admin (voir scripts/write-admin-config.mjs) : mêmes secrets
// GitHub que la FAQ admin, pour n'avoir qu'un seul identifiant à retenir.
define('ADMIN_USERNAME', '');
define('ADMIN_PASSWORD_HASH', '');

// Sel secret servant à calculer les tokens de désinscription (hash_hmac), pour
// que les liens de désinscription ne soient pas falsifiables.
define('UNSUBSCRIBE_SALT', '');
