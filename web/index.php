<?php
// APRS Object Beacon - edytor (bez logowania, tylko siec lokalna)
// Open source project by W9GIL / Group 312 Chicago
session_start();

// PHP na Raspberry Pi OS domyslnie liczy czas w UTC - bierzemy strefe systemu
$tz = trim((string)@file_get_contents('/etc/timezone'));
if ($tz === '' && is_link('/etc/localtime')) $tz = preg_replace('#^.*zoneinfo/#', '', (string)readlink('/etc/localtime'));
@date_default_timezone_set($tz ?: 'America/Chicago');

const APP_DIR   = '/opt/aprs-beacon';   // instalator ustawia to automatycznie
const APP_NAME  = 'APRS Object Beacon';
const APP_VER   = '1.0';
const AUTHOR    = 'W9GIL';
const ORG_NAME  = 'Group 312 Chicago';
const ORG_URL   = 'https://group312chicago.org';
const REPO_URL  = 'https://github.com/woyteg/aprs-object-beacon';
// Bez hasla: edytor dziala tylko z sieci lokalnej (192.168.x, 10.x, 172.16-31.x, localhost).
// Ustaw na false, jesli swiadomie chcesz dostep spoza LAN (np. przez VPN z innym adresem).
const LAN_ONLY  = true;

$file = APP_DIR . '/comments.json';
$cfgFile = APP_DIR . '/config.json';
$msg = '';
$err = '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function beacon($args, $withErr = true) {
    $cmd = '/usr/bin/python3 ' . escapeshellarg(APP_DIR . '/beacon.py') . ' ' . $args;
    return (string)shell_exec($cmd . ($withErr ? ' 2>&1' : ' 2>/dev/null'));
}

function aprs_passcode($callsign) {
    $call = strtoupper(explode('-', $callsign)[0]);
    $h = 0x73E2;
    for ($i = 0; $i < strlen($call); $i += 2) {
        $h ^= ord($call[$i]) << 8;
        if ($i + 1 < strlen($call)) $h ^= ord($call[$i + 1]);
    }
    return $h & 0x7FFF;
}

function lines($s) {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$s)), 'strlen'));
}

function valid_date($d) {
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d;
}

function parse_form($p) {
    $errors = [];
    $out = [
        'announce' => trim($p['announce'] ?? '') ?: '{name} za {days} dni',
        'default'  => lines($p['default'] ?? ''),
        'holidays' => [],
        'events'   => [],
    ];
    foreach (lines($p['holidays'] ?? '') as $i => $l) {
        $f = array_map('trim', explode('|', $l, 2));
        $n = $i + 1;
        if (count($f) < 2 || $f[1] === '') { $errors[] = "Święta, linia $n: brak tekstu po |"; continue; }
        if (!preg_match('/^(\d{2}-\d{2}|easter([+-]\d{1,2})?|nth:\d{1,2}:[1-7]:(-1|[1-5])|thanksgiving|memorial|labor|pulaski)$/i', $f[0])) {
            $errors[] = "Święta, linia $n: nie rozumiem daty '{$f[0]}'"; continue;
        }
        $out['holidays'][] = ['date' => strtolower($f[0]), 'text' => $f[1]];
    }
    foreach (lines($p['events'] ?? '') as $i => $l) {
        $f = array_map('trim', explode('|', $l, 5));
        $n = $i + 1;
        if (count($f) !== 5) { $errors[] = "Wydarzenia, linia $n: potrzeba 5 pól oddzielonych |"; continue; }
        [$start, $end, $ann, $name, $text] = $f;
        if ($end === '') $end = $start;
        if (!valid_date($start) || !valid_date($end)) { $errors[] = "Wydarzenia, linia $n: data w formacie RRRR-MM-DD"; continue; }
        if ($end < $start) { $errors[] = "Wydarzenia, linia $n: koniec przed początkiem"; continue; }
        $out['events'][] = ['start' => $start, 'end' => $end, 'announce_days' => max(0, (int)$ann),
                            'name' => $name, 'text' => $text];
    }
    usort($out['events'], fn($a, $b) => strcmp($a['start'], $b['start']));
    return [$out, $errors];
}

// ---------- dostep tylko z sieci lokalnej ----------
function is_local_ip($ip) {
    if ($ip === '::1' || str_starts_with($ip, '127.') || str_starts_with($ip, '::ffff:127.')) return true;
    if (str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    $l = strtolower($ip);   // IPv6: link-local fe80::/10 i unique-local fc00::/7
    return (bool)preg_match('/^(fe[89ab]|f[cd])/', $l);
}
$allowed = !LAN_ONLY || is_local_ip($_SERVER['REMOTE_ADDR'] ?? '');

$css = <<<CSS
:root{--bg:#14181f;--card:#1d232d;--fg:#e6e9ee;--mut:#8b95a5;--acc:#3fa7ff;--ok:#3ecf72;--bad:#ff6b6b;--line:#2c3440}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.45 system-ui,sans-serif}
.wrap{max-width:860px;margin:0 auto;padding:16px}h1{font-size:20px;margin:8px 0 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin-bottom:14px}
h2{font-size:15px;margin:0 0 6px;color:var(--acc)}.hint{color:var(--mut);font-size:13px;margin:0 0 8px}
textarea,input[type=text],input[type=password]{width:100%;background:#11151b;color:var(--fg);border:1px solid var(--line);border-radius:6px;padding:8px;font:13px/1.5 ui-monospace,monospace}
textarea{min-height:120px;resize:vertical}button{background:var(--acc);color:#fff;border:0;border-radius:6px;padding:9px 16px;font-weight:600;cursor:pointer}
button.sec{background:#3a4352}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.warn{color:var(--bad);font-size:13px;white-space:pre-line;margin-top:4px}.msg{color:var(--ok)}.err{color:var(--bad)}
pre{background:#11151b;padding:8px;border-radius:6px;overflow-x:auto;font-size:12px;white-space:pre-wrap;word-break:break-all}
.tag{display:inline-block;font-size:11px;padding:1px 7px;border-radius:9px;background:#2c3440;color:var(--mut);margin-right:6px}
body{background:radial-gradient(1200px 500px at 50% -200px,#1d3350 0%,var(--bg) 70%) no-repeat,var(--bg)}
.top{display:flex;align-items:center;gap:14px;padding:18px 0 16px;flex-wrap:wrap}
.logo{width:52px;height:52px;flex:none}
.brand{flex:1;min-width:200px}.brand h1{margin:0;font-size:21px;letter-spacing:.2px}
.brand .sub{color:var(--mut);font-size:13px;margin-top:2px}
.brand .sub b{color:var(--fg);font-weight:600;font-family:ui-monospace,monospace}
.pill{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:600;padding:5px 11px;border-radius:20px;border:1px solid var(--line);background:#11151b}
.dot{width:8px;height:8px;border-radius:50%;background:var(--mut)}
.pill.on{color:var(--ok);border-color:#24553a}.pill.on .dot{background:var(--ok);box-shadow:0 0 0 0 rgba(62,207,114,.6);animation:ping 2s infinite}
.pill.off{color:var(--bad);border-color:#5a2a2a}.pill.off .dot{background:var(--bad)}
@keyframes ping{70%{box-shadow:0 0 0 8px rgba(62,207,114,0)}100%{box-shadow:0 0 0 0 rgba(62,207,114,0)}}
.stats{display:flex;gap:18px;flex-wrap:wrap;color:var(--mut);font-size:12px;margin:2px 0 12px}
.stats b{color:var(--fg);font-weight:600}
h2{display:flex;align-items:center;gap:8px}h2 svg{width:17px;height:17px;flex:none}
.air{border-color:#24456a;background:linear-gradient(180deg,#1b2838,var(--card))}
.term{background:#0b0f14;border:1px solid #1f2a36;border-radius:8px;padding:10px 12px;font:12.5px/1.6 ui-monospace,Menlo,monospace;white-space:pre-wrap;overflow-wrap:anywhere;margin:6px 0 10px}
.row label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--mut)}
.row label.chk{flex-direction:row;align-items:center;gap:6px;font-size:13px;color:var(--fg);align-self:flex-end;padding-bottom:8px}
select{background:#11151b;color:var(--fg);border:1px solid var(--line);border-radius:6px;padding:7px 8px;font:13px system-ui,sans-serif}
.term .p-call{color:#f2c94c}.term .p-path{color:#5c6b7d}.term .p-name{color:#3fa7ff;font-weight:700}
.term .p-ts{color:#9b8cff}.term .p-pos{color:#56d4c2}.term .p-sym{color:#ff9f43;font-weight:700}
.term .p-info{color:#e6e9ee}.term .p-kill{color:var(--bad);font-weight:700}
.legend{display:flex;gap:12px;flex-wrap:wrap;font-size:11px;color:var(--mut)}
.legend i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:4px;vertical-align:middle}
footer{border-top:1px solid var(--line);margin-top:22px;padding:16px 0 28px;color:var(--mut);font-size:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
footer svg{width:22px;height:22px;opacity:.8}footer a{color:var(--acc);text-decoration:none}footer a:hover{text-decoration:underline}
footer .sp{flex:1}
CSS;

$LOGO = <<<SVG
<svg class="logo" viewBox="0 0 64 64" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <rect x="1" y="1" width="62" height="62" rx="14" fill="#11151b" stroke="#2c3440"/>
  <path d="M32 24 L22 54 M32 24 L42 54 M25.5 44 H38.5 M27.5 37 H36.5 M23.5 50 L36 41 M40.5 50 L28 41" stroke="#8b95a5" stroke-width="2"/>
  <circle cx="32" cy="21" r="3.2" fill="#3fa7ff"/>
  <path d="M25 14.5a10 10 0 0 0 0 13M39 14.5a10 10 0 0 1 0 13" stroke="#3fa7ff" stroke-width="2.4"/>
  <path d="M19.5 9.5a17 17 0 0 0 0 23M44.5 9.5a17 17 0 0 1 0 23" stroke="#3fa7ff" stroke-width="2.4" opacity=".55"/>
</svg>
SVG;

function icon($n) {
    $p = [
        'air'   => '<path d="M5 12a7 7 0 0 1 14 0M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.3" fill="currentColor"/><path d="M12 13.5V21"/>',
        'pin'   => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'cloud' => '<path d="M7 18h10a4 4 0 0 0 .5-8 6 6 0 0 0-11.4 1.6A3.3 3.3 0 0 0 7 18z"/>',
        'chat'  => '<path d="M4 5h16v11H9l-5 4z"/>',
        'star'  => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'radio' => '<rect x="3.5" y="9" width="17" height="11" rx="2"/><path d="M7 9l10-5"/><circle cx="15.5" cy="14.5" r="2.3"/><path d="M7 13h3M7 16h3"/>',
        'cal'   => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    ][$n] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

// koloruje ramke APRS-obiektu po polach
function colorize($pkt) {
    if (!preg_match('/^([^>]+)>([^:]+):;(.{9})([*_])(\d{6}z)(\d{4}\.\d{2}[NS])(.)(\d{5}\.\d{2}[EW])(.)(.*)$/s', $pkt, $m)) return h($pkt);
    return '<span class="p-call">' . h($m[1]) . '</span><span class="p-path">&gt;' . h($m[2]) . ':;</span>'
         . '<span class="p-name">' . h($m[3]) . '</span>'
         . '<span class="' . ($m[4] === '_' ? 'p-kill' : 'p-path') . '">' . h($m[4]) . '</span>'
         . '<span class="p-ts">' . h($m[5]) . '</span>'
         . '<span class="p-pos">' . h($m[6]) . '</span><span class="p-sym">' . h($m[7]) . '</span>'
         . '<span class="p-pos">' . h($m[8]) . '</span><span class="p-sym">' . h($m[9]) . '</span>'
         . '<span class="p-info">' . h($m[10]) . '</span>';
}

function footer_html() {
    $repo = REPO_URL ? ' · <a href="' . h(REPO_URL) . '" target="_blank" rel="noopener">GitHub</a>' : '';
    return '<footer><svg viewBox="0 0 64 64" fill="none" stroke="#3fa7ff" stroke-width="3" stroke-linecap="round">'
         . '<path d="M32 26 L23 56 M32 26 L41 56" stroke="#8b95a5"/><circle cx="32" cy="22" r="3.5" fill="#3fa7ff" stroke="none"/>'
         . '<path d="M24 14a11 11 0 0 0 0 16M40 14a11 11 0 0 1 0 16"/></svg>'
         . '<span><b style="color:var(--fg)">' . APP_NAME . '</b> v' . APP_VER . ' · open source project by <b style="color:var(--fg)">' . AUTHOR . '</b></span>'
         . '<span class="sp"></span><span><a href="' . h(ORG_URL) . '" target="_blank" rel="noopener">' . ORG_NAME . '</a>' . $repo . ' · 73!</span></footer>';
}

if (!$allowed) { http_response_code(403); ?>
<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= APP_NAME ?></title><style><?= $css ?></style>
<div class="wrap" style="max-width:420px;margin-top:9vh">
<div style="text-align:center;margin-bottom:16px"><?= str_replace('class="logo"', 'class="logo" style="width:72px;height:72px"', $LOGO) ?>
<h1 style="margin:10px 0 2px"><?= APP_NAME ?></h1><div class="hint"><?= ORG_NAME ?></div></div>
<div class="card"><p class="err" style="margin:0">Edytor jest dostępny tylko z sieci lokalnej.</p></div>
<?= footer_html() ?></div></html>
<?php exit; }

// ---------- akcje ----------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$data = json_decode((string)@file_get_contents($file), true) ?: [];
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $err = 'Sesja wygasła - odśwież stronę.';
    } elseif ($_POST['action'] === 'save') {
        [$new, $errors] = parse_form($_POST);
        if ($errors) {
            $err = implode("\n", $errors);
            $posted = $_POST;
        } else {
            $json = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            if (file_put_contents($file, $json, LOCK_EX) === false) {
                $err = "Nie mogę zapisać $file (uprawnienia?)";
                $posted = $_POST;
            } else {
                $data = $new;
                $msg = 'Zapisano. Następna ramka pójdzie z nowymi ustawieniami.';
            }
        }
    } elseif ($_POST['action'] === 'add_obj' || str_starts_with($_POST['action'], 'del_obj_')) {
        $cfg = json_decode((string)@file_get_contents($cfgFile), true);
        if (!$cfg) {
            $err = 'Nie mogę odczytać config.json';
        } else {
            $cfg['objects'] = $cfg['objects'] ?? [];
            if ($_POST['action'] === 'add_obj') {
                $n = count($cfg['objects']) + 1;
                $cfg['objects'][] = ['name' => "OBIEKT$n", 'enabled' => false, 'lat' => 0, 'lon' => 0,
                                     'symbol' => '/+', 'freq' => false, 'rotate' => false,
                                     'tone' => null, 'offset' => '', 'range_miles' => 0, 'text' => ''];
                $msg = "Dodano obiekt OBIEKT$n. Uzupełnij nazwę, pozycję i opis, zaznacz „Nadawaj” i zapisz.";
            } else {
                $i = (int)substr($_POST['action'], 8);
                if (isset($cfg['objects'][$i])) {
                    $o = $cfg['objects'][$i];
                    if (!empty($o['lat']) || !empty($o['lon'])) {
                        $cfg['pending_kill'][] = ['name' => $o['name'], 'lat' => $o['lat'], 'lon' => $o['lon'],
                                                  'symbol' => $o['symbol'] ?? '/r'];
                    }
                    array_splice($cfg['objects'], $i, 1);
                    $msg = "Usunięto obiekt {$o['name']}. Zniknie z mapy przy następnym wysłaniu.";
                }
            }
            if (file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
                $err = "Nie mogę zapisać $cfgFile (uprawnienia?)"; $msg = '';
            }
        }
    } elseif ($_POST['action'] === 'save_obj') {
        $cfg = json_decode((string)@file_get_contents($cfgFile), true);
        $errors = [];
        if (!$cfg || empty($cfg['objects'])) {
            $errors[] = 'Nie mogę odczytać config.json';
        } else {
            foreach ($cfg['objects'] as $i => &$o) {
                $in = $_POST['obj'][$i] ?? null;
                if (!$in) continue;
                $label = $o['name'];

                // nazwa obiektu (1-9 znakow ASCII)
                $name = trim($in['name'] ?? $o['name']);
                if (!preg_match('/^[\x21-\x7e][\x20-\x7e]{0,8}$/', $name) || strpbrk($name, '*_|~') !== false) {
                    $errors[] = "$label: nazwa obiektu 1-9 znaków, bez polskich liter i bez * _ | ~";
                } elseif ($name !== $o['name']) {
                    // stara nazwa zostanie usunieta z mapy przy nastepnym wyslaniu
                    // (tylko jesli obiekt mial juz pozycje, czyli mogl byc wyslany)
                    if (!empty($o['lat']) || !empty($o['lon'])) {
                        $cfg['pending_kill'][] = ['name' => $o['name'], 'lat' => $o['lat'], 'lon' => $o['lon'],
                                                  'symbol' => $o['symbol'] ?? '/r'];
                    }
                    $o['name'] = $name;
                }

                // pozycja: "41.9108, -87.6779" (wklejone z Google Maps) albo dwie liczby
                $pos = trim(str_replace(['°', ';'], ['', ','], $in['pos'] ?? ''));
                if ($pos === '') {
                    // puste = zostaw obecna pozycje
                } elseif (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)$/', $pos, $m)
                    && abs((float)$m[1]) <= 90 && abs((float)$m[2]) <= 180) {
                    $o['lat'] = round((float)$m[1], 5);
                    $o['lon'] = round((float)$m[2], 5);
                } else {
                    $errors[] = "$label: pozycja np. 41.9108, -87.6779 (szerokość, długość; zachód z minusem)";
                }

                // ikona: z listy albo wlasna (2 znaki: tablica + symbol)
                $sym = ($in['symbol'] ?? '') === 'custom' ? trim($in['symbol_custom'] ?? '') : ($in['symbol'] ?? '');
                if (preg_match('/^[\/\\\\A-Z0-9][\x21-\x7e]$/', $sym)) $o['symbol'] = $sym;
                else $errors[] = "$label: ikona to 2 znaki, np. /r";

                $tone =trim(str_replace(',', '.', $in['tone'] ?? ''));
                if ($tone === '' || strtolower($tone) === 'off') $o['tone'] = null;
                elseif (is_numeric($tone) && $tone >= 60 && $tone <= 260) $o['tone'] = (float)$tone;
                else $errors[] = "$label: ton musi być liczbą, np. 114.8 (albo pusty = bez tonu)";
                // przyjmuje "+5", "-0.6" (MHz) albo "+500", "-060" (format APRS, po 10 kHz)
                $off = str_replace([',', ' ', 'MHz', 'mhz'], ['.', '', '', ''], trim($in['offset'] ?? ''));
                if ($off === '') {
                    $o['offset'] = '';
                } elseif (preg_match('/^([+-]?)(\d+(\.\d+)?)$/', $off, $m)) {
                    $v = (float)$m[2];
                    $units = $v <= 10 ? (int)round($v * 100) : (int)$v;
                    $o['offset'] = ($m[1] === '-' ? '-' : '+') . str_pad((string)$units, 3, '0', STR_PAD_LEFT);
                } else {
                    $errors[] = "$label: offset np. +5 albo -0.6 (MHz), albo pusty";
                }
                $rng = trim($in['range'] ?? '');
                if ($rng === '' || ctype_digit($rng)) $o['range_miles'] = $rng === '' ? 0 : (int)$rng;
                else $errors[] = "$label: zasięg to liczba mil";
                $o['text'] = trim(preg_replace('/\s+/', ' ', $in['text'] ?? ''));
                $o['enabled'] = !empty($in['enabled']);
                $o['freq'] = !empty($in['freq']);
                $o['rotate'] = !empty($in['rotate']);
            }
            unset($o);
            $names = array_column($cfg['objects'], 'name');
            if (count($names) !== count(array_unique($names))) $errors[] = 'Dwa obiekty nie mogą mieć tej samej nazwy.';
        }
        if ($errors) {
            $err = implode("\n", $errors);
        } elseif (file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            $err = "Nie mogę zapisać $cfgFile (uprawnienia?)";
        } else {
            $msg = 'Zapisano obiekty.';
        }
    } elseif ($_POST['action'] === 'save_station') {
        $cfg = json_decode((string)@file_get_contents($cfgFile), true);
        $call = strtoupper(trim($_POST['callsign'] ?? ''));
        $pass = trim($_POST['passcode'] ?? '');
        $server = trim($_POST['server'] ?? '') ?: 'rotate.aprs2.net';
        $errors = [];
        if (!$cfg) $errors[] = 'Nie mogę odczytać config.json';
        if (!preg_match('/^[A-Z0-9]{3,6}(-([0-9]|1[0-5]))?$/', $call) || !preg_match('/\d/', explode('-', $call)[0])) {
            $errors[] = 'Znak np. W9GIL-10 (SSID 0-15, opcjonalny)';
        }
        if ($pass !== '' && !preg_match('/^\d{1,5}$/', $pass)) $errors[] = 'Passcode to liczba (albo puste = automatycznie)';
        if (!preg_match('/^[a-z0-9.-]+$/i', $server)) $errors[] = 'Nieprawidłowy adres serwera';
        if ($errors) {
            $err = implode("\n", $errors);
        } else {
            $cfg['callsign'] = $call;
            $cfg['passcode'] = ($pass !== '' && (int)$pass !== aprs_passcode($call)) ? $pass : '';   // '' = auto
            $cfg['server'] = $server;
            if (file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
                $err = "Nie mogę zapisać $cfgFile (uprawnienia?)";
            } else {
                $msg = "Zapisano stację $call.";
            }
        }
    } elseif ($_POST['action'] === 'save_wx') {
        $cfg = json_decode((string)@file_get_contents($cfgFile), true);
        if (!$cfg) {
            $err = 'Nie mogę odczytać config.json';
        } else {
            $w = $cfg['weather'] ?? [];
            $key = trim($_POST['wx_key'] ?? '');
            if ($key !== '') $w['api_key'] = $key;          // puste pole = zostaw stary klucz
            $w['units'] = ($_POST['wx_units'] ?? '') === 'metric' ? 'metric' : 'imperial';
            $w['refresh_minutes'] = max(10, (int)($_POST['wx_refresh'] ?? 60));
            $tpl = trim($_POST['wx_template'] ?? '');
            $w['template'] = $tpl !== '' ? $tpl : 'WX {temp} {press} {wind} chm{clouds} {precip}';
            $cfg['weather'] = $w;
            @unlink(APP_DIR . '/weather_cache.json');           // nowe ustawienia = pobierz od nowa
            if (file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
                $err = "Nie mogę zapisać $cfgFile (uprawnienia?)";
            } else {
                $msg = 'Zapisano ustawienia pogody.';
            }
        }
    } elseif ($_POST['action'] === 'send') {
        $msg = "Wysłane teraz:\n" . trim(beacon(''));
    }
}

// ---------- tekst do formularza ----------
if ($posted) {
    $f = ['announce' => $posted['announce'] ?? '', 'default' => $posted['default'] ?? '',
          'holidays' => $posted['holidays'] ?? '', 'events' => $posted['events'] ?? ''];
} else {
    $f = [
        'announce' => $data['announce'] ?? '{name} za {days} dni',
        'default'  => implode("\n", $data['default'] ?? []),
        'holidays' => implode("\n", array_map(fn($x) => "{$x['date']} | {$x['text']}", $data['holidays'] ?? [])),
        'events'   => implode("\n", array_map(fn($x) => "{$x['start']} | {$x['end']} | {$x['announce_days']} | {$x['name']} | {$x['text']}", $data['events'] ?? [])),
    ];
}

$cfgView = json_decode((string)@file_get_contents($cfgFile), true) ?: [];
$SYMBOLS = [
    '/r' => 'Przemiennik (antena)',
    '/#' => 'Digipeater',
    '/&' => 'Bramka (gateway)',
    '/-' => 'Dom / QTH',
    '/y' => 'Antena Yagi na QTH',
    '/_' => 'Stacja pogodowa',
    '/o' => 'EOC / centrum kryzysowe',
    '/c' => 'Stanowisko dowodzenia (ICP)',
    '/+' => 'Punkt medyczny / Czerwony Krzyż',
    '/h' => 'Szpital',
    '/a' => 'Karetka',
    '/:' => 'Straż pożarna',
    '/!' => 'Policja',
    '/E' => 'Wydarzenie (oko)',
    '/;' => 'Praca terenowa (namiot)',
    '/K' => 'Szkoła',
];
$prev = json_decode(beacon('--dry-run --json', false), true);
$limit = (int)($prev['max_text'] ?? 33);
$srcName = ['event' => 'wydarzenie', 'announce' => 'zapowiedź', 'holiday' => 'święto', 'default' => 'rotacja', 'none' => 'brak', 'fixed' => 'stały opis'];

// status nadawania
$timerOn = trim((string)shell_exec('systemctl is-active aprs-beacon.timer 2>/dev/null')) === 'active';
$last = json_decode((string)@file_get_contents(APP_DIR . '/last_sent.json'), true);
$lastErr = json_decode((string)@file_get_contents(APP_DIR . '/last_error.json'), true);
$now = time();
$nextTs = $now - ($now % 600) + 180; if ($nextTs <= $now) $nextTs += 600;   // timer: :03, :13, :23...
function ago($ts) {
    $d = time() - $ts;
    if ($d < 60) return 'przed chwilą';
    if ($d < 3600) return floor($d / 60) . ' min temu';
    if ($d < 86400) return floor($d / 3600) . ' h temu';
    return date('Y-m-d H:i', $ts);
}
$showErr = $lastErr && (!$last || $lastErr['ts'] > $last['ts']);
?>
<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= APP_NAME ?></title><style><?= $css ?></style>
<div class="wrap">
<header class="top">
  <?= $LOGO ?>
  <div class="brand"><h1><?= APP_NAME ?></h1>
    <div class="sub">nadawca <b><?= h($cfgView['callsign'] ?? '?') ?></b></div></div>
  <span class="pill <?= $timerOn ? 'on' : 'off' ?>"><span class="dot"></span><?= $timerOn ? 'NA ANTENIE' : 'TIMER WYŁĄCZONY' ?></span>
</header>

<?php if ($msg) echo '<div class="card msg"><pre>' . h($msg) . '</pre></div>'; ?>
<?php if ($err) echo '<div class="card err" style="white-space:pre-line">' . h($err) . '</div>'; ?>
<?php if ($prev && empty($prev['configured'])) { ?>
<div class="card" style="border-color:#7a5a1e;background:#2a2416">
<h2 style="color:#f2c94c">Pierwsze uruchomienie</h2>
<p style="margin:0">Wpisz swój <b>znak</b> w sekcji „Stacja nadawcza” i <b>pozycję</b> obiektu poniżej.
Dopóki tego nie zrobisz, beacon nic nie wysyła.</p></div>
<?php } ?>

<div class="card air">
<h2><?= icon('air') ?>Teraz w eterze</h2>
<div class="stats">
  <span>Ostatnio wysłano: <b><?= $last ? h(date('H:i', $last['ts'])) . ' (' . ago($last['ts']) . ')' : '—' ?></b></span>
  <span>Następne: <b><?= $timerOn ? h(date('H:i', $nextTs)) : 'wyłączone' ?></b></span>
  <span>Co: <b>10 min</b></span>
</div>
<?php if ($showErr) echo '<p class="err" style="margin:0 0 8px">Ostatnia próba (' . h(ago($lastErr['ts'])) . '): ' . h($lastErr['error']) . '</p>'; ?>
<?php if (!$prev) { echo '<p class="err">Nie mogę uruchomić beacon.py - sprawdź ścieżkę i uprawnienia.</p>'; }
else foreach ($prev['packets'] as $p) { ?>
  <p style="margin:4px 0"><span class="tag"><?= h($srcName[$p['source']] ?? $p['source']) ?></span><b><?= h($p['name']) ?></b><?= $p['text'] !== '' ? ' - ' . h($p['text']) : '' ?>
  <?php if ($p['truncated']) echo ' <span class="err">(ucięte)</span>'; ?></p>
  <div class="term"><?= colorize($p['packet']) ?></div>
<?php } ?>
<div class="row" style="justify-content:space-between">
  <div class="legend">
    <span><i style="background:#f2c94c"></i>nadawca</span><span><i style="background:#3fa7ff"></i>obiekt</span>
    <span><i style="background:#9b8cff"></i>czas UTC</span><span><i style="background:#56d4c2"></i>pozycja</span>
    <span><i style="background:#ff9f43"></i>ikona</span><span><i style="background:#e6e9ee"></i>komentarz</span>
  </div>
  <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
  <button name="action" value="send">Wyślij teraz</button></form>
</div>
</div>

<?php
$stCall = $cfgView['callsign'] ?? '';
$stAuto = $stCall ? aprs_passcode($stCall) : '';
$stPass = (string)($cfgView['passcode'] ?? '');
$stManual = $stPass !== '' && $stCall && (int)$stPass !== $stAuto;
?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<div class="card">
<h2><?= icon('radio') ?>Stacja nadawcza</h2>
<p class="hint">Znak, z którego wysyłane są obiekty. Na mapie widać nazwę obiektu, a znak pojawia się jako „reported by”.</p>
<div class="row" style="align-items:flex-end">
  <label>Znak z SSID <input type="text" name="callsign" value="<?= h($stCall) ?>" placeholder="W9GIL-10" style="width:140px;text-transform:uppercase" maxlength="9"></label>
  <label>Passcode APRS-IS <input type="text" name="passcode" value="<?= $stManual ? h($stPass) : '' ?>" placeholder="auto: <?= h($stAuto) ?>" style="width:130px" inputmode="numeric"></label>
  <label style="flex:1;min-width:180px">Serwer APRS-IS <input type="text" name="server" value="<?= h($cfgView['server'] ?? 'rotate.aprs2.net') ?>"></label>
</div>
<p class="hint" style="margin-top:8px">Passcode liczy się sam ze znaku (bez SSID). Zostaw puste, chyba że chcesz wpisać inny.
<?= $stManual ? '<span class="err">Teraz używany jest passcode wpisany ręcznie.</span>' : '' ?></p>
<p style="margin-bottom:0"><button name="action" value="save_station">Zapisz stację</button></p>
</div>
</form>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<!-- Enter w polu = zapis (a nie pierwszy przycisk „Usuń”) -->
<button name="action" value="save_obj" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px">Zapisz</button>
<?php foreach (($cfgView['objects'] ?? []) as $i => $o) { ?>
<div class="card">
<div class="row" style="justify-content:space-between;align-items:center;margin-bottom:6px">
<h2 style="margin:0"><?= icon('pin') ?>Obiekt <?= h($o['name']) ?><?= ($o['enabled'] ?? true) ? '' : ' <span class="tag" style="margin-left:4px">wyłączony</span>' ?></h2>
<button class="sec" name="action" value="del_obj_<?= $i ?>" onclick="return confirm('Usunąć obiekt <?= h($o['name']) ?>? Zniknie też z mapy.')" style="padding:5px 11px;font-size:12px">Usuń</button>
</div>
<?php $sym = $o['symbol'] ?? '/r'; $isCustom = !isset($SYMBOLS[$sym]); ?>
<div class="row" style="align-items:flex-end">
  <label>Nazwa na mapie <input type="text" name="obj[<?= $i ?>][name]" value="<?= h($o['name']) ?>" maxlength="9" style="width:120px"></label>
  <label style="flex:1;min-width:220px">Pozycja (szer., dł.)
    <input type="text" name="obj[<?= $i ?>][pos]" value="<?= (empty($o['lat']) && empty($o['lon'])) ? '' : h($o['lat'] . ', ' . $o['lon']) ?>" placeholder="np. 41.9108, -87.6779"></label>
</div>
<p class="hint" style="margin-top:6px">Pozycję skopiuj z Google Maps: prawy klik na miejscu → kliknij w liczby → wklej tutaj.
<a href="https://www.google.com/maps?q=<?= h(($o['lat'] ?? '') . ',' . ($o['lon'] ?? '')) ?>" target="_blank" style="color:var(--acc)">Pokaż obecną na mapie</a>.
Zmiana nazwy usunie starą nazwę z mapy przy następnym wysłaniu.</p>
<div class="row" style="margin-bottom:10px">
  <label>Ikona
    <select name="obj[<?= $i ?>][symbol]" onchange="this.parentNode.nextElementSibling.style.display=this.value==='custom'?'':'none'"
     >
      <?php foreach ($SYMBOLS as $code => $desc) { ?>
        <option value="<?= h($code) ?>" <?= $sym === $code ? 'selected' : '' ?>><?= h("$desc  ($code)") ?></option>
      <?php } ?>
      <option value="custom" <?= $isCustom ? 'selected' : '' ?>>Inna (wpisz kod)</option>
    </select></label>
  <label style="<?= $isCustom ? '' : 'display:none' ?>">Kod <input type="text" name="obj[<?= $i ?>][symbol_custom]" value="<?= h($sym) ?>" maxlength="2" style="width:60px"></label>
</div>
<p class="hint">Stały opis wysyłany zawsze. Wpisz <code>{wx}</code>, żeby dodać pogodę.</p>
<input type="text" name="obj[<?= $i ?>][text]" value="<?= h($o['text'] ?? '') ?>" placeholder="np. FM Poland TG3109312 Group312Chicago.org albo: First Aid - Medical Tent">
<?php $isFreq = $o['freq'] ?? true; ?>
<div class="row" style="margin-top:12px;gap:18px">
  <label class="chk" style="padding:0"><input type="checkbox" name="obj[<?= $i ?>][enabled]" value="1" <?= ($o['enabled'] ?? true) ? 'checked' : '' ?>> Nadawaj</label>
  <label class="chk" style="padding:0"><input type="checkbox" name="obj[<?= $i ?>][rotate]" value="1" <?= ($o['rotate'] ?? true) ? 'checked' : '' ?>> Doklejaj zmienny komentarz (święta, wydarzenia, rotacja)</label>
  <label class="chk" style="padding:0"><input type="checkbox" name="obj[<?= $i ?>][freq]" value="1" <?= $isFreq ? 'checked' : '' ?>
    onchange="this.closest('.card').querySelector('.freq').style.display=this.checked?'':'none'"> To przemiennik (ton, offset, zasięg)</label>
</div>
<div class="freq" style="<?= $isFreq ? '' : 'display:none' ?>">
<div class="row" style="margin-top:10px">
  <label>Ton <input type="text" name="obj[<?= $i ?>][tone]" value="<?= h($o['tone'] ?? '') ?>" style="width:90px" placeholder="114.8"></label>
  <label>Offset <input type="text" name="obj[<?= $i ?>][offset]" value="<?= h($o['offset'] ?? '') ?>" style="width:90px" placeholder="+5"></label>
  <label>Zasięg (mi) <input type="text" name="obj[<?= $i ?>][range]" value="<?= h($o['range_miles'] ?? '') ?>" style="width:70px"></label>
</div>
<p class="hint" style="margin-top:8px">Nazwa obiektu przemiennika to zwykle jego częstotliwość, np. <code>441.675+</code>. Ton pusty = bez tonu. Offset w MHz, np. <code>+5</code> albo <code>-0.6</code>, pusty = standardowy.</p>
</div>
<p style="margin-top:12px"><button name="action" value="save_obj">Zapisz obiekty</button></p>
</div>
<?php } ?>
<p><button class="sec" name="action" value="add_obj">+ Dodaj obiekt</button>
<span class="hint" style="margin-left:8px">np. punkt medyczny, stanowisko dowodzenia, stacja na imprezie</span></p>
</form>

<?php $wx = $cfgView['weather'] ?? []; $hasKey = !empty($wx['api_key']); ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<div class="card">
<h2><?= icon('cloud') ?>Pogoda (OpenWeather)</h2>
<p class="hint">Wpisz <code>{wx}</code> w dowolny komentarz albo w stały opis, a w to miejsce wstawi się aktualna pogoda.
Klucz API: openweathermap.org → My API keys (nowy klucz aktywuje się do 2 godzin).</p>
<input type="password" name="wx_key" autocomplete="off"
  placeholder="<?= $hasKey ? 'Klucz zapisany (…' . h(substr($wx['api_key'], -4)) . ') – zostaw puste, żeby nie zmieniać' : 'Wklej klucz API' ?>">
<div class="row" style="margin-top:10px">
  <label>Jednostki
    <select name="wx_units">
      <option value="imperial" <?= ($wx['units'] ?? 'imperial') === 'imperial' ? 'selected' : '' ?>>°F, mph, in</option>
      <option value="metric" <?= ($wx['units'] ?? '') === 'metric' ? 'selected' : '' ?>>°C, m/s, mm</option>
    </select></label>
  <label>Odświeżaj co (min) <input type="text" name="wx_refresh" value="<?= h($wx['refresh_minutes'] ?? 60) ?>" style="width:70px"></label>
</div>
<p class="hint" style="margin-top:10px">Szablon: <code>{temp}</code> <code>{press}</code> <code>{wind}</code> <code>{clouds}</code> <code>{precip}</code> <code>{hum}</code> <code>{desc}</code>. Opad pokazuje się tylko, gdy pada.</p>
<input type="text" name="wx_template" value="<?= h($wx['template'] ?? 'WX {temp} {press} {wind} chm{clouds} {precip}') ?>">
<p><button name="action" value="save_wx">Zapisz pogodę</button></p>
</div>
</form>

<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

<div class="card">
<h2><?= icon('chat') ?>Komentarze domyślne (rotacja)</h2>
<p class="hint">Jeden na linię, zmieniają się co 10 minut. Limit: <?= $limit ?> znaków. Polskie znaki zostaną zamienione automatycznie.</p>
<textarea name="default" data-kind="default"><?= h($f['default']) ?></textarea><div class="warn"></div>
</div>

<div class="card">
<h2><?= icon('star') ?>Święta</h2>
<p class="hint"><code>data | tekst</code>. Data: <code>MM-DD</code>, <code>easter</code>, <code>easter+1</code>,
<code>thanksgiving</code>, <code>memorial</code>, <code>labor</code>, <code>pulaski</code>,
albo <code>nth:miesiąc:dzień_tyg:który</code> (dzień 1=pon..7=niedz, który -1 = ostatni).</p>
<textarea name="holidays" data-kind="holidays" style="min-height:200px"><?= h($f['holidays']) ?></textarea><div class="warn"></div>
</div>

<div class="card">
<h2><?= icon('cal') ?>Wydarzenia</h2>
<p class="hint"><code>start | koniec | ile dni zapowiadać | nazwa | tekst w dniu wydarzenia</code><br>
Np. <code>2026-11-21 | 2026-11-21 | 3 | Spotkanie Group 312 | Spotkanie dzis 18:00</code></p>
<textarea name="events" data-kind="events"><?= h($f['events']) ?></textarea><div class="warn"></div>
<p class="hint" style="margin-top:10px">Szablon zapowiedzi (<code>{name}</code>, <code>{days}</code>):</p>
<input type="text" name="announce" value="<?= h($f['announce']) ?>">
</div>

<button name="action" value="save">Zapisz</button>
</form>
<?= footer_html() ?>
</div>

<script>
const LIMIT = <?= $limit ?>;
const part = {
  default: l => l,
  holidays: l => l.split('|').slice(1).join('|'),
  events: l => l.split('|').slice(4).join('|')
};
document.querySelectorAll('textarea[data-kind]').forEach(t => {
  const out = t.nextElementSibling;
  const check = () => {
    const bad = t.value.split('\n')
      .map((l, i) => [i + 1, part[t.dataset.kind](l).trim()])
      .filter(([, s]) => s.length > LIMIT);
    out.textContent = bad.map(([n, s]) => `Linia ${n}: ${s.length}/${LIMIT} znaków - koniec zostanie ucięty`).join('\n');
  };
  t.addEventListener('input', check);
  check();
});
</script>
</html>
