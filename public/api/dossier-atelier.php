<?php
/**
 * La Boîte à Bivouac — déclenchement du dossier atelier (génération FreeCAD sur GitHub Actions).
 *
 * Deux usages :
 *  1. Depuis le code PHP du site (commande validée, bouton de l'admin) :
 *        require_once __DIR__ . '/dossier-atelier.php';
 *        $r = declencher_dossier_atelier([...]);
 *  2. Appel HTTP POST (JSON) protégé par la clé d'administration :
 *        curl -X POST https://laboiteabivouac.fr/api/dossier-atelier.php \
 *             -H "X-Cle-Admin: <ADMIN_KEY>" -H "Content-Type: application/json" \
 *             -d '{"reference":"CMD-2026-0042","longueur_int_cm":185,"largeur_int_cm":115,"hauteur_ridelle_cm":40,"hauteur_boite_cm":90}'
 *
 * Seules la référence et les cotes partent chez GitHub : aucune donnée personnelle en clair. Pour un
 * dossier offert, l'adresse du demandeur s'y ajoute, chiffrée (voir lib/dossier-gratuit.php).
 * Les secrets sont dans config-dossier-atelier.php, HORS du dossier web (voir README).
 */

declare(strict_types=1);

const DA_BORNES = [                       // en cm, alignées sur atelier/generer_boite.py
    'longueur_int_cm'    => [92, 262],
    'largeur_int_cm'     => [72, 142],
    'hauteur_ridelle_cm' => [17, 60],
    'hauteur_boite_cm'   => [60, 120],
];
const DA_MAX_PAR_HEURE = 20;               // garde-fou contre les déclenchements en rafale

function da_config(): array
{
    // Le site est servi depuis laboiteabivouac/ ; la config est dans le dossier parent, non publié.
    $chemin = dirname(__DIR__, 2) . '/config-dossier-atelier.php';
    if (!is_file($chemin)) {
        throw new RuntimeException('Configuration absente : ' . basename($chemin));
    }
    $c = require $chemin;
    foreach (['GITHUB_TOKEN', 'GITHUB_REPO', 'ADMIN_KEY'] as $cle) {
        if (empty($c[$cle])) {
            throw new RuntimeException("Configuration incomplète : $cle");
        }
    }
    return $c;
}

/**
 * Tronque sans mbstring.
 *
 * Le kit d'origine utilisait mb_substr(). L'extension n'est pas garantie sur
 * cet hébergement mutualisé — le dépôt le dit déjà dans public/ask-question.php
 * et dans lib/clients.php — et son absence faisait échouer da_valider() à
 * chaque appel : aucun dossier n'aurait jamais été déclenché. On coupe donc sur
 * les octets, puis on retire une séquence UTF-8 laissée à moitié, ce qui évite
 * d'envoyer « Erd\xC3 » à GitHub.
 */
function da_tronquer(string $texte, int $longueur): string
{
    if (strlen($texte) <= $longueur) {
        return $texte;
    }
    $coupe = substr($texte, 0, $longueur);
    // Octets de continuation orphelins (10xxxxxx).
    while ($coupe !== '' && (ord($coupe[strlen($coupe) - 1]) & 0xC0) === 0x80) {
        $coupe = substr($coupe, 0, -1);
    }
    // Amorce sans sa suite (11xxxxxx en dernière position).
    if ($coupe !== '' && (ord($coupe[strlen($coupe) - 1]) & 0xC0) === 0xC0) {
        $coupe = substr($coupe, 0, -1);
    }

    return $coupe;
}

/** Vérifie et normalise la configuration ; renvoie [donnees, erreurs]. */
function da_valider(array $e): array
{
    $err = [];
    $d = [];
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)($e['reference'] ?? ''));
    if ($ref === '' || $ref === null) {
        $err[] = 'Référence manquante';
    }
    $d['reference'] = substr((string)$ref, 0, 40);
    foreach (DA_BORNES as $cle => [$min, $max]) {
        $v = str_replace(',', '.', (string)($e[$cle] ?? ''));
        if (!is_numeric($v)) {
            $err[] = "Valeur manquante ou invalide : $cle";
            continue;
        }
        $v = round((float)$v, 1);
        if ($v < $min || $v > $max) {
            $err[] = "$cle hors plage : $v cm (autorisé de $min à $max cm)";
        }
        $d[$cle] = $v;
    }
    if (!$err) {
        // cadre de plateau = hauteur - tube (30) - (ridelle + 10) - jeux (9) >= 150 mm
        $plateau = $d['hauteur_boite_cm'] * 10 - 30 - ($d['hauteur_ridelle_cm'] * 10 + 10) - 9;
        if ($plateau < 150) {
            $err[] = sprintf('Boîte trop basse pour cette ridelle : plateau de %d mm (150 mm minimum)', (int)$plateau);
        }
    }
    foreach (['marque', 'modele'] as $cle) {
        $d[$cle] = da_tronquer(trim(strip_tags((string)($e[$cle] ?? ''))), 40);
    }
    $d['ridelle_estimee'] = !empty($e['ridelle_estimee']);
    // Dossier offert (modèle libre) : adresse du demandeur, CHIFFRÉE par
    // lib/dossier-gratuit.php. Jamais d'adresse en clair dans la charge
    // envoyée à GitHub, qui la journalise.
    if (isset($e['destinataire_chiffre'])) {
        $chiffre = (string)$e['destinataire_chiffre'];
        if (preg_match('/^[A-Za-z0-9_-]{80,400}$/', $chiffre) !== 1) {
            $err[] = 'Destinataire chiffré invalide';
        } else {
            $d['destinataire_chiffre'] = $chiffre;
        }
    }
    return [$d, $err];
}

function da_limiter(): bool
{
    $f = sys_get_temp_dir() . '/bivouac_dossier_atelier.json';
    $maintenant = time();
    $t = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $t = array_values(array_filter($t, fn($x) => $x > $maintenant - 3600));
    if (count($t) >= DA_MAX_PAR_HEURE) {
        return false;
    }
    $t[] = $maintenant;
    file_put_contents($f, json_encode($t), LOCK_EX);
    return true;
}

/**
 * Déclenche la génération. Renvoie ['ok' => bool, 'message' => string, 'erreurs' => array].
 * Entrée : reference, longueur_int_cm, largeur_int_cm, hauteur_ridelle_cm, hauteur_boite_cm,
 *          [marque, modele, ridelle_estimee]
 */
function declencher_dossier_atelier(array $entree): array
{
    [$d, $err] = da_valider($entree);
    if ($err) {
        return ['ok' => false, 'message' => 'Cotes refusées', 'erreurs' => $err];
    }
    if (!da_limiter()) {
        return ['ok' => false, 'message' => 'Trop de demandes cette heure-ci, réessayer plus tard', 'erreurs' => []];
    }
    $c = da_config();
    $corps = json_encode(['event_type' => 'dossier-atelier', 'client_payload' => $d], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.github.com/repos/' . $c['GITHUB_REPO'] . '/dispatches');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corps,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $c['GITHUB_TOKEN'],
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: laboiteabivouac-dossier-atelier',
            'Content-Type: application/json',
        ],
    ]);
    $reponse = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur_curl = curl_error($ch);
    curl_close($ch);
    if ($code === 204) {
        return ['ok' => true, 'message' => 'Génération lancée pour ' . $d['reference'] . ' : dossier par e-mail sous 10 à 15 min', 'erreurs' => []];
    }
    error_log("dossier-atelier : GitHub HTTP $code $erreur_curl " . substr((string)$reponse, 0, 300));
    return ['ok' => false, 'message' => "GitHub a refusé la demande (HTTP $code)", 'erreurs' => []];
}

// ------------------------------------------------------------------ appel HTTP direct
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST uniquement']);
            exit;
        }
        $c = da_config();
        if (!hash_equals((string)$c['ADMIN_KEY'], (string)($_SERVER['HTTP_X_CLE_ADMIN'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Accès refusé']);
            exit;
        }
        $entree = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($entree)) {
            $entree = $_POST;
        }
        $r = declencher_dossier_atelier($entree);
        http_response_code($r['ok'] ? 202 : 422);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $t) {
        error_log('dossier-atelier : ' . $t->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Erreur serveur']);
    }
}
