<?php
declare(strict_types=1);

// Ce fichier documente les constantes Stripe attendues par public/lib/stripe.php.
// Le vrai config-stripe.php n'est JAMAIS commité : il est généré au déploiement
// par scripts/write-api-config.mjs à partir des secrets GitHub Actions (voir
// .github/workflows/deploy.yml), comme config.php et admin/config.php.
//
// Pourquoi un fichier à part de config.php :
//
//   1. config.php définit ADMIN_USERNAME et ADMIN_PASSWORD_HASH, que
//      admin/config.php définit aussi. Une page d'administration qui inclurait
//      les deux redéfinirait ces constantes, avec des avertissements PHP en
//      plein milieu de la page.
//   2. Les endpoints de la newsletter n'ont aucune raison de charger des clés de
//      paiement. Moins de code voit un secret, mieux c'est.
//
// Ces constantes sont OPTIONNELLES, contrairement à celles de config.php : si un
// secret GitHub n'existe pas, la constante n'est pas écrite, et lib/stripe.php
// en déduit que le paiement est indisponible dans ce mode. Le générateur va plus
// loin : si AUCUNE clé n'est exploitable, ce fichier n'est pas créé du tout.
// C'est délibéré — les rendre obligatoires aurait empêché la génération de
// config.php et coupé la newsletter tant que les clés n'étaient pas déposées.
//
// Un mode = une paire, et les deux clés d'une paire vont ensemble : une clé
// secrète sans secret de webhook permettrait d'encaisser un paiement que le site
// ne saurait pas rattacher à une commande. Le mode effectivement utilisé vient
// de data/settings.json (payment_mode), réglé dans /admin/parametres.php, jamais
// de ce fichier.
//
// Secrets GitHub correspondants : STRIPE_TEST_SECRET_KEY,
// STRIPE_TEST_WEBHOOK_SECRET, STRIPE_LIVE_SECRET_KEY,
// STRIPE_LIVE_WEBHOOK_SECRET.
//
// Les clés secrètes commencent par sk_ ou rk_ (clé restreinte, préférable côté
// serveur), le secret de webhook par whsec_. La clé publique pk_… n'a rien à
// faire ici : le serveur n'en a pas besoin.
define('STRIPE_TEST_SECRET_KEY', '');
define('STRIPE_TEST_WEBHOOK_SECRET', '');
define('STRIPE_LIVE_SECRET_KEY', '');
define('STRIPE_LIVE_WEBHOOK_SECRET', '');
