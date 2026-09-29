<?php
declare(strict_types=1);

/**
 * Déclenchement du dossier atelier pour une commande.
 *
 * Fait le pont entre le module commercial et le générateur FreeCAD hébergé sur
 * GitHub Actions (api/dossier-atelier.php, fourni par le kit atelier).
 *
 * UN SEUL CHEMIN, DEUX DÉCLENCHEURS
 * La confirmation de paiement et le bouton de l'administration appellent tous
 * deux cette fonction. Le jeton GitHub et la clé d'administration ne quittent
 * donc jamais le serveur, et surtout ne passent jamais par du JavaScript.
 *
 * CE QUI PART CHEZ GITHUB : la référence et les cotes. Ni nom, ni email, ni
 * adresse. La référence est le numéro de commande suivi du rang de la ligne —
 * elle identifie une pièce à fabriquer, pas une personne.
 *
 * ASYNCHRONE PAR NATURE : GitHub accuse réception en quelques secondes, la
 * génération prend dix à quinze minutes et le dossier arrive par e-mail. Ce
 * qu'on enregistre ici est donc l'issue du déclenchement, pas celle du dossier.
 */

require_once __DIR__ . '/commandes.php';
require_once __DIR__ . '/../api/dossier-atelier.php';

/** Une ligne de commande décrit-elle une Boîte à fabriquer ? */
function dossierAtelierLigneEligible(array $ligne): bool
{
	$config = json_decode((string) ($ligne['configuration'] ?? ''), true);

	return is_array($config) && isset($config['longueur_int_cm'], $config['hauteur_boite_cm']);
}

/** Résultat déjà enregistré pour une ligne, ou null. */
function dossierAtelierEtat(array $ligne): ?array
{
	$brut = json_decode((string) ($ligne['dossier_atelier'] ?? ''), true);

	return is_array($brut) ? $brut : null;
}

/**
 * Déclenche la génération pour toutes les lignes « Boîte » d'une commande.
 *
 * @param bool $forcer relancer même les lignes déjà déclenchées avec succès.
 *                     Faux à la confirmation du paiement — une seconde
 *                     livraison du même événement Stripe ne doit pas relancer
 *                     FreeCAD — vrai depuis le bouton de l'administration, qui
 *                     sert justement à recommencer.
 * @return array{lancees: int, ignorees: int, echecs: int, messages: string[]}
 */
function dossierAtelierLancerPourCommande(int $commandeId, bool $forcer = false): array
{
	$commande = commandeParId($commandeId);
	if ($commande === null) {
		return ['lancees' => 0, 'ignorees' => 0, 'echecs' => 0, 'messages' => ['Commande introuvable.']];
	}

	$resume = ['lancees' => 0, 'ignorees' => 0, 'echecs' => 0, 'messages' => []];
	$rang = 0;

	foreach (commandeLignes($commandeId) as $ligne) {
		$rang++;
		if (!dossierAtelierLigneEligible($ligne)) {
			continue;
		}

		$etat = dossierAtelierEtat($ligne);
		if (!$forcer && ($etat['ok'] ?? false) === true) {
			$resume['ignorees']++;
			continue;
		}

		$config = json_decode((string) $ligne['configuration'], true);
		// Le rang vient de l'ordre d'insertion des lignes, stable : deux
		// déclenchements de la même commande donnent la même référence, et le
		// dossier reçu se rattache sans ambiguïté.
		$reference = $commande['numero'] . '-' . $rang;

		try {
			$retour = declencher_dossier_atelier([
				'reference' => $reference,
				'longueur_int_cm' => $config['longueur_int_cm'] ?? null,
				'largeur_int_cm' => $config['largeur_int_cm'] ?? null,
				'hauteur_ridelle_cm' => $config['hauteur_ridelle_cm'] ?? null,
				'hauteur_boite_cm' => $config['hauteur_boite_cm'] ?? null,
				'marque' => $config['marque'] ?? '',
				'modele' => $config['modele'] ?? '',
				'ridelle_estimee' => $config['ridelle_estimee'] ?? false,
			]);
		} catch (Throwable $e) {
			// Configuration absente sur l'hébergement, réseau coupé : on note et
			// on continue. Une commande payée ne doit jamais échouer ici.
			error_log('[dossier-atelier] ' . $reference . ' : ' . $e->getMessage());
			$retour = ['ok' => false, 'message' => 'Déclenchement impossible : ' . $e->getMessage(), 'erreurs' => []];
		}

		$message = (string) $retour['message'];
		if (($retour['erreurs'] ?? []) !== []) {
			$message .= ' — ' . implode(' ; ', $retour['erreurs']);
		}

		bdCommerce()->prepare('UPDATE order_items SET dossier_atelier = :etat WHERE id = :id')
			->execute([
				':etat' => (string) json_encode(
					['ok' => (bool) $retour['ok'], 'message' => $message, 'date' => gmdate('c'), 'reference' => $reference],
					JSON_UNESCAPED_UNICODE
				),
				':id' => (int) $ligne['id'],
			]);

		$retour['ok'] ? $resume['lancees']++ : $resume['echecs']++;
		$resume['messages'][] = $reference . ' : ' . $message;
	}

	return $resume;
}
