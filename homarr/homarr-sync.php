<?php
// Baut ein Homarr-Board (v2) aus allen laufenden Unraid-Containern: Apps, Gruppen, Integrationen, Docker-Steuerung.
// Aufruf: docker ps --format '{{.Names}}' | HOMARR_API_KEY=... php homarr-sync.php

$cfg = [
  'url'       => rtrim(getenv('HOMARR_URL') ?: 'http://192.168.0.20:7575', '/'),
  'key'       => getenv('HOMARR_API_KEY') ?: '',
  'board'     => getenv('BOARD') ?: 'Baum',
  'ip'        => getenv('HOST_IP') ?: '192.168.0.20',
  'unraid'    => rtrim(getenv('UNRAID_URL') ?: 'https://192.168.0.20:444', '/'),
  'tpl'       => getenv('TPL_DIR') ?: '/boot/config/plugins/dockerMan/templates-user',
  'appdata'   => getenv('APPDATA') ?: '/mnt/cache/appdata',
  'self'      => strtolower(getenv('SELF_NAME') ?: 'homarr'),
  'jfkey'     => getenv('JELLYFIN_KEY') ?: '',
  'urkey'     => getenv('UNRAID_KEY') ?: '',
  'groups'    => getenv('GROUPS_FILE') ?: __DIR__ . '/groups.override',
  'quick'     => getenv('QUICKLINKS_FILE') ?: __DIR__ . '/quicklinks.txt',
  'dry'       => getenv('DRY_RUN') === '1',
  'style'     => getenv('STYLE') ?: 'klassisch',
  'home'      => (getenv('SET_HOME') ?: (getenv('STYLE') === 'kompakt' ? '0' : '1')) === '1',
  'city'      => getenv('WEATHER_CITY') ?: 'Friesoythe',
  'lat'       => (float)(getenv('WEATHER_LAT') ?: 53.0206),
  'lon'       => (float)(getenv('WEATHER_LON') ?: 7.8586),
];
if ($cfg['key'] === '' && !$cfg['dry']) { fwrite(STDERR, "HOMARR_API_KEY fehlt\n"); exit(1); }

function out($m) { fwrite(STDERR, $m . "\n"); }

// ---------- HTTP ----------
function api($method, $path, $body = null) {
  global $cfg;
  $hdr = "ApiKey: {$cfg['key']}\r\nAccept: application/json\r\n";
  $opt = ['method' => $method, 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 60];
  if ($body !== null) { $opt['header'] .= "Content-Type: application/json\r\n"; $opt['content'] = json_encode($body, JSON_UNESCAPED_SLASHES); }
  $res = @file_get_contents($cfg['url'] . $path, false, stream_context_create(['http' => $opt]));
  $code = 0;
  foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
  $json = json_decode((string)$res, true);
  if ($code < 200 || $code >= 300) throw new RuntimeException("$method $path -> HTTP $code: " . substr((string)$res, 0, 400));
  return $json;
}
function trpcQuery($proc, $input = null) {
  $q = $input === null ? '' : '?input=' . rawurlencode(json_encode(['json' => $input]));
  $r = api('GET', "/api/trpc/$proc$q");
  return $r['result']['data']['json'] ?? null;
}
function trpcMutate($proc, $input) {
  $r = api('POST', "/api/trpc/$proc", ['json' => $input]);
  return $r['result']['data']['json'] ?? null;
}
function cid() { return 'c' . bin2hex(random_bytes(12)); }

// ---------- Container einsammeln (wie gen-services.php) ----------
$running = array_values(array_filter(array_map('trim', file('php://stdin'))));
$runLc = array_map('strtolower', $running);

$groups = [
  'Medien'     => ['jellyfin','jellyseerr','seerr','jellystat','plex','emby','audiobookshelf','navidrome','music-assistant','musicassistant','kidsdashboard','tdarr','fileflows'],
  'Arr'        => ['sonarr','radarr','prowlarr','bazarr','lidarr','readarr','nzbhydra','recyclarr','profilarr','sabthrottle','strm','trailarr','umlautadaptarr','notifiarr','sabnzbd','nzbdav','infinidysk','infiny'],
  'Downloads'  => ['jdownloader','tg2','unpackerr','gluetun','qbittorrent','pyload'],
  'PS5'        => ['fpkg','ps5','filezilla'],
  'Smart Home' => ['home-assistant','homeassistant','zigbee','mosquitto','node-red','nodered','esphome','evcc','frigate','scrypted','hermes'],
  'Dokumente'  => ['paperless','sharepoint','immich','nextcloud'],
  'Netzwerk'   => ['adguard','cloudflare','ddns','unifi','nginx','npm','swag','wireguard','wg-easy','traefik','speedtest'],
];
$override = ['chromium-web' => 'PS5', 'filezilla' => 'PS5', 'yarr' => 'Arr'];
if (is_file($cfg['groups'])) foreach (file($cfg['groups']) as $l)
  if (preg_match('/^\s*([^#=]+?)\s*=\s*(.+?)\s*$/', $l, $m)) $override[strtolower($m[1])] = $m[2];

$services = []; $secrets = []; $skipped = [];
foreach (glob("{$cfg['tpl']}/my-*.xml") as $f) {
  $x = @simplexml_load_file($f);
  if (!$x || !isset($x->Name)) continue;
  $name = (string)$x->Name;
  $i = array_search(strtolower($name), $runLc, true);
  if ($i === false || strtolower($name) === $cfg['self']) continue;
  $webui = trim((string)$x->WebUI);
  if ($webui === '') { $skipped[] = $name; continue; }
  $ports = []; $cfgPath = '';
  foreach ($x->Config as $c) {
    $t = (string)$c['Type']; $target = (string)$c['Target']; $val = trim((string)$c);
    if ($t === 'Port' && $val !== '') $ports[$target] = $val;
    if ($t === 'Path' && $target === '/config' && $val !== '') $cfgPath = $val;
  }
  // Eigene IP (br0/custom), sonst Server-IP
  $net = (string)$x->Network;
  $myIp = trim((string)$x->MyIP);
  $useIp = ($myIp !== '' && !in_array($net, ['bridge', 'host'], true) && !str_starts_with($net, 'container:')) ? $myIp : $cfg['ip'];
  $href = str_replace('[IP]', $useIp, $webui);
  // Container-Port -> Host-Port; zeigt die WebUI auf einen ungemappten Port und gibt es genau ein Mapping, dieses nehmen
  $href = preg_replace_callback('/\[PORT:(\d+)\]/', function ($m) use ($ports, $net) {
    if (isset($ports[$m[1]])) return $ports[$m[1]];
    if ($net === 'bridge' && count($ports) === 1) return reset($ports);
    return $m[1];
  }, $href);
  $base = preg_replace('#^(https?://[^/]+).*$#', '$1', $href);
  $repo = strtolower((string)$x->Repository);
  $key  = strtolower($name) . ' ' . $repo;
  $grp  = $override[strtolower($name)] ?? null;
  if ($grp === null) {
    $grp = 'System';
    foreach ($groups as $g => $words) foreach ($words as $w) if (str_contains($key, $w)) { $grp = $g; break 2; }
  }
  $icon = trim((string)$x->Icon);
  if (!preg_match('#^https?://#', $icon)) $icon = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/' . strtolower($name) . '.png';
  $desc = preg_match('#^container:(.+)$#', (string)$x->Network, $m) ? 'via ' . $m[1] : null;
  $services[$grp][] = ['name' => $name, 'href' => $href, 'icon' => $icon, 'desc' => $desc];

  // API-Keys fuer Integrationen
  $c = $cfgPath ?: "{$cfg['appdata']}/$name";
  foreach (['sonarr','radarr','prowlarr','lidarr','readarr'] as $k)
    if (str_contains($key, $k) && is_file("$c/config.xml")
        && preg_match('#<ApiKey>([^<]+)</ApiKey>#', file_get_contents("$c/config.xml"), $m)) { $secrets[] = [$k, $name, $base, $m[1]]; break; }
  if (str_contains($key, 'sabnzbd'))
    foreach (["$c/sabnzbd.ini", "$c/config/sabnzbd.ini"] as $ini)
      if (is_file($ini) && preg_match('/^api_key\s*=\s*(\S+)/m', file_get_contents($ini), $m)) { $secrets[] = ['sabNzbd', $name, $base, $m[1]]; break; }
  if (str_contains($key, 'jellyfin/jellyfin') || strtolower($name) === 'jellyfin')
    if ($cfg['jfkey'] !== '') $secrets[] = ['jellyfin', $name, $base, $cfg['jfkey']];
}
if ($cfg['urkey'] !== '') $secrets[] = ['unraid', 'Unraid', $cfg['unraid'], $cfg['urkey']];
if ($skipped) out('Ohne WebUI (nicht aufgenommen): ' . implode(', ', $skipped));

// Schnellzugriff
$quick = [];
if (is_file($cfg['quick'])) foreach (file($cfg['quick']) as $l) {
  $l = trim($l); if ($l === '' || $l[0] === '#') continue;
  $p = array_map('trim', explode('|', $l)); if (count($p) < 2) continue;
  $dom = $p[2] ?? parse_url($p[1], PHP_URL_HOST);
  $quick[] = ['name' => $p[0], 'href' => $p[1], 'icon' => "https://www.google.com/s2/favicons?domain=$dom&sz=128", 'desc' => null];
}
$unraidApps = [['name' => 'Unraid', 'href' => $cfg['unraid'] . '/', 'icon' => 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/svg/unraid.svg', 'desc' => 'Server ' . $cfg['ip']]];

$order = array_merge(['Schnellzugriff', 'Unraid'], array_keys($groups), array_keys($services), ['System']);
$all = ['Schnellzugriff' => $quick, 'Unraid' => $unraidApps] + $services;
$sections = [];
foreach (array_unique($order) as $g) {
  if (empty($all[$g])) continue;
  if (!in_array($g, ['Schnellzugriff', 'Unraid'], true)) usort($all[$g], fn($a, $b) => strcasecmp($a['name'], $b['name']));
  $sections[$g] = $all[$g];
}

if ($cfg['dry']) {
  foreach ($sections as $g => $apps) out("$g: " . implode(', ', array_column($apps, 'name')));
  out('Integrationen: ' . implode(', ', array_map(fn($s) => "{$s[0]} ({$s[2]})", $secrets)));
  exit(0);
}

// ---------- Homarr ----------
$info = api('GET', '/api/info');
out("Homarr {$info['version']} erreichbar");

// Apps anlegen/aktualisieren (Abgleich ueber den Namen)
$existing = [];
foreach (api('GET', '/api/apps') as $a) $existing[strtolower($a['name'])] = $a['id'];
foreach ($sections as $g => &$apps) foreach ($apps as &$a) {
  $body = ['name' => mb_substr($a['name'], 0, 64), 'description' => $a['desc'], 'iconUrl' => $a['icon'],
           'href' => $a['href'], 'pingUrl' => preg_match('#^https?://#', $a['href']) ? $a['href'] : null];
  $id = $existing[strtolower($body['name'])] ?? null;
  if ($id) { api('PATCH', "/api/apps/$id", $body); }
  else { $r = api('POST', '/api/apps', $body); $id = $r['appId'] ?? $r['id']; $existing[strtolower($body['name'])] = $id; }
  $a['appId'] = $id;
}
unset($apps, $a);
out('Apps: ' . array_sum(array_map('count', $sections)));

// Integrationen (nur anlegen, wenn Art + URL noch nicht existieren)
$integ = [];
foreach ((array)trpcQuery('integration.all') as $it) $integ[$it['kind'] . '|' . rtrim($it['url'], '/')] = $it['id'];
foreach ($secrets as [$kind, $name, $url, $secret]) {
  $k = "$kind|" . rtrim($url, '/');
  if (isset($integ[$k])) continue;
  try {
    $r = trpcMutate('integration.create', ['name' => $name, 'url' => $url, 'kind' => $kind,
      'secrets' => [['kind' => 'apiKey', 'value' => $secret]], 'attemptSearchEngineCreation' => false]);
    if (isset($r['integration']['id'])) { $integ[$k] = $r['integration']['id']; out("Integration $name angelegt"); }
    else out("Integration $name: Verbindungstest fehlgeschlagen " . json_encode($r['error'] ?? $r));
  } catch (Throwable $e) { out("Integration $name: " . $e->getMessage()); }
}
$byKind = [];
foreach ($integ as $k => $id) $byKind[explode('|', $k)[0]][] = $id;

// Board anlegen bzw. finden
$boardId = null;
foreach (api('GET', '/api/boards') as $b) if (strcasecmp($b['name'], $cfg['board']) === 0) $boardId = $b['id'];
if (!$boardId) { $r = api('POST', '/api/boards', ['name' => $cfg['board'], 'columnCount' => 12, 'isPublic' => false]); $boardId = $r['boardId']; out("Board {$cfg['board']} angelegt"); }
$board = trpcQuery('board.getBoardByName', ['name' => $cfg['board']]);
$layouts = $board['layouts'];
$roots = array_values(array_filter($board['sections'], fn($s) => $s['kind'] === 'empty'));
$main = null;
foreach ($roots as $r) if ((int)$r['xOffset'] !== -1 && (int)$r['xOffset'] !== 1 && ($main === null || $r['yOffset'] < $main['yOffset'])) $main = $r;
if (!$main) throw new RuntimeException('Keine Hauptsektion im Board gefunden');

function laneCols($l) {
  $t = max(1, (int)$l['columnCount']);
  if (($l['role'] ?? '') === 'mobile') return $t;
  $left = min(max(0, (int)($l['leftGutterColumnCount'] ?? 0)), $t - 1);
  $right = min(max(0, (int)($l['rightGutterColumnCount'] ?? 0)), $t - $left - 1);
  return $t - $left - $right;
}

// Inhalt komplett neu aufbauen (vorhandene Root-Sektionen bleiben, alles andere wird ersetzt)
$style = $cfg['style'];
$outSections = array_map(fn($s) => ['id' => $s['id'], 'kind' => 'empty', 'yOffset' => (int)$s['yOffset'], 'xOffset' => (int)$s['xOffset']], $roots);
$outItems = [];
$y = array_fill_keys(array_column($layouts, 'id'), 0);     // Cursor fuer volle Breite
$colY = array_fill_keys(array_column($layouts, 'id'), []); // Cursor je Spalte (Stil kompakt)
$adv = ['title' => null, 'customCssClasses' => [], 'borderColor' => ''];

// $span: 'full' = volle Breite, 'col' = Spalte (bei breitem Layout 3 Spalten nebeneinander)
$addContainer = function (string $title, array $children, callable $place, string $span = 'full', bool $label = true)
  use (&$outSections, &$outItems, &$y, &$colY, $layouts, $main) {
  $sid = cid();
  $secLayouts = []; $childLayouts = [];
  foreach ($layouts as $l) {
    $lid = $l['id']; $lane = laneCols($l);
    $n = ($span === 'col' && $lane >= 9) ? 3 : 1;
    if ($n === 1) {
      $start = max([$y[$lid], ...array_values($colY[$lid])]);
      [$h, $pos] = $place($lane);
      $secLayouts[] = ['layoutId' => $lid, 'xOffset' => 0, 'yOffset' => $start, 'width' => $lane, 'height' => $h, 'parentSectionId' => $main['id']];
      $y[$lid] = $start + $h; $colY[$lid] = [];
    } else {
      $cw = intdiv($lane, $n);
      for ($c = 0; $c < $n; $c++) $colY[$lid][$c] ??= $y[$lid];
      $c = array_keys($colY[$lid], min($colY[$lid]))[0];
      $w = $c === $n - 1 ? $lane - $cw * ($n - 1) : $cw;
      [$h, $pos] = $place($w);
      $secLayouts[] = ['layoutId' => $lid, 'xOffset' => $c * $cw, 'yOffset' => $colY[$lid][$c], 'width' => $w, 'height' => $h, 'parentSectionId' => $main['id']];
      $colY[$lid][$c] += $h;
    }
    foreach ($pos as $idx => $p) $childLayouts[$idx][] = $p + ['layoutId' => $lid, 'sectionId' => $sid];
  }
  $outSections[] = ['id' => $sid, 'kind' => 'container', 'collapsed' => false,
    'options' => ['title' => $title, 'customCssClasses' => [], 'borderColor' => '', 'showLabel' => $label, 'collapsible' => false, 'showOpenAll' => false, 'scrollable' => false],
    'layouts' => $secLayouts];
  foreach ($children as $idx => $c) $outItems[] = $c + ['id' => cid(), 'layouts' => $childLayouts[$idx]];
};

// Raster fuer App-Kacheln: $tw Spalten pro Kachel
$grid = function (int $count, int $tw) {
  return function ($cols) use ($count, $tw) {
    $tw = min($tw, $cols); $per = max(1, intdiv($cols, $tw)); $pos = [];
    for ($i = 0; $i < $count; $i++) $pos[] = ['xOffset' => ($i % $per) * $tw, 'yOffset' => intdiv($i, $per), 'width' => $tw, 'height' => 1];
    return [max(1, (int)ceil($count / $per)), $pos];
  };
};
$appItem = fn($a, $layout = null) => ['kind' => 'app', 'options' => array_filter(['appId' => $a['appId'], 'openInNewTab' => true, 'showTitle' => true, 'layout' => $layout], fn($v) => $v !== null),
  'integrationIds' => [], 'advancedOptions' => $adv];

if ($style === 'kompakt') {
  // Kopfband: Uhr mit Wetter + Schnellzugriff als Lesezeichen-Leiste
  $loc = ['name' => $cfg['city'], 'latitude' => $cfg['lat'], 'longitude' => $cfg['lon']];
  $quickIds = array_column(array_merge($sections['Unraid'] ?? [], $sections['Schnellzugriff'] ?? []), 'appId');
  $band = [
    ['kind' => 'clock', 'options' => ['is24HourFormat' => true, 'showDate' => true, 'showWeather' => true, 'weatherLocation' => $loc, 'useCustomTimezone' => true, 'timezone' => 'Europe/Berlin'], 'integrationIds' => [], 'advancedOptions' => $adv],
    ['kind' => 'bookmarks', 'options' => ['title' => '', 'showTitle' => false, 'layout' => 'row', 'variant' => 'soft', 'spacing' => 'md', 'hideHostname' => true, 'openNewTab' => true, 'grow' => true, 'items' => $quickIds], 'integrationIds' => [], 'advancedOptions' => $adv],
  ];
  $addContainer('Start', $band, function ($cols) {
    if ($cols < 9) return [4, [['xOffset' => 0, 'yOffset' => 0, 'width' => $cols, 'height' => 2], ['xOffset' => 0, 'yOffset' => 2, 'width' => $cols, 'height' => 2]]];
    return [2, [['xOffset' => 0, 'yOffset' => 0, 'width' => 3, 'height' => 2], ['xOffset' => 3, 'yOffset' => 0, 'width' => $cols - 3, 'height' => 2]]];
  }, 'full', false);
  // Gruppen als Listen in drei Spalten nebeneinander, Kacheln quer (Icon links, Name rechts)
  foreach ($sections as $title => $apps) {
    if (in_array($title, ['Schnellzugriff', 'Unraid'], true)) continue;
    $addContainer($title, array_map(fn($a) => $appItem($a, 'row'), $apps), $grid(count($apps), 2), 'col');
  }
} else {
  foreach ($sections as $title => $apps)
    $addContainer($title, array_map(fn($a) => $appItem($a), $apps), $grid(count($apps), 1));
}

// Status: Downloads (SABnzbd) + Mediaserver (Jellyfin), falls Integrationen vorhanden
$status = [];
if (!empty($byKind['sabNzbd']))  $status[] = ['kind' => 'downloads',   'options' => new stdClass(), 'integrationIds' => $byKind['sabNzbd'],  'advancedOptions' => $adv];
if (!empty($byKind['jellyfin'])) $status[] = ['kind' => 'mediaServer', 'options' => new stdClass(), 'integrationIds' => $byKind['jellyfin'], 'advancedOptions' => $adv];
if ($status) $addContainer('Status', $status, function ($cols) use ($status) {
  $w = count($status) > 1 && $cols >= 8 ? intdiv($cols, 2) : $cols;
  $pos = []; $x = 0; $yy = 0;
  foreach ($status as $s) { if ($x + $w > $cols) { $x = 0; $yy += 4; } $pos[] = ['xOffset' => $x, 'yOffset' => $yy, 'width' => $w, 'height' => 4]; $x += $w; }
  return [$yy + 4, $pos];
});

// Docker-Steuerung (Start/Stop/Restart) ueber den Docker-Socket
$addContainer('Docker', [['kind' => 'dockerContainers', 'options' => new stdClass(), 'integrationIds' => [], 'advancedOptions' => $adv]],
  fn($cols) => [8, [['xOffset' => 0, 'yOffset' => 0, 'width' => $cols, 'height' => 8]]]);

api('PUT', "/api/boards/$boardId/content", ['sections' => $outSections, 'items' => $outItems]);
out('Board gespeichert: ' . count($outSections) . ' Sektionen, ' . count($outItems) . ' Elemente');
if ($style === 'kompakt') {
  $css = "body { background: radial-gradient(1200px 600px at 10% -10%, #1e3a8a55, transparent), radial-gradient(900px 500px at 110% 10%, #7c3aed44, transparent), #0b1020 !important; }";
  try { api('PATCH', "/api/boards/$boardId/settings", ['primaryColor' => '#3b82f6', 'secondaryColor' => '#a855f7', 'opacity' => 80, 'itemRadius' => 'md', 'customCss' => $css]); out('Board-Optik gesetzt'); }
  catch (Throwable $e) { out('Board-Optik: ' . $e->getMessage()); }
}
if ($cfg['home']) { try { api('PATCH', "/api/boards/$boardId/home"); out('Als Startboard gesetzt'); } catch (Throwable $e) { out('Startboard: ' . $e->getMessage()); } }
out("Fertig: {$cfg['url']}/boards/{$cfg['board']}");
