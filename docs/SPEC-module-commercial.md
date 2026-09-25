# Spécification — Module commercial laboiteabivouac.fr

Version 2 — 25/09/2026 · Statut : à développer · Destinataire : Claude Code
À placer dans le dépôt : `docs/SPEC-module-commercial.md`
Changement v2 : **paiement en ligne dès la V1** (décision de Matthieu du 25/09/2026).

---

## 1. Objectif

Permettre aux visiteurs de créer un compte, d'ajouter au panier une Boîte à Bivouac (fabriquée sur mesure pour leur remorque) et des accessoires, puis de passer commande et **payer en ligne**.

Le module est livré en production **désactivé** et s'active depuis l'interface d'admin, sans redéploiement. À terme, la page `/compatibilite` devient le configurateur qui alimente le panier.

## 2. Principes non négociables

- **Interrupteur appliqué côté serveur.** Module désactivé = les API commerce répondent 404. Le masquage visuel ne suffit pas.
- **Aucun prix ne vient du navigateur.** Le serveur recalcule tous les montants à partir du catalogue et des règles de prix.
- **Aucune donnée client dans un fichier accessible par URL.** Stockage dans un dossier interdit par `.htaccess`, exclu de la synchro FTP.
- **Aucune donnée de carte bancaire** ne transite par le serveur ni n'y est stockée.
- **Aucun abonnement payant.** Seul Stripe prélève une commission par transaction. Réutilisation de l'existant (voir §4).
- **Règles de marque** : le mot « démontable » ne doit apparaître dans aucun texte.
- **Design system** : classes `t-*`, palette `@theme` de `global.css`. Ne jamais combiner une classe `t-*` avec un utilitaire de taille Tailwind. Attention au piège de spécificité des `<style>` scopés Astro.

## 3. Hors périmètre V1

- Commande sans compte (invité) — le compte est requis pour commander.
- Facturation automatique (facture produite manuellement au départ).
- Codes promo, multi-devises, livraison internationale, gestion de stock avancée.
- Paiement en plusieurs fois et moyens de paiement différés (virement, prélèvement SEPA) : voir §8.

## 4. Existant à réutiliser

| Besoin | Existant |
|---|---|
| Authentification admin | Auth PHP actuelle (`ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH`) |
| Admin autonome PHP | Modèle de `public/admin/faq.php`, `equipements.php` |
| Anti-abus | `public/lib/rate-limit.php` |
| Envoi d'emails | SMTP OVH déjà utilisé par la newsletter |
| Secrets → config | `scripts/write-api-config.mjs`, `write-admin-config.mjs` + GitHub Secrets |
| Données live non écrasées | Exclusions de synchro dans `.github/workflows/deploy.yml` |
| Calcul des dimensions | `src/data/remorques.ts` (28 remorques, hauteur, jeu, volume, classe de péage) |
| Upload de médias | Mécanique d'upload de l'admin équipements |
| Mesure d'audience | Umami (sans cookie) |

---

## 5. Phase 0 — Interrupteur et socle

Livrable déployable en production immédiatement : **rien ne change pour les visiteurs.**

### 5.1 Réglages

- `public/data/settings.default.json` versionné : `{ "commerce_enabled": false, "payment_mode": "test" }`.
- Fichier live `public/data/settings.json` exclu de la synchro FTP (même mécanique que les JSON live FAQ/équipements).
- `public/lib/settings.php` : lecture avec verrou, repli sur le fichier par défaut.
- `public/api/settings.php` (GET) → `{"commerce": bool, "preview": bool}`, en-tête `Cache-Control: no-store`.
  - `preview = true` quand le module est désactivé mais qu'une session admin est ouverte.
- `public/lib/commerce-guard.php` : inclus en tête de chaque endpoint commerce. Module désactivé et pas de session admin → `404` JSON.

### 5.2 Admin — page Paramètres

- Même technologie que `faq.php`, protégée par l'auth admin existante.
- Interrupteur « Module commercial » avec modale de confirmation, date de dernière modification.
- Sélecteur « Paiement : test / réel » (réglage `payment_mode`, valeur par défaut `test`). Voir §6.5.
- Checklist indicative (non bloquante) à côté de l'interrupteur : CGV publiées, mentions légales avec SIRET, politique de confidentialité à jour, prix renseignés dans le catalogue, paiement en mode réel.
- Avertissement bien visible si le module est activé alors que le paiement est encore en mode test.
- Lien ajouté dans le menu latéral de l'admin.

### 5.3 Front

- Tout élément commercial (icône panier, lien « Mon compte », boutons « Ajouter au panier », prix) porte `data-feature="commerce"` et l'attribut `hidden` dans le HTML statique.
- `src/scripts/features.ts`, chargé par `Layout.astro` : appelle `/api/settings.php` et retire `hidden` si `commerce` ou `preview`.
- En mode aperçu, bandeau discret : « Mode aperçu — boutique non publique ».
- **Échec de l'appel = tout reste masqué** (fail closed).
- Pages commerciales (`/panier`, `/compte`, `/commande`, `/boutique`) : `noindex, nofollow`, exclues du sitemap, redirection vers `/` si module désactivé (hors aperçu).

### 5.4 Stockage

- **SQLite via PDO** (`pdo_sqlite`), fichier dans un dossier protégé par `.htaccess` (`Require all denied`) et exclu du déploiement FTP.
- Vérifier d'abord que `pdo_sqlite` est disponible sur l'hébergement OVH. Sinon : MySQL OVH via PDO.
- Migrations SQL versionnées (`public/lib/db/migrations/*.sql`), appliquées par un script admin idempotent.
- Tables : `customers`, `addresses`, `password_resets`, `products`, `orders`, `order_items`.
- Montants stockés en **centimes (entiers)**.

---

## 6. Phase 1 — Comptes, catalogue, panier, commande, paiement

Développée derrière l'interrupteur, testée en production en mode aperçu admin avec le paiement en mode test.

### 6.1 Comptes clients

- Inscription (email, mot de passe de 10 caractères minimum, prénom, nom), connexion, déconnexion.
- Mot de passe oublié : lien à usage unique, jeton stocké haché, validité 1 h, envoi via le SMTP existant.
- `password_hash` / `password_verify` ; `session_regenerate_id` à la connexion ; cookie `Secure`, `HttpOnly`, `SameSite=Lax`.
- Jeton CSRF sur toute requête POST.
- `rate-limit.php` appliqué à la connexion, l'inscription et le mot de passe oublié.
- Messages d'erreur génériques (ne jamais révéler si un email existe).
- Espace « Mon compte » : coordonnées, adresses, historique des commandes avec statut.
- Suppression de compte : anonymisation du client, commandes conservées (obligations comptables).
- P1 : vérification de l'email à l'inscription.

### 6.2 Catalogue

- Deux types : `boite` (produit unique, sur mesure) et `accessoire`.
- Admin Produits : créer, modifier, désactiver ; nom, description, prix TTC, photo, visible oui/non.
- Page `/boutique` : accessoires visibles (masquée si module désactivé).

### 6.3 Panier

- Stocké côté navigateur (`localStorage`, clé `bab_cart_v1`). Pas de compte requis pour remplir le panier.
- Ligne boîte : identifiant de remorque de `remorques.ts` **ou** dimensions intérieures saisies (L × l en cm), plus les options.
- Les dimensions de la boîte sont calculées avec la règle existante de `remorques.ts`, **portée à l'identique en PHP** pour le recalcul serveur (même jeu, même hauteur, mêmes bornes). Ajouter un test qui compare les deux implémentations sur les 28 remorques.
- Lignes accessoires : quantité.
- Page `/panier` : modification des quantités, suppression, sous-total indicatif, bouton « Commander ».

### 6.4 Commande

- Tunnel : panier → connexion/inscription → adresse → récapitulatif → validation.
- Case d'acceptation des CGV obligatoire ; la version des CGV acceptée est enregistrée avec la commande.
- Le serveur revalide chaque ligne (produit actif, remorque connue ou dimensions dans les bornes), **recalcule tous les prix**, enregistre un instantané immuable (libellés, prix, configuration, dimensions calculées).
- Numérotation : `BAB-AAAA-NNNN`.
- La commande est **engageante dès le clic** : le bouton final porte la mention « Commande avec obligation de paiement ».
- La commande est créée au statut « En attente de paiement », puis le client est redirigé vers le paiement (§6.5).
- Emails, envoyés **uniquement après confirmation du paiement** : confirmation de commande au client (récapitulatif complet, dimensions calculées, CGV applicables), notification à `contact@laboiteabivouac.fr`.
- Statuts : En attente de paiement → Payée → En fabrication → Prête / Expédiée → Livrée ; Annulée ; Remboursée.
- Une commande restée « En attente de paiement » plus de 24 h passe à « Annulée » (nettoyage au prochain passage admin ou tâche planifiée).

### 6.5 Paiement en ligne (Stripe Checkout)

- **Stripe Checkout**, page de paiement hébergée par Stripe : aucune donnée de carte ne touche le serveur, pas d'abonnement (commission par transaction uniquement).
- Moyen de paiement V1 : carte bancaire (Apple Pay / Google Pay si Stripe les propose automatiquement). Pas de moyens de paiement différés en V1.
- Appels à l'API Stripe en `curl` PHP, **sans SDK ni Composer** :
  - `public/api/checkout.php` (POST, client connecté, CSRF) : revalide et recalcule la commande, crée une session Checkout avec les lignes et montants **calculés par le serveur**, l'identifiant de commande en `metadata` et `client_reference_id`, l'email du client, `success_url` et `cancel_url` vers le site. Clé d'idempotence = numéro de commande.
  - `public/api/stripe-webhook.php` : vérifie la signature `Stripe-Signature` (HMAC SHA-256 avec le secret du webhook, tolérance de 5 min, comparaison `hash_equals`) ; sur `checkout.session.completed` avec `payment_status = paid`, vérifie que le montant payé correspond au total de la commande, passe la commande à « Payée » et envoie les emails. Traitement idempotent (un même événement reçu deux fois ne produit rien de plus).
  - Le webhook n'est **pas** soumis au `commerce-guard` : il doit toujours pouvoir confirmer un paiement.
- La page de retour `success_url` affiche « Paiement en cours de confirmation » tant que le webhook n'a pas validé la commande : **seul le webhook fait foi**, jamais le retour navigateur.
- **Mode test / réel** : deux jeux de clés dans la configuration (`STRIPE_TEST_SECRET_KEY`, `STRIPE_TEST_WEBHOOK_SECRET`, `STRIPE_LIVE_SECRET_KEY`, `STRIPE_LIVE_WEBHOOK_SECRET`), stockés dans GitHub Secrets et écrits au déploiement par `write-api-config.mjs`. Le réglage `payment_mode` choisit le jeu utilisé. Jamais de clé dans le code ni dans un chat.
- En mode test, le paiement n'est accessible qu'aux sessions admin (mode aperçu), pour éviter qu'un client « paie » sans être débité.
- Chaque commande enregistre le mode (`test` ou `live`) et l'identifiant de paiement Stripe ; l'admin affiche un badge « TEST » sur les commandes de test.
- Remboursement (bien sur mesure impossible à fabriquer, annulation) : effectué depuis le tableau de bord Stripe ; l'admin permet de passer la commande à « Remboursée » avec une note.

### 6.6 Admin commandes et clients

- Commandes : liste filtrable par statut et par mode (test / réel), détail (client, lignes, configuration, adresse, identifiant de paiement), changement de statut, export CSV.
- P1 : email automatique au client à chaque changement de statut.
- P1 : liste et recherche des clients.

### 6.7 Mesure

- Événements Umami, sans donnée personnelle : `ajout_panier`, `debut_commande`, `paiement_lance`, `paiement_confirme`.

---

## 7. Phase 2 — Configurateur sur `/compatibilite`

- La page reste pleinement utilisable comme outil de compatibilité quand le module est désactivé : aucune régression fonctionnelle ni SEO.
- Étapes : 1. remorque (existant) · 2. options · 3. récapitulatif (dimensions, volume, classe de péage, prix) + bouton « Ajouter au panier » (`data-feature="commerce"`).
- Règles de prix dans un fichier unique `src/data/pricing.json`, lu par le TypeScript au build (affichage) et par le PHP (recalcul serveur). Aucune règle de prix dupliquée en dur.
- Logique de `remorques.ts` inchangée ; tests unitaires ajoutés pour le calcul de prix.
- Lien partageable : `/compatibilite?remorque=<id>&options=<…>`.

## 8. Évolutions de paiement (hors V1)

- Acompte à la commande et solde avant livraison, si ce modèle est retenu (question 5).
- Moyens de paiement différés (virement, prélèvement SEPA) : ils imposent de gérer les événements `checkout.session.async_payment_succeeded` et `async_payment_failed`.
- Facturation automatique.

---

## 9. Prérequis avant d'activer l'interrupteur en production

Points juridiques à faire valider par un professionnel :

- Structure juridique et SIRET.
- CGV publiées : exception au droit de rétractation pour un bien fabriqué selon les spécifications du client (la boîte sur mesure), délai de rétractation de 14 jours pour les accessoires standards, garanties légales, modalités et délais de livraison.
- Mentions légales complétées (SIRET, adresse, régime de TVA).
- Politique de confidentialité mise à jour (comptes clients, commandes, durées de conservation).
- Processus de facturation défini, même manuel.
- Compte Stripe activé pour les paiements réels (vérification de l'entreprise, qui suppose le SIRET), clés réelles et webhook réel installés, sélecteur de paiement passé en « réel ».

## 10. Critères d'acceptation

**Module désactivé — visiteur**
- [ ] Aucun élément commercial visible sur aucune page.
- [ ] Tous les endpoints commerce répondent 404.
- [ ] `/panier`, `/compte`, `/commande`, `/boutique` redirigent vers l'accueil et sont en `noindex`.
- [ ] `/compatibilite` fonctionne exactement comme aujourd'hui.

**Module désactivé — admin connecté**
- [ ] Tout est visible avec le bandeau « Mode aperçu ».
- [ ] Une commande complète est payée avec une carte de test Stripe ; elle apparaît « Payée » avec le badge « TEST ».

**Module activé**
- [ ] Parcours complet : inscription → panier (boîte + accessoire) → commande → paiement → emails reçus → commande « Payée » dans l'admin et dans « Mon compte ».

**Paiement**
- [ ] Paiement refusé ou abandonné : la commande reste « En attente de paiement », aucun email de confirmation.
- [ ] Un retour sur `success_url` sans webhook ne passe pas la commande à « Payée ».
- [ ] Un webhook à signature invalide est rejeté (400) et ne modifie rien.
- [ ] Un même événement webhook reçu deux fois ne produit qu'un seul passage à « Payée » et un seul envoi d'emails.
- [ ] En mode test, un visiteur non admin ne peut pas lancer de paiement.

**Sécurité**
- [ ] Un prix modifié dans le `localStorage` est ignoré ; le montant envoyé à Stripe est celui calculé par le serveur.
- [ ] Une requête POST sans jeton CSRF est refusée.
- [ ] Le fichier de base de données n'est pas téléchargeable par URL.
- [ ] Les tentatives de connexion au-delà du seuil de `rate-limit.php` sont bloquées.
- [ ] Un déploiement FTP n'écrase ni la base de données ni `settings.json`.

## 11. Questions ouvertes

| # | Question | Qui | Bloque |
|---|---|---|---|
| 1 | Modèle de prix de la boîte (fixe, selon dimensions, selon options) et montants | Matthieu | Prix en phase 1 |
| 2 | Accessoires : catalogue dédié ou extension du catalogue Équipements existant ? | Matthieu | §6.2 |
| 3 | Livraison, retrait à l'atelier, frais de port ? | Matthieu | §6.4 |
| 4 | Régime de TVA (franchise en base ou non) | Finance & Legal | Affichage des prix |
| 5 | Paiement intégral à la commande ou acompte ? (V1 développée en paiement intégral) | Matthieu | §8 |
| 6 | Liste des options du configurateur | Matthieu / Product | Phase 2 |

Les questions 1 à 4 ne bloquent pas le développement : utiliser des valeurs factices clairement marquées en attendant.

## 12. Ordre de livraison

1. Phase 0 en production, interrupteur désactivé.
2. Phase 1 en production, testée en mode aperçu admin avec Stripe en mode test (un compte Stripe en mode test suffit, sans SIRET).
3. Phase 2 (configurateur).
4. Passage du paiement en mode réel puis activation du module, une fois les prérequis du §9 remplis.

Après chaque phase : mettre à jour la documentation Notion concernée (Engineering : Stack & Architecture, Sécurité, CI/CD ; Product : Fonctionnalités, Architecture de l'information).
