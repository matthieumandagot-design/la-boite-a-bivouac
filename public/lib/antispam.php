<?php
/**
 * Macklos — protection anti-spam des formulaires publics (module commun à tous les sites).
 * Version 1.2.0 — 12/11/2026 (fonctionne sans l'extension mbstring)
 *
 * v1.2 (souhait de Matthieu) : AUCUN message n'est supprimé sur la base de son contenu. Les contrôles
 * de contenu renvoient le statut « suspect » : le message est enregistré et transmis normalement,
 * signalé « Spam probable » (sujet de la notification, admin), et on n'écrit pas à l'adresse saisie.
 * Seuls les robots qui n'ont pas affiché le formulaire (champ piège, jeton absent ou faux, envoi en
 * moins de 3 s) et le dépassement du quota par IP reçoivent le statut « robot » (ignorés en silence).
 *
 * Sans captcha, invisible pour le visiteur. Contrôles :
 *   1. Champ piège (honeypot) : invisible pour un humain, rempli par les robots.
 *   2. Jeton signé (HMAC) avec l'heure d'affichage du formulaire :
 *        - absent ou falsifié  → robot qui poste directement ;
 *        - envoyé en moins de ANTISPAM_DELAI_MIN secondes → robot ;
 *        - trop vieux (> ANTISPAM_DELAI_MAX) → on demande au visiteur de renvoyer.
 *   3. Limite par adresse IP (hachée, rien de personnel stocké) : par heure et par jour.
 *   4. Contenu (statut « suspect », jamais de suppression) : domaines d'e-mail jetables, saut de
 *      ligne dans un champ court, lien dans un champ court (nom, sujet…), trop de liens dans le
 *      message, même message déjà reçu (7 jours). C'est ce contrôle qui repère les robots pilotés
 *      par IA dans un vrai navigateur (cas du 11/11/2026) : ils passent le champ piège et le délai.
 *   5. antispam_copie_autorisee() : jamais de copie du message à l'expéditeur s'il contient
 *      un lien (empêche d'utiliser le site pour envoyer du spam à un tiers).
 *
 * Un robot rejeté reçoit la même réponse qu'un envoi réussi (il n'apprend rien) ; rien n'est
 * enregistré ni envoyé. Robots et messages suspects sont comptés dans <ANTISPAM_DIR>/journal.json.
 *
 * Utilisation (voir LISEZ-MOI) :
 *   Dans le <form> :   <?= antispam_champs('contact') ?>
 *   Au traitement :    $as = antispam_verifier('contact', $_POST, ['email' => $email, 'texte' => $message]);
 *                      if ($as['statut'] === 'robot')  { // faire comme si c'était envoyé, ne rien faire }
 *                      if ($as['statut'] === 'expire') { // afficher ANTISPAM_MSG_EXPIRE, garder la saisie }
 *                      // 'ok' ou 'suspect' : enregistrer + notifier (préfixe antispam_prefixe($as)) ;
 *                      if ($as['statut'] === 'ok' && antispam_copie_autorisee($message)) { // copie à l'expéditeur }
 */

if (!defined('ANTISPAM_DIR'))        define('ANTISPAM_DIR', dirname(__DIR__) . '/data/antispam');
if (!defined('ANTISPAM_DELAI_MIN'))  define('ANTISPAM_DELAI_MIN', 3);        // secondes
if (!defined('ANTISPAM_DELAI_MAX'))  define('ANTISPAM_DELAI_MAX', 86400);    // 24 h
if (!defined('ANTISPAM_MAX_HEURE'))  define('ANTISPAM_MAX_HEURE', 3);        // envois par IP et par formulaire
if (!defined('ANTISPAM_MAX_JOUR'))   define('ANTISPAM_MAX_JOUR', 10);
if (!defined('ANTISPAM_MAX_LIENS'))  define('ANTISPAM_MAX_LIENS', 2);        // au-delà : rejet
if (!defined('ANTISPAM_CHAMP_PIEGE')) define('ANTISPAM_CHAMP_PIEGE', 'site_web_entreprise');
if (!defined('ANTISPAM_MSG_EXPIRE')) define('ANTISPAM_MSG_EXPIRE',
    'La page était ouverte depuis longtemps : merci de vérifier votre message et de cliquer à nouveau sur Envoyer.');

/** Domaines d'e-mail jetables ou de spam déjà vus (fin de domaine). Complétable par site. */
function antispam_domaines_bloques(): array {
    static $liste = null;
    if ($liste === null) {
        $liste = ['my.id', 'probox.my.id', 'mailinator.com', 'yopmail.com', 'guerrillamail.com',
                  'sharklasers.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'trashmail.com'];
        $sup = ANTISPAM_DIR . '/domaines-bloques.txt';
        if (is_file($sup)) {
            foreach (file($sup, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                $l = strtolower(trim($l));
                if ($l !== '' && $l[0] !== '#') $liste[] = $l;
            }
        }
    }
    return $liste;
}

function antispam_dossier(): string {
    $d = ANTISPAM_DIR;
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
    }
    if (is_dir($d) && !is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/** Clé secrète propre au site, créée au premier usage. */
function antispam_cle(): string {
    if (defined('ANTISPAM_CLE')) return ANTISPAM_CLE;
    $f = antispam_dossier() . '/cle.txt';
    $k = is_file($f) ? trim((string) file_get_contents($f)) : '';
    if (strlen($k) < 32) {
        $k = bin2hex(random_bytes(32));
        @file_put_contents($f, $k, LOCK_EX);
        @chmod($f, 0640);
    }
    return $k;
}

function antispam_jeton(string $form, ?int $t = null): string {
    $t = $t ?? time();
    return $t . '.' . substr(hash_hmac('sha256', $form . '|' . $t, antispam_cle()), 0, 32);
}

/** Champs cachés à placer dans le <form>. */
function antispam_champs(string $form): string {
    $piege = htmlspecialchars(ANTISPAM_CHAMP_PIEGE, ENT_QUOTES);
    $jeton = htmlspecialchars(antispam_jeton($form), ENT_QUOTES);
    // Masqué hors écran (pas display:none, que certains robots repèrent), ignoré des lecteurs d'écran.
    return '<div aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto;width:1px;height:1px;overflow:hidden">'
         . '<label for="as_' . $piege . '">Ne pas remplir ce champ</label>'
         . '<input type="text" id="as_' . $piege . '" name="' . $piege . '" value="" tabindex="-1" autocomplete="off">'
         . '</div>'
         . '<input type="hidden" name="as_jeton" value="' . $jeton . '">';
}

function antispam_ip(): string {
    // Pas de X-Forwarded-For : falsifiable. OVH mutualisé fournit la vraie IP dans REMOTE_ADDR.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function antispam_nb_liens(string $texte): int {
    $tld = 'com|net|org|online|xyz|top|site|info|biz|pro|app|dev|io|co|me|ly|gl|link|click|shop|store|ru|cn|id|fr|de|be|ch|eu|us|uk|in|tk|ml|ga|cf|gq|live|life|today|website|space|fun|icu|club|vip|win|bid|loan|work';
    return preg_match_all('~(https?://|www\.|\b[a-z0-9-]{2,}(\.[a-z0-9-]{2,})*\.(' . $tld . ')\b)~i', $texte);
}

/**
 * Vérifie un envoi. $donnees : ['email' => ..., 'texte' => ..., 'champs' => [valeurs courtes à protéger des sauts de ligne]].
 * Retour : ['statut' => 'ok'|'suspect'|'robot'|'expire', 'motif' => string, 'libelle' => string].
 */
function antispam_verifier(string $form, array $post, array $donnees = []): array {
    $rejet = function (string $motif) use ($form) {
        antispam_journal($form, $motif);
        return ['statut' => 'robot', 'motif' => $motif, 'libelle' => antispam_libelle($motif)];
    };

    // 1. Champ piège
    if (trim((string) ($post[ANTISPAM_CHAMP_PIEGE] ?? '')) !== '') return $rejet('champ_piege');

    // 2. Jeton
    $jeton = (string) ($post['as_jeton'] ?? '');
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $jeton, $m)) return $rejet('jeton_absent');
    $t = (int) $m[1];
    if (!hash_equals(antispam_jeton($form, $t), $jeton)) return $rejet('jeton_faux');
    $age = time() - $t;
    if ($age < ANTISPAM_DELAI_MIN) return $rejet('trop_rapide');
    if ($age > ANTISPAM_DELAI_MAX) {
        antispam_journal($form, 'expire');
        return ['statut' => 'expire', 'motif' => 'expire', 'libelle' => antispam_libelle('expire')];
    }

    return antispam_verifier_contenu($form, $donnees);
}

/**
 * Contrôles de contenu seuls (pour un site qui a déjà son champ piège et son jeton).
 * $donnees : ['email' => ..., 'texte' => message libre, 'champs' => [nom, sujet… : champs courts]].
 * $opts : ['quota' => true, 'doublons' => true].
 */
function antispam_verifier_contenu(string $form, array $donnees, array $opts = []): array {
    $opts += ['quota' => true, 'doublons' => true];
    $suspect = function (string $motif) use ($form) {
        antispam_journal($form, 'suspect_' . $motif);
        return ['statut' => 'suspect', 'motif' => $motif, 'libelle' => antispam_libelle($motif)];
    };
    // Le quota par IP reste un blocage : c'est un envoi en rafale, pas un message à lire.
    if ($opts['quota'] && !antispam_quota($form)) {
        antispam_journal($form, 'quota_ip');
        return ['statut' => 'robot', 'motif' => 'quota_ip', 'libelle' => antispam_libelle('quota_ip')];
    }
    $texte = (string) ($donnees['texte'] ?? '');
    $doublon = $opts['doublons'] && antispam_doublon($form, $texte);
    if ($opts['doublons']) antispam_empreinte($form, $texte);
    $email = strtolower(trim((string) ($donnees['email'] ?? '')));
    if ($email !== '') {
        $dom = substr(strrchr($email, '@') ?: '', 1);
        foreach (antispam_domaines_bloques() as $b) {
            if ($dom === $b || str_ends_with($dom, '.' . $b)) return $suspect('domaine_bloque');
        }
    }
    $champs = array_map('strval', (array) ($donnees['champs'] ?? []));
    foreach (array_merge([$email], $champs) as $v) {
        if (preg_match('/[\r\n]/', $v)) return $suspect('injection');
    }
    foreach ($champs as $v) {
        if (antispam_nb_liens($v) > 0) return $suspect('lien_champ_court');
    }
    if (antispam_nb_liens($texte) > ANTISPAM_MAX_LIENS) return $suspect('trop_de_liens');
    if ($doublon) return $suspect('doublon');
    return ['statut' => 'ok', 'motif' => '', 'libelle' => ''];
}

/** Libellé en français d'un motif (journal, admin, notifications). */
function antispam_libelle(string $motif): string {
    $motif = preg_replace('/^suspect_/', '', $motif);
    return [
        'domaine_bloque' => 'adresse e-mail jetable', 'injection' => 'saut de ligne dans un champ court',
        'lien_champ_court' => 'lien dans le nom ou un champ court', 'trop_de_liens' => 'plus de 2 liens',
        'doublon' => 'message déjà reçu', 'quota_ip' => 'trop d\'envois depuis la même connexion',
        'champ_piege' => 'robot (champ invisible rempli)', 'jeton_absent' => 'robot (envoi hors formulaire)',
        'jeton_faux' => 'robot (envoi hors formulaire)', 'trop_rapide' => 'robot (envoi trop rapide)',
        'expire' => 'page ouverte trop longtemps',
    ][$motif] ?? $motif;
}

/** Préfixe à mettre devant le sujet d'une notification : '' si le message est normal. */
function antispam_prefixe(array $resultat): string {
    return ($resultat['statut'] ?? '') === 'suspect' ? '[Spam probable : ' . $resultat['libelle'] . '] ' : '';
}

function antispam_normaliser(string $texte): string {
    $t = preg_replace('/\s+/u', ' ', trim($texte)) ?? trim($texte);
    $t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    return strlen($t) >= 20 ? hash('sha256', $t) : '';
}

/** Même texte déjà accepté sur ce formulaire dans les 7 derniers jours ? */
function antispam_doublon(string $form, string $texte): bool {
    $h = antispam_normaliser($texte);
    if ($h === '') return false;
    $f = ANTISPAM_DIR . '/empreintes.json';
    $data = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $t = $data[$form . ':' . substr($h, 0, 24)] ?? 0;
    return $t > time() - 7 * 86400;
}

function antispam_empreinte(string $form, string $texte): void {
    $h = antispam_normaliser($texte);
    if ($h === '') return;
    $f = antispam_dossier() . '/empreintes.json';
    $fh = @fopen($f, 'c+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fh), true) ?: [];
    $lim = time() - 7 * 86400;
    $data = array_filter($data, fn($x) => $x > $lim);
    $data[$form . ':' . substr($h, 0, 24)] = time();
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
}

/** Peut-on écrire à l'adresse saisie par le visiteur en reprenant ce texte ? Jamais s'il contient un lien. */
function antispam_copie_autorisee(string ...$textes): bool {
    foreach ($textes as $t) if (antispam_nb_liens($t) > 0) return false;
    return true;
}

function antispam_quota(string $form): bool {
    $f = antispam_dossier() . '/quota.json';
    $h = @fopen($f, 'c+');
    if (!$h) return true; // ne jamais bloquer un vrai visiteur à cause du disque
    flock($h, LOCK_EX);
    $data = json_decode((string) stream_get_contents($h), true) ?: [];
    $now = time();
    $cle = $form . ':' . substr(hash_hmac('sha256', antispam_ip(), antispam_cle()), 0, 20);
    // Nettoyage des entrées de plus de 24 h
    foreach ($data as $k => $ts) {
        $data[$k] = array_values(array_filter((array) $ts, fn($x) => $x > $now - 86400));
        if (!$data[$k]) unset($data[$k]);
    }
    $ts = $data[$cle] ?? [];
    $heure = count(array_filter($ts, fn($x) => $x > $now - 3600));
    $ok = $heure < ANTISPAM_MAX_HEURE && count($ts) < ANTISPAM_MAX_JOUR;
    if ($ok) { $ts[] = $now; $data[$cle] = $ts; }
    ftruncate($h, 0); rewind($h);
    fwrite($h, json_encode($data));
    fflush($h); flock($h, LOCK_UN); fclose($h);
    return $ok;
}

/** Compteur des rejets par jour, formulaire et motif (pour l'admin ou un contrôle à la main). */
function antispam_journal(string $form, string $motif): void {
    $f = antispam_dossier() . '/journal.json';
    $h = @fopen($f, 'c+');
    if (!$h) return;
    flock($h, LOCK_EX);
    $data = json_decode((string) stream_get_contents($h), true) ?: [];
    $jour = date('Y-m-d');
    $data[$jour][$form][$motif] = ($data[$jour][$form][$motif] ?? 0) + 1;
    // On garde 60 jours
    ksort($data);
    $data = array_slice($data, -60, null, true);
    ftruncate($h, 0); rewind($h);
    fwrite($h, json_encode($data, JSON_PRETTY_PRINT));
    fflush($h); flock($h, LOCK_UN); fclose($h);
}

/** Résumé des 30 derniers jours : [['jour', 'form', 'motif', 'n', 'suspect' => bool (transmis) ou false (bloqué), 'libelle'], ...] */
function antispam_resume(int $jours = 30): array {
    $f = ANTISPAM_DIR . '/journal.json';
    $data = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $out = [];
    foreach (array_slice($data, -$jours, null, true) as $jour => $forms) {
        foreach ($forms as $form => $motifs) {
            foreach ($motifs as $motif => $n) {
                $suspect = str_starts_with($motif, 'suspect_');
                $libelle = antispam_libelle($motif);
                $out[] = compact('jour', 'form', 'motif', 'n', 'suspect', 'libelle');
            }
        }
    }
    return array_reverse($out);
}
