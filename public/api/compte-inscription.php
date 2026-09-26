<?php
declare(strict_types=1);

/**
 * Création d'un compte client (docs/SPEC-module-commercial.md §6.1).
 *
 *   POST /api/compte-inscription.php
 *   { "email": "…", "motdepasse": "…", "prenom": "…", "nom": "…" }
 *   → 200 {"ok": true}
 *
 * POURQUOI LA RÉPONSE EST LA MÊME SI L'ADRESSE EXISTE DÉJÀ
 * Répondre « cette adresse est déjà utilisée » permet à n'importe qui de savoir
 * qui est client de la boutique, une adresse à la fois. La spécification
 * l'interdit (§6.1). La réponse est donc rigoureusement identique dans les deux
 * cas, et l'inscription n'ouvre jamais la session : le front enchaîne sur
 * /api/compte-connexion.php avec les mêmes identifiants. Si le compte vient
 * d'être créé, le client est connecté sans s'en apercevoir ; si l'adresse
 * appartenait à quelqu'un d'autre, il reçoit le message générique de la
 * connexion, qui ne révèle rien de plus.
 *
 * La vérification de l'email à l'inscription est notée P1 dans la spec : elle
 * rendra ce détour inutile, en confirmant l'adresse avant toute connexion.
 */

require_once __DIR__ . '/../lib/commerce-guard.php';
exigerCommerceActif();

require_once __DIR__ . '/lib/commerce-api.php';
require_once __DIR__ . '/../lib/verification-email.php';

apiExigerMethode('POST');
apiExigerBase();

// Avant le jeton CSRF : un robot qui n'en présente aucun ne doit pas pouvoir
// marteler l'endpoint gratuitement. Cinq comptes par heure et par IP laissent
// largement de quoi se tromper, sans permettre une création en masse.
apiLimiterDebit('inscription', 5, 3600);

apiExigerCsrf();

$donnees = apiCorpsJson();

$erreurs = validerInscription($donnees);
if ($erreurs !== []) {
	apiJson(422, ['error' => 'Formulaire incomplet.', 'champs' => $erreurs]);
}

// validerInscription() a déjà garanti le type et la forme de chaque champ.
$nouveauId = creerClient(
	(string) $donnees['email'],
	(string) $donnees['motdepasse'],
	(string) $donnees['prenom'],
	(string) $donnees['nom']
);

/*
 * Lien de vérification de l'adresse (§6.1, « P1 »).
 *
 * Envoyé APRÈS la réponse, via le rappel d'apiJson : attendre le serveur SMTP
 * ferait répondre une adresse libre nettement plus lentement qu'une adresse
 * déjà prise, et l'énumération se ferait au chronomètre malgré des corps de
 * réponse identiques.
 *
 * Une adresse déjà prise mais pas encore vérifiée reçoit un NOUVEAU lien. C'est
 * le bon comportement produit — quelqu'un qui se réinscrit a le plus souvent
 * perdu le premier message — et cela rend les deux chemins équivalents. Un
 * compte déjà vérifié, lui, ne reçoit rien : il n'y a rien à confirmer, et
 * prévenir « quelqu'un a tenté de s'inscrire avec votre adresse » est un autre
 * sujet, à traiter s'il se pose.
 */
$aNotifier = $nouveauId !== null
	? clientParId($nouveauId)
	: clientParEmail((string) $donnees['email']);

apiJson(200, ['ok' => true], static function () use ($aNotifier): void {
	if ($aNotifier === null || $aNotifier['anonymise_a'] !== null || emailVerifie($aNotifier)) {
		return;
	}

	verifEnvoyerLien($aNotifier, verifCreerJeton((int) $aNotifier['id']));
});
