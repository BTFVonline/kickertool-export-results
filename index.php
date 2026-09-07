<?php
/**
 * Kickertool Ergebnisse-Downloader
 * Features: Vereins-Konfiguration, Turnier-Auswahl, Download-History,
 *           ZIP-Download, E-Mail-Versand, Cron-Endpoint.
 */

session_start();

const API_BASE = 'https://api.tournament.io/v1/table_soccer/result';

// Ablageort fuer Konfiguration, Verlauf und Cron-Token. Enthaelt Zugangsdaten
// (SMTP-Passwort), liegt daher idealerweise ausserhalb des Webroots:
//   SetEnv KICKERTOOL_DATA_DIR /home/user/kickertool-data   (.htaccess / vHost)
define('DATA_DIR',   getenv('KICKERTOOL_DATA_DIR') ?: __DIR__ . '/data');
define('CFG_FILE',   DATA_DIR . '/config.json');
define('HIST_FILE',  DATA_DIR . '/history.json');
define('TOKEN_FILE', DATA_DIR . '/token.txt');
define('KEY_FILE',   DATA_DIR . '/secret.key');

// ─── Bootstrap data dir ──────────────────────────────────────────────────────

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);

// Protect data/ from direct HTTP access (Apache 2.2 and 2.4).
// Rewritten when outdated so existing installs also get the 2.4 syntax.
$htaccess      = DATA_DIR . '/.htaccess';
$htaccess_body = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
               . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
if (!file_exists($htaccess) || file_get_contents($htaccess) !== $htaccess_body)
    file_put_contents($htaccess, $htaccess_body);

// ─── Secret encryption ───────────────────────────────────────────────────────

// Das SMTP-Passwort muss zur Laufzeit im Klartext an den Mailserver gehen, ist also
// zwingend umkehrbar verschluesselt und kein Hash. Der Schutz greift genau gegen
// einen Fall: config.json faellt jemandem in die Haende (falsch gesetzter
// Verzeichnisschutz, Backup, versehentlicher Commit), der Schluessel aber nicht.
// Gegen vollen Dateizugriff oder Codeausfuehrung auf dem Server hilft er nicht.
//
// Schluesselquelle, in dieser Reihenfolge:
//   1. Umgebungsvariable KICKERTOOL_SECRET_KEY - empfohlen, denn dann liegt der
//      Schluessel gar nicht erst im Dateisystem neben der Konfiguration
//   2. data/secret.key, wird beim ersten Mal automatisch erzeugt
const SECRET_PREFIX = 'enc:v1:';

function secret_key(): string {
    $env = getenv('KICKERTOOL_SECRET_KEY');
    if (is_string($env) && $env !== '') return hash('sha256', $env, true);

    if (!file_exists(KEY_FILE)) {
        file_put_contents(KEY_FILE, base64_encode(random_bytes(32)));
        @chmod(KEY_FILE, 0600);
    }
    $stored = (string)file_get_contents(KEY_FILE);
    $raw    = base64_decode(trim($stored), true);
    return ($raw !== false && strlen($raw) === 32) ? $raw : hash('sha256', $stored, true);
}

function encrypt_secret(string $plain): string {
    if ($plain === '' || !function_exists('openssl_encrypt')) return $plain;
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $ct === false ? $plain : SECRET_PREFIX . base64_encode($iv . $tag . $ct);
}

// Leerer Rueckgabewert = nicht entschluesselbar (Schluessel weg oder getauscht).
// Die Oberflaeche zeigt das Passwort dann als nicht gesetzt an; es muss neu rein.
function decrypt_secret(string $stored): string {
    if (!str_starts_with($stored, SECRET_PREFIX)) return $stored; // Altbestand: Klartext
    if (!function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($stored, strlen(SECRET_PREFIX)), true);
    if ($raw === false || strlen($raw) <= 28) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA,
                             substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

// ─── Persistence ────────────────────────────────────────────────────────────

// Ausserhalb dieser beiden Funktionen ist $config['smtp_pass'] immer Klartext.
function load_config(): array {
    if (!file_exists(CFG_FILE)) return ['clubs' => [], 'filter' => [], 'filter_from_date' => '', 'email' => ''];
    $c = json_decode(file_get_contents(CFG_FILE), true) ?: [];
    if (!empty($c['smtp_pass'])) {
        $was_plaintext  = !str_starts_with($c['smtp_pass'], SECRET_PREFIX);
        $c['smtp_pass'] = decrypt_secret($c['smtp_pass']);
        // Bestandsinstallation: Klartext-Passwort einmalig verschluesselt nachziehen
        if ($was_plaintext && function_exists('openssl_encrypt')) save_config($c);
    }
    return $c;
}
function save_config(array $c): void {
    if (!empty($c['smtp_pass']) && !str_starts_with($c['smtp_pass'], SECRET_PREFIX))
        $c['smtp_pass'] = encrypt_secret($c['smtp_pass']);
    file_put_contents(CFG_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod(CFG_FILE, 0600);
}

function load_history(): array {
    if (!file_exists(HIST_FILE)) return [];
    return json_decode(file_get_contents(HIST_FILE), true) ?: [];
}
function save_history(array $h): void {
    file_put_contents(HIST_FILE, json_encode($h, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function get_token(): string {
    if (!file_exists(TOKEN_FILE)) file_put_contents(TOKEN_FILE, bin2hex(random_bytes(20)));
    return trim(file_get_contents(TOKEN_FILE));
}

// ─── API helpers ─────────────────────────────────────────────────────────────

function api_get(string $url): mixed {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'kickertool-downloader/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? json_decode($body, true) : null;
}

function api_bytes(string $url): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'kickertool-downloader/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body !== false) ? $body : false;
}

// ─── Utilities ───────────────────────────────────────────────────────────────

function club_slug(string $url): string { return trim(parse_url($url, PHP_URL_PATH), '/'); }

function sanitize(string $s): string { return trim(preg_replace('/[\\\\\\/:*?"<>|]/', '_', $s)); }

function t_dir(array $t): string {
    $d = '';
    if (!empty($t['date'])) {
        try { $d = (new DateTime($t['date']))->format('Y-m-d') . '_'; } catch (Exception) {}
    }
    return sanitize($d . ($t['name'] ?? 'unbekannt'));
}

function fetch_tournaments(array $clubs): array {
    $out = [];
    foreach ($clubs as $url) {
        $slug = club_slug($url);
        $data = api_get(API_BASE . '/page/' . $slug);
        if (!$data) continue;
        foreach ($data['tournaments'] ?? [] as $t) {
            $t['_slug']      = $slug;
            $t['_club_name'] = $data['name'] ?? $slug;
            $out[]           = $t;
        }
    }
    return $out;
}

function live_url(array $t): string {
    $base = 'https://live.kickertool3.de/' . $t['_slug'];
    if (!empty($t['disciplines'][0]['_id']))
        return $base . '/tournaments/' . $t['_id'] . '/disciplines/' . $t['disciplines'][0]['_id'] . '/overview';
    return $base;
}

function tournament_in_filter(array $t, array $fl, string $from_date): bool {
    if ($fl) {
        $m = false;
        foreach ($fl as $f) if (str_contains(strtolower($t['name']), $f)) { $m = true; break; }
        if (!$m) return false;
    }
    if ($from_date && ($t['date'] ?? '') < $from_date) return false;
    return true;
}

function fmt_date(string $iso): string {
    try { return (new DateTime($iso))->format('d.m.Y'); } catch (Exception) { return $iso; }
}

function he(string $s): string { return htmlspecialchars($s); }

function render_t_row(array $t, string $cat, array $history, string $from_date): void {
    $id   = $t['_id'];
    $state = $t['state'] ?? 'planned';
    $month = !empty($t['date']) ? substr($t['date'], 0, 7) : '';
    $meta  = (!empty($t['date']) ? fmt_date($t['date']) : '') . ' &middot; ' . he($t['_club_name']);
    $live  = '<a href="' . he(live_url($t)) . '" target="_blank" rel="noopener" class="btn-live"'
           . ' title="Auf live.kickertool3.de ansehen" onclick="event.stopPropagation()">↗</a>';
    $da    = 'data-club="' . he($t['_club_name']) . '" data-month="' . he($month) . '" data-date="' . he($t['date'] ?? '') . '"';
    $wrap  = '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:.35rem">';

    if ($cat === 'included') {
        echo "<label class=\"t-row\" $da>"
           . '<input type="checkbox" name="tids[]" value="' . he($id) . '" checked data-new="1">'
           . '<div><div class="t-name">' . he($t['name']) . '</div><div class="t-meta">' . $meta . '</div></div>'
           . $wrap . ($state === 'running' ? '<span class="badge badge-running">Läuft</span>' : '')
           . '<span class="badge badge-new">Neu</span>' . $live . '</div></label>';
    } elseif ($cat === 'exported') {
        $exp_date = he($history[$id]['exported_at'] ?? '');
        echo "<label class=\"t-row t-exported\" $da>"
           . '<input type="checkbox" name="tids[]" value="' . he($id) . '" data-new="0">'
           . '<div><div class="t-name">' . he($t['name']) . '</div>'
           . '<div class="t-meta">' . $meta . ' &middot; exportiert am ' . $exp_date . '</div></div>'
           . $wrap . '<span class="badge badge-done">Exportiert</span>' . $live
           . '<a href="?action=reset_one&tid=' . urlencode($id) . '" class="btn btn-sm btn-danger"'
           . ' title="Als &quot;neu&quot; markieren" onclick="return confirm(\'Turnier als neu markieren?\')">↺</a>'
           . '</div></label>';
    } else {
        $reason = $state === 'planned'                                    ? '<span class="badge badge-planned">Geplant</span>'
                : ($from_date && ($t['date'] ?? '') < $from_date         ? '<span class="badge badge-filtered">Vor Startdatum</span>'
                :                                                           '<span class="badge badge-filtered">Kein Treffer</span>');
        echo "<div class=\"t-row t-filtered\" $da><span style=\"width:1.1rem\"></span>"
           . '<div><div class="t-name">' . he($t['name']) . '</div><div class="t-meta">' . $meta . '</div></div>'
           . $wrap . $reason . $live . '</div></div>';
    }
    echo "\n";
}

// ─── Pure-PHP ZIP writer (no ZipArchive extension needed) ────────────────────

class PureZip {
    private array $entries = [];

    public function addFromString(string $name, string $data): void {
        $crc   = crc32($data);
        $usize = strlen($data);
        // Use deflate if available, else store uncompressed
        if (function_exists('gzdeflate') && $usize > 64) {
            $cdata  = gzdeflate($data, 6);
            $method = 8;
        } else {
            $cdata  = $data;
            $method = 0;
        }
        $this->entries[] = ['name' => $name, 'cdata' => $cdata, 'crc' => $crc,
                            'usize' => $usize, 'csize' => strlen($cdata), 'method' => $method];
    }

    public function getBytes(): string {
        $local = ''; $central = ''; $offsets = [];
        foreach ($this->entries as $e) {
            $offsets[] = strlen($local);
            $nlen  = strlen($e['name']);
            $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $e['method'], 0, 0,
                           $e['crc'], $e['csize'], $e['usize'], $nlen, 0)
                    . $e['name'] . $e['cdata'];
        }
        foreach ($this->entries as $i => $e) {
            $nlen    = strlen($e['name']);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $e['method'], 0, 0,
                             $e['crc'], $e['csize'], $e['usize'], $nlen, 0, 0, 0, 0, 0, $offsets[$i])
                      . $e['name'];
        }
        $n    = count($this->entries);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($local), 0);
        return $local . $central . $eocd;
    }

    public function saveTo(string $path): bool {
        return file_put_contents($path, $this->getBytes()) !== false;
    }
}

// ─── Pure-PHP ZIP reader ──────────────────────────────────────────────────────
// Reads sizes from the Central Directory, not Local Headers —
// required because the API ZIPs use Data Descriptors (flag 0x0008)
// which leaves csize/usize as 0 in the local headers.

function parse_zip_entries(string $bytes): array {
    $result = [];
    $len    = strlen($bytes);

    // 1. Find End of Central Directory (PK\x05\x06) — scan from end
    $eocd_pos = false;
    for ($i = $len - 22; $i >= max(0, $len - 22 - 65536); $i--) {
        if (substr($bytes, $i, 4) === "\x50\x4b\x05\x06") { $eocd_pos = $i; break; }
    }
    if ($eocd_pos === false) return [];

    $eocd      = unpack('vdisk/vdisk_cd/vcount_here/vcount_total/Vcd_size/Vcd_offset', substr($bytes, $eocd_pos + 4, 18));
    $cd_offset = $eocd['cd_offset'];
    $cd_count  = $eocd['count_total'];

    // 2. Parse Central Directory entries (authoritative for csize/usize/method/offset)
    $pos = $cd_offset;
    for ($i = 0; $i < $cd_count; $i++) {
        if ($pos + 46 > $len) break;
        if (substr($bytes, $pos, 4) !== "\x50\x4b\x01\x02") break;

        $cd = unpack('vver_made/vver_need/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint_attr/Vext_attr/Vlh_offset',
                     substr($bytes, $pos + 4, 42));

        $name   = substr($bytes, $pos + 46, $cd['nlen']);
        $lh_off = $cd['lh_offset'];

        // 3. Find actual data start via local header at lh_offset
        if ($lh_off + 30 <= $len && substr($bytes, $lh_off, 4) === "\x50\x4b\x03\x04") {
            $lh = unpack('vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen',
                         substr($bytes, $lh_off + 4, 26));
            $data_start = $lh_off + 30 + $lh['nlen'] + $lh['xlen'];
            $cdata      = substr($bytes, $data_start, $cd['csize']); // use CD size, not LH

            $data = match($cd['method']) {
                8       => @gzinflate($cdata),
                default => $cdata,
            };
            if ($data !== false && $data !== '') $result[$name] = $data;
        }

        $pos += 46 + $cd['nlen'] + $cd['xlen'] + $cd['clen'];
    }
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────

function build_zip(array $tournaments, array $ids): string|false {
    $zip   = new PureZip();
    $added = 0;
    foreach ($tournaments as $t) {
        if (!in_array($t['_id'], $ids, true)) continue;
        $bytes = api_bytes(API_BASE . '/tournaments/' . $t['_id'] . '/export/sport-xml');
        if ($bytes === false) continue;
        $dir     = sanitize($t['_slug']) . '/' . t_dir($t);
        $entries = parse_zip_entries($bytes);
        foreach ($entries as $name => $data)
            $zip->addFromString($dir . '/' . sanitize($name), $data);
        if ($entries) $added++;
    }
    if ($added === 0) return false;
    $tmp = tempnam(sys_get_temp_dir(), 'kt_') . '.zip';
    return $zip->saveTo($tmp) ? $tmp : false;
}

// ─── Pure-PHP SMTP client ─────────────────────────────────────────────────────

function smtp_send(array $cfg, string $to, string $subject, string $mime_body): true|string {
    $host    = $cfg['smtp_host']   ?? '';
    $port    = (int)($cfg['smtp_port']   ?? 587);
    $user    = $cfg['smtp_user']   ?? '';
    $pass    = $cfg['smtp_pass']   ?? '';
    $from    = $cfg['smtp_from']   ?? $user;
    $secure  = $cfg['smtp_secure'] ?? 'tls'; // 'tls' | 'ssl' | 'none'

    $errno = 0; $errstr = '';
    $addr  = $secure === 'ssl' ? "ssl://$host" : $host;
    $sock  = @fsockopen($addr, $port, $errno, $errstr, 15);
    if (!$sock) return "Verbindung fehlgeschlagen: $errstr ($errno)";

    $r = function() use ($sock) { return fgets($sock, 1024); };
    $w = function(string $s) use ($sock) { fwrite($sock, $s . "\r\n"); };

    $expect = function(string $line, string $code) {
        if (!str_starts_with($line, $code)) throw new RuntimeException("SMTP: erwartet $code, got: $line");
    };

    try {
        $expect($r(), '220');

        $w("EHLO localhost");
        while (true) { $l = $r(); if (substr($l,3,1) === ' ') break; }

        if ($secure === 'tls') {
            $w("STARTTLS");
            $expect($r(), '220');
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $w("EHLO localhost");
            while (true) { $l = $r(); if (substr($l,3,1) === ' ') break; }
        }

        if ($user !== '') {
            $w("AUTH LOGIN");
            $expect($r(), '334');
            $w(base64_encode($user));
            $expect($r(), '334');
            $w(base64_encode($pass));
            $expect($r(), '235');
        }

        $w("MAIL FROM:<$from>");   $expect($r(), '250');
        $w("RCPT TO:<$to>");       $expect($r(), '250');
        $w("DATA");                $expect($r(), '354');
        $w($mime_body . "\r\n."); $expect($r(), '250');
        $w("QUIT");                $r();
    } catch (RuntimeException $e) {
        fclose($sock);
        return $e->getMessage();
    }

    fclose($sock);
    return true;
}

function send_zip_mail(array $smtp_cfg, string $to, string $zip_path, string $filename): true|string {
    $from = $smtp_cfg['smtp_from'] ?? ($smtp_cfg['smtp_user'] ?? 'kickertool@noreply.local');
    $b    = md5(uniqid('', true));
    $mime = "From: $from\r\nTo: $to\r\n"
          . "Subject: Kickertool Ergebnisse " . date('d.m.Y') . "\r\n"
          . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n"
          . "\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
          . "Kickertool Ergebnisse – " . date('d.m.Y') . "\r\n"
          . "Im Anhang befinden sich die neuen Turnierergebnisse.\r\n"
          . "--$b\r\nContent-Type: application/zip\r\n"
          . "Content-Transfer-Encoding: base64\r\n"
          . "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n"
          . chunk_split(base64_encode(file_get_contents($zip_path)))
          . "\r\n--$b--";
    return smtp_send($smtp_cfg, $to, 'Kickertool Ergebnisse ' . date('d.m.Y'), $mime);
}

function mark_exported(array &$history, array $tournaments, array $ids): void {
    foreach ($tournaments as $t)
        if (in_array($t['_id'], $ids, true))
            $history[$t['_id']] = ['exported_at' => date('Y-m-d'), 'name' => $t['name'], 'club' => $t['_club_name']];
}

// ─── Action dispatch ─────────────────────────────────────────────────────────

$config  = load_config();
$history = load_history();
$token   = get_token();

// PRG: read flash from session (set by POST handlers before redirect)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function redirect_with_flash(string $type, string $text): never {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── save config ──
if ($action === 'save_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['clubs']            = array_values(array_filter(array_map('trim', explode("\n", $_POST['clubs']  ?? ''))));
    $config['filter']           = array_values(array_filter(array_map('trim', explode("\n", $_POST['filter'] ?? ''))));
    $config['filter_from_date'] = trim($_POST['filter_from_date'] ?? '');
    $config['email']            = trim($_POST['email']            ?? '');
    $config['smtp_host']        = trim($_POST['smtp_host']        ?? '');
    $config['smtp_port']        = trim($_POST['smtp_port']        ?? '587');
    $config['smtp_secure']      = trim($_POST['smtp_secure']      ?? 'tls');
    $config['smtp_user']        = trim($_POST['smtp_user']        ?? '');
    $config['smtp_from']        = trim($_POST['smtp_from']        ?? '');
    if (trim($_POST['smtp_pass'] ?? '') !== '') $config['smtp_pass'] = trim($_POST['smtp_pass']);
    save_config($config);
    redirect_with_flash('ok', 'Einstellungen gespeichert.');
}

// ── reset single history entry (GET to avoid nested-form issue) ──
if (($_GET['action'] ?? '') === 'reset_one' && isset($_GET['tid'])) {
    $id = $_GET['tid'];
    if ($id && isset($history[$id])) { unset($history[$id]); save_history($history); }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

// ── reset full history ──
if ($action === 'reset_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    save_history([]);
    redirect_with_flash('ok', 'Download-Verlauf zurückgesetzt.');
}

// ── download ZIP ──
if ($action === 'download' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids  = $_POST['tids'] ?? [];
    $all  = fetch_tournaments($config['clubs']);
    $path = $ids ? build_zip($all, $ids) : false;
    if ($path) {
        mark_exported($history, $all, $ids); save_history($history);
        $name = 'kickertool_' . date('Y-m-d') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path); unlink($path); exit;
    }
    redirect_with_flash('err', empty($ids) ? 'Keine Turniere ausgewählt.' : 'Download fehlgeschlagen.');
}

// ── send email ──
if ($action === 'email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['tids'] ?? [];
    if (empty($config['email'])) {
        redirect_with_flash('err', 'Keine E-Mail-Adresse konfiguriert (Einstellungen).');
    } elseif (empty($ids)) {
        redirect_with_flash('err', 'Keine Turniere ausgewählt.');
    } else {
        $all  = fetch_tournaments($config['clubs']);
        $path = build_zip($all, $ids);
        if ($path) {
            $name   = 'kickertool_' . date('Y-m-d') . '.zip';
            $result = send_zip_mail($config, $config['email'], $path, $name);
            unlink($path);
            if ($result === true) {
                mark_exported($history, $all, $ids); save_history($history);
                redirect_with_flash('ok', 'E-Mail gesendet an ' . $config['email'] . '.');
            } else {
                redirect_with_flash('err', 'E-Mail fehlgeschlagen: ' . $result);
            }
        } else {
            redirect_with_flash('err', 'ZIP-Erstellung fehlgeschlagen.');
        }
    }
}

// ── cron endpoint ──
if (isset($_GET['cron'])) {
    if (($_GET['token'] ?? '') !== $token) { http_response_code(403); echo "Unauthorized\n"; exit; }

    // Exclusive lock prevents duplicate sends when cron fires multiple times concurrently
    $lock = fopen(DATA_DIR . '/cron.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { echo "OK: Bereits in Ausführung.\n"; exit; }

    $history   = load_history(); // reload after acquiring lock
    $all       = fetch_tournaments($config['clubs']);
    $fl        = array_map('strtolower', $config['filter'] ?? []);
    $from_date = $config['filter_from_date'] ?? '';
    $new_ids   = [];
    foreach ($all as $t) {
        if ($t['state'] !== 'finished')                 continue;
        if (isset($history[$t['_id']]))                 continue;
        if (!tournament_in_filter($t, $fl, $from_date)) continue;
        $new_ids[] = $t['_id'];
    }
    if (empty($new_ids))         { flock($lock, LOCK_UN); echo "OK: Keine neuen Turniere.\n"; exit; }
    if (empty($config['email'])) { flock($lock, LOCK_UN); echo "FEHLER: Keine E-Mail-Adresse konfiguriert.\n"; exit; }
    $path = build_zip($all, $new_ids);
    if (!$path)                  { flock($lock, LOCK_UN); echo "FEHLER: ZIP-Erstellung fehlgeschlagen.\n"; exit; }
    $name   = 'kickertool_' . date('Y-m-d') . '.zip';
    $result = send_zip_mail($config, $config['email'], $path, $name);
    unlink($path);
    if ($result === true) {
        mark_exported($history, $all, $new_ids); save_history($history);
        echo "OK: " . count($new_ids) . " Turnier(e) gesendet.\n";
    } else {
        echo "FEHLER: $result\n";
    }
    flock($lock, LOCK_UN);
    exit;
}

// ─── Prepare page data ───────────────────────────────────────────────────────

$all_tournaments = fetch_tournaments($config['clubs']);

// Sort: newest first
usort($all_tournaments, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

$fl_page        = array_map('strtolower', $config['filter'] ?? []);
$from_date_page = $config['filter_from_date'] ?? '';

// Categorise into three buckets
$cat_included = []; // matches filter, not exported, finished/running
$cat_exported = []; // already in history (regardless of filter)
$cat_filtered = []; // doesn't match filter or planned, not exported

foreach ($all_tournaments as $t) {
    $id    = $t['_id'];
    $state = $t['state'] ?? 'planned';
    if (isset($history[$id])) {
        $cat_exported[] = $t;
    } elseif ($state !== 'planned' && tournament_in_filter($t, $fl_page, $from_date_page)) {
        $cat_included[] = $t;
    } else {
        $cat_filtered[] = $t;
    }
}

// Build human-readable filter description
$filter_parts = [];
if ($config['filter'] ?? []) $filter_parts[] = implode(', ', array_map(fn($f) => "\u{201E}$f\u{201C}", $config['filter']));
if ($from_date_page)         $filter_parts[] = 'ab ' . (function($d) {
    try { return (new DateTime($d))->format('d.m.Y'); } catch (Exception) { return $d; }
})($from_date_page);
$filter_desc = $filter_parts ? implode(' · ', $filter_parts) : null;

$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
$cron_url = $base_url . '?cron=1&token=' . $token;

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kickertool Ergebnisse</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --green:   #5b8f41; --green-d: #4b7b38; --green-l: #eef6e9;
  --red:     #dc2626; --red-l:   #fff0f0;
  --blue:    #2563eb; --blue-l:  #eff6ff;
  --amber:   #b45309; --amber-l: #fef3c7;
  --bg:      #f3f4f6; --surface: #ffffff; --surface2: #f8fafc;
  --border:  #e5e7eb; --text:    #1f2937; --muted:    #6b7280;
  --label:   #374151; --cron-text: #374151;
  --badge-done-bg: #f3f4f6; --badge-run-bg: #fef3c7; --badge-run-fg: #b45309;
  --flash-ok-fg: #2d6a1f; --flash-ok-border: #b7dba0;
  --shadow:  0 1px 6px rgba(0,0,0,.08);
  --radius:  10px;
}

@media (prefers-color-scheme: dark) {
  :root {
    --green-l:  #1a2e14;
    --red-l:    #2d1111;
    --blue-l:   #1e2d4a;
    --amber-l:  #2d2510;
    --bg:       #0f1117; --surface: #1a1d27; --surface2: #141720;
    --border:   #2d3248; --text:    #e8eaf0; --muted:    #8b90a8;
    --label:    #b0b5cb; --cron-text: #b0b5cb;
    --badge-done-bg: #252838; --badge-run-bg: #2d2510; --badge-run-fg: #d97706;
    --flash-ok-fg: #86efac; --flash-ok-border: #166534;
    --shadow:   0 1px 8px rgba(0,0,0,.4);
  }
}

body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

/* Layout */
.wrap   { max-width: 860px; margin: 0 auto; padding: 2rem 1rem 4rem; }
h1      { font-size: 1.5rem; font-weight: 700; }
.sub    { color: var(--muted); font-size: .9rem; margin-top: .25rem; }

/* Tabs */
.tabs        { display: flex; gap: .5rem; margin: 1.5rem 0 1rem; border-bottom: 2px solid var(--border); }
.tab         { padding: .6rem 1.2rem; border-radius: var(--radius) var(--radius) 0 0; cursor: pointer; font-weight: 600; font-size: .9rem; color: var(--muted); background: none; border: none; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: color .15s, border-color .15s; }
.tab.active  { color: var(--green); border-bottom-color: var(--green); }
.tab-panel   { display: none; }
.tab-panel.active { display: block; }

/* Cards */
.card { background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow); padding: 1.5rem; margin-bottom: 1.25rem; }
.card-title { font-weight: 700; font-size: 1rem; margin-bottom: 1rem; }

/* Form elements */
label     { display: block; font-size: .83rem; font-weight: 600; color: var(--label); margin-bottom: .35rem; }
.hint     { font-size: .77rem; color: var(--muted); margin-bottom: .5rem; }
textarea, input[type=email], input[type=text], input[type=password], input[type=date] {
  width: 100%; border: 1.5px solid var(--border); border-radius: 8px;
  padding: .6rem .85rem; font-size: .92rem; font-family: inherit;
  transition: border-color .15s; margin-bottom: 1rem;
  background: var(--surface); color: var(--text);
}
input[type=date] { font-family: inherit; }
select {
  background: var(--surface); color: var(--text); border-color: var(--border);
}
textarea:focus, input:focus { outline: none; border-color: var(--green); }
textarea { resize: vertical; }

/* Buttons */
.btn         { display: inline-flex; align-items: center; gap: .4rem; padding: .6rem 1.1rem; border-radius: 8px; font-size: .92rem; font-weight: 600; cursor: pointer; border: none; transition: background .15s; text-decoration: none; }
.btn-primary { background: var(--green); color: #fff; }
.btn-primary:hover { background: var(--green-d); }
.btn-outline  { background: var(--surface); color: var(--green); border: 1.5px solid var(--green); }
.btn-outline:hover { background: var(--green-l); }
.btn-sm       { padding: .35rem .7rem; font-size: .8rem; }
.btn-danger   { background: var(--surface); color: var(--red); border: 1.5px solid #fca5a5; }
.btn-danger:hover { background: var(--red-l); }
.btn-row      { display: flex; gap: .65rem; flex-wrap: wrap; margin-top: .75rem; }

/* Flash message */
.flash        { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: .9rem; }
.flash.ok     { background: var(--green-l); color: var(--flash-ok-fg); border: 1px solid var(--flash-ok-border); }
.flash.err    { background: var(--red-l);   color: var(--red); border: 1px solid #fca5a5; }

/* Tournament list */
.t-list        { display: flex; flex-direction: column; gap: .5rem; }
.t-row         { display: grid; grid-template-columns: auto 1fr auto; gap: .75rem; align-items: center; padding: .75rem 1rem; border-radius: 8px; border: 1.5px solid var(--border); transition: border-color .15s; background: var(--surface); }
.t-row:hover   { border-color: var(--green); }
.t-name        { font-weight: 600; font-size: .95rem; }
.t-meta        { font-size: .82rem; color: var(--muted); margin-top: .15rem; }
.badge         { display: inline-block; padding: .2rem .55rem; border-radius: 99px; font-size: .75rem; font-weight: 700; white-space: nowrap; }
.badge-new     { background: var(--green-l); color: var(--green); }
.badge-done    { background: var(--badge-done-bg); color: var(--muted); }
.badge-running { background: var(--badge-run-bg); color: var(--badge-run-fg); }
.badge-planned { background: var(--badge-done-bg); color: var(--muted); }
.badge-filtered { background: var(--blue-l); color: var(--blue); }

/* Row dim variants */
.t-row.t-exported { opacity: .7; }
.t-row.t-filtered { opacity: .5; cursor: default; }
.t-row.t-filtered:hover { border-color: var(--border); }

/* Group heading (injected by JS) */
.group-head { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin: .75rem 0 .35rem; padding-left: .25rem; }
.group-head:first-child { margin-top: 0; }

/* Category section headers */
.cat-head { display: flex; align-items: center; gap: .65rem; margin: 1.5rem 0 .6rem; }
.cat-head:first-of-type { margin-top: 0; }
.cat-title { font-weight: 700; font-size: .95rem; }
.cat-count { font-size: .78rem; color: var(--muted); background: var(--surface2); border: 1px solid var(--border); border-radius: 99px; padding: .1rem .5rem; }
.cat-desc { font-size: .8rem; color: var(--muted); }
.cat-empty { font-size: .88rem; color: var(--muted); padding: .6rem .25rem; font-style: italic; }

/* Grouping bar */
.grouping-bar { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem; font-size: .85rem; color: var(--muted); }
.group-btn { background: none; border: 1.5px solid var(--border); border-radius: 6px; padding: .25rem .7rem; font-size: .82rem; cursor: pointer; color: var(--muted); transition: all .15s; font-family: inherit; }
.group-btn.active { border-color: var(--green); color: var(--green); background: var(--green-l); font-weight: 600; }

/* Select controls */
.select-bar    { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .85rem; align-items: center; }
.select-bar span { font-size: .85rem; color: var(--muted); }

/* Collapsible filtered section */
details.cat-details > summary { list-style: none; cursor: pointer; user-select: none; }
details.cat-details > summary::-webkit-details-marker { display: none; }
details.cat-details > summary .cat-chevron { display: inline-block; transition: transform .2s; margin-left: auto; color: var(--muted); font-size: .8rem; }
details.cat-details[open] > summary .cat-chevron { transform: rotate(90deg); }

/* Live-link icon button */
.btn-live { display: inline-flex; align-items: center; justify-content: center; width: 1.8rem; height: 1.8rem; border-radius: 6px; border: 1.5px solid var(--border); background: var(--surface); color: var(--muted); text-decoration: none; font-size: .8rem; transition: all .15s; flex-shrink: 0; }
.btn-live:hover { border-color: var(--green); color: var(--green); background: var(--green-l); }

/* Cron box */
.cron-box { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: .75rem 1rem; font-family: monospace; font-size: .82rem; word-break: break-all; color: var(--cron-text); margin-top: .5rem; }
</style>
</head>
<body>
<div class="wrap">

  <h1>Kickertool Ergebnisse</h1>
  <p class="sub">Sport-XML Ergebnisse direkt von live.kickertool3.de</p>

  <?php if ($flash): ?>
  <div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>" style="margin-top:1rem">
    <?= htmlspecialchars($flash['text']) ?>
  </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab active" onclick="showTab('turniere', this)">Turniere</button>
    <button class="tab" onclick="showTab('einstellungen', this)">Einstellungen</button>
    <button class="tab" onclick="showTab('automation', this)">Automatisierung</button>
  </div>

  <!-- ══════════════════════════════════════════════════════════ TURNIERE -->
  <div id="tab-turniere" class="tab-panel active">

    <?php if (empty($config['clubs'])): ?>
    <div class="card">
      <p>Noch keine Vereine konfiguriert. Bitte zuerst <strong>Einstellungen</strong> öffnen.</p>
    </div>
    <?php elseif (empty($all_tournaments)): ?>
    <div class="card">
      <p>Keine Turniere gefunden (API-Fehler oder keine Turniere vorhanden).</p>
    </div>
    <?php else: ?>

    <form method="POST">
      <input type="hidden" name="action" value="download">

      <!-- Grouping + selection controls -->
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
        <div class="select-bar" style="margin-bottom:0">
          <span>Auswahl:</span>
          <button type="button" class="btn btn-sm btn-outline" onclick="selectAll(true)">Alle</button>
          <button type="button" class="btn btn-sm btn-outline" onclick="selectNew()">Nur neue</button>
          <button type="button" class="btn btn-sm btn-outline" onclick="selectAll(false)">Keine</button>
        </div>
        <div class="grouping-bar" style="margin-bottom:0">
          Gruppieren:
          <button type="button" class="group-btn active" id="gbtn-club"  onclick="setGrouping('club')">Verein</button>
          <button type="button" class="group-btn"        id="gbtn-month" onclick="setGrouping('month')">Datum</button>
        </div>
      </div>

      <!-- ── EINGESCHLOSSEN ── -->
      <div class="cat-head">
        <span class="cat-title">Eingeschlossen</span>
        <span class="cat-count"><?= count($cat_included) ?></span>
        <?php if ($filter_desc): ?>
        <span class="cat-desc"><?= htmlspecialchars($filter_desc) ?></span>
        <?php endif; ?>
      </div>
      <div class="t-list" id="list-included">
        <?php if (empty($cat_included)): ?>
        <p class="cat-empty">Keine Turniere entsprechen dem Filter.</p>
        <?php else: foreach ($cat_included as $t) render_t_row($t, 'included', $history, $from_date_page); endif; ?>
      </div>

      <!-- ── EXPORTIERT (collapsible) ── -->
      <details class="cat-details" style="margin-top:1.75rem">
        <summary class="cat-head" style="margin-top:0">
          <span class="cat-title">Exportiert</span>
          <span class="cat-count"><?= count($cat_exported) ?></span>
          <span class="cat-chevron">▶</span>
        </summary>
      <div class="t-list" id="list-exported" style="margin-top:.6rem">
        <?php if (empty($cat_exported)): ?>
        <p class="cat-empty">Noch keine Turniere exportiert.</p>
        <?php else: foreach ($cat_exported as $t) render_t_row($t, 'exported', $history, $from_date_page); endif; ?>
      </div>
      </details>

      <!-- ── GEFILTERT (collapsible) ── -->
      <details class="cat-details" style="margin-top:1.75rem">
        <summary class="cat-head" style="margin-top:0">
          <span class="cat-title">Gefiltert</span>
          <span class="cat-count"><?= count($cat_filtered) ?></span>
          <?php if ($filter_desc): ?>
          <span class="cat-desc">nicht in: <?= htmlspecialchars($filter_desc) ?></span>
          <?php endif; ?>
          <span class="cat-chevron">▶</span>
        </summary>
        <div class="t-list" id="list-filtered" style="margin-top:.6rem">
          <?php if (empty($cat_filtered)): ?>
          <p class="cat-empty">Alle Turniere entsprechen dem Filter.</p>
          <?php else: foreach ($cat_filtered as $t) render_t_row($t, 'filtered', $history, $from_date_page); endif; ?>
        </div>
      </details>

      <!-- Action bar -->
      <div class="btn-row" style="margin-top:1.5rem">
        <button type="submit" name="action" value="download" class="btn btn-primary">⬇ Ausgewählte herunterladen</button>
        <?php if (!empty($config['email'])): ?>
        <button type="submit" name="action" value="email" class="btn btn-outline">✉ Per E-Mail senden</button>
        <?php else: ?>
        <span style="font-size:.85rem;color:var(--muted);align-self:center">E-Mail: erst in Einstellungen konfigurieren</span>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════════════════════════ EINSTELLUNGEN -->
  <div id="tab-einstellungen" class="tab-panel">
    <form method="POST">
      <input type="hidden" name="action" value="save_config">

      <div class="card">
        <div class="card-title">Vereine</div>
        <label for="clubs">Vereins-URLs</label>
        <p class="hint">Eine URL pro Zeile — z.B. https://live.kickertool3.de/deinverein</p>
        <textarea id="clubs" name="clubs" rows="5"><?= htmlspecialchars(implode("\n", $config['clubs'])) ?></textarea>

        <label for="filter">Turnier-Filter nach Name <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <p class="hint">Nur Turniere deren Name einen dieser Texte enthält (einer pro Zeile). Leer = alle.</p>
        <textarea id="filter" name="filter" rows="3"><?= htmlspecialchars(implode("\n", $config['filter'])) ?></textarea>

        <label for="filter_from_date">Turnier-Filter ab Datum <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
        <p class="hint">Nur Turniere ab diesem Datum einschließen. Leer = kein Datum-Filter.</p>
        <input type="date" id="filter_from_date" name="filter_from_date"
               value="<?= htmlspecialchars($config['filter_from_date'] ?? '') ?>">
      </div>

      <div class="card">
        <div class="card-title">E-Mail &amp; SMTP</div>

        <label for="email">Empfänger-Adresse</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($config['email'] ?? '') ?>" placeholder="name@example.com">

        <label for="smtp_host">SMTP-Host</label>
        <input type="text" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($config['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
          <div>
            <label for="smtp_port">Port</label>
            <input type="text" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars($config['smtp_port'] ?? '587') ?>" placeholder="587">
          </div>
          <div>
            <label for="smtp_secure">Verschlüsselung</label>
            <select id="smtp_secure" name="smtp_secure" style="width:100%;border:1.5px solid var(--border);border-radius:8px;padding:.6rem .85rem;font-size:.92rem;margin-bottom:1rem">
              <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'Keine (25)'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= ($config['smtp_secure'] ?? 'tls') === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <label for="smtp_from">Absender-Adresse</label>
        <input type="text" id="smtp_from" name="smtp_from" value="<?= htmlspecialchars($config['smtp_from'] ?? '') ?>" placeholder="kickertool@example.com">

        <label for="smtp_user">SMTP-Benutzername</label>
        <input type="text" id="smtp_user" name="smtp_user" value="<?= htmlspecialchars($config['smtp_user'] ?? '') ?>" placeholder="user@example.com" autocomplete="off">

        <label for="smtp_pass">SMTP-Passwort <?php if (!empty($config['smtp_pass'])): ?><span style="font-weight:400;color:var(--muted)">(gesetzt – leer lassen zum Beibehalten)</span><?php endif; ?></label>
        <input type="password" id="smtp_pass" name="smtp_pass" value="" placeholder="••••••••" autocomplete="new-password">
        <p class="hint">Wird verschlüsselt in <code>data/config.json</code> abgelegt (AES-256-GCM). Der Schlüssel liegt in <code>data/secret.key</code> bzw. in der Umgebungsvariable <code>KICKERTOOL_SECRET_KEY</code> — siehe README.</p>
      </div>

      <button type="submit" class="btn btn-primary">Speichern</button>
    </form>

    <div class="card" style="margin-top:1.25rem">
      <div class="card-title">Download-Verlauf</div>
      <p style="font-size:.88rem;color:var(--muted);margin-bottom:.75rem">
        <?= count($history) ?> Turniere als exportiert markiert.
        Zurücksetzen damit sie beim nächsten Lauf wieder als &ldquo;Neu&rdquo; erscheinen.
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="reset_history">
        <button type="submit" class="btn btn-danger btn-sm">Verlauf komplett zurücksetzen</button>
      </form>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════ AUTOMATION -->
  <div id="tab-automation" class="tab-panel">
    <div class="card">
      <div class="card-title">Wöchentlicher E-Mail-Versand (Cron)</div>
      <p style="font-size:.88rem;margin-bottom:.85rem">
        Dieser URL-Aufruf sendet automatisch alle <em>eingeschlossenen</em> neuen abgeschlossenen Turniere per E-Mail
        und markiert sie danach als exportiert. Kann per Cron wöchentlich aufgerufen werden.
      </p>
      <label>Cron-URL (enthält Secret-Token &mdash; nicht öffentlich teilen)</label>
      <div class="cron-box"><?= htmlspecialchars($cron_url) ?></div>

      <p style="font-size:.85rem;color:var(--muted);margin-top:1rem;margin-bottom:.5rem">
        <strong>cPanel-Cron-Eintrag</strong> (jeden Montag 07:00 Uhr):
      </p>
      <div class="cron-box">0 7 * * 1 wget -qO- "<?= htmlspecialchars($cron_url) ?>"</div>

      <p style="font-size:.85rem;color:var(--muted);margin-top:.75rem">
        Jetzt manuell auslösen:
      </p>
      <div class="btn-row" style="margin-top:.4rem">
        <a href="<?= htmlspecialchars($cron_url) ?>" class="btn btn-outline" target="_blank">▶ Cron jetzt ausführen</a>
      </div>
    </div>

    <?php if (empty($config['email'])): ?>
    <div class="flash err">Keine E-Mail-Adresse konfiguriert &mdash; bitte zuerst in Einstellungen eintragen.</div>
    <?php endif; ?>
  </div>

</div><!-- /wrap -->

<script>
function showTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
}

function selectAll(checked) {
  document.querySelectorAll('input[type=checkbox][name="tids[]"]').forEach(cb => {
    if (!cb.disabled) cb.checked = checked;
  });
}
function selectNew() {
  document.querySelectorAll('input[type=checkbox][name="tids[]"]').forEach(cb => {
    if (!cb.disabled) cb.checked = cb.dataset.new === '1';
  });
}

const DE_MONTHS = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];

function formatMonth(ym) {
  if (!ym) return '–';
  const parts = ym.split('-');
  return DE_MONTHS[parseInt(parts[1]) - 1] + ' ' + parts[0];
}

let currentGrouping = 'club';

function setGrouping(mode) {
  currentGrouping = mode;
  document.getElementById('gbtn-club').classList.toggle('active', mode === 'club');
  document.getElementById('gbtn-month').classList.toggle('active', mode === 'month');
  applyGrouping(mode);
  try { localStorage.setItem('kt_grouping', mode); } catch(e) {}
}

function applyGrouping(mode) {
  ['included', 'exported', 'filtered'].forEach(cat => {
    const list = document.getElementById('list-' + cat);
    if (!list) return;

    // Remove old group headers
    list.querySelectorAll('.group-head').forEach(h => h.remove());

    const rows = [...list.querySelectorAll('.t-row')];
    if (rows.length === 0) return;

    // Sort rows
    if (mode === 'club') {
      rows.sort((a, b) => {
        const c = (a.dataset.club || '').localeCompare(b.dataset.club || '', 'de');
        return c !== 0 ? c : (b.dataset.date || '').localeCompare(a.dataset.date || '');
      });
    } else {
      rows.sort((a, b) => (b.dataset.date || '').localeCompare(a.dataset.date || ''));
    }

    // Re-append sorted rows with group headers
    let lastKey = null;
    rows.forEach(row => {
      const key = mode === 'club' ? row.dataset.club : row.dataset.month;
      if (key !== lastKey) {
        lastKey = key;
        const head = document.createElement('div');
        head.className = 'group-head';
        head.textContent = mode === 'month' ? formatMonth(key) : (key || '–');
        list.appendChild(head);
      }
      list.appendChild(row);
    });
  });
}

// Restore grouping preference and apply on load
document.addEventListener('DOMContentLoaded', () => {
  let saved = 'club';
  try { saved = localStorage.getItem('kt_grouping') || 'club'; } catch(e) {}
  setGrouping(saved);
});
</script>
</body>
</html>
