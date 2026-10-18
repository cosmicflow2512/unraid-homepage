<?php
// Erzeugt services.yaml fuer gethomepage aus den Unraid-Templates (my-*.xml) der laufenden Container
$tplDir  = getenv('TPL_DIR') ?: '/boot/config/plugins/dockerMan/templates-user';
$ip      = getenv('HOST_IP') ?: '192.168.0.20';
$appdata = getenv('APPDATA') ?: '/mnt/cache/appdata';
$self    = strtolower(getenv('SELF_NAME') ?: 'homepage');
$unraidUrl = getenv('UNRAID_URL') ?: "https://$ip:444";
$running = array_filter(array_map('trim', file('php://stdin')));
$runLc   = array_map('strtolower', $running);

$groups = [
  'Medien'      => ['jellyfin','jellyseerr','seerr','jellystat','plex','emby','audiobookshelf','navidrome','music-assistant','musicassistant','kidsdashboard','tdarr','fileflows'],
  'Arr'         => ['sonarr','radarr','prowlarr','bazarr','lidarr','readarr','nzbhydra','recyclarr','profilarr','sabthrottle','strm','trailarr','umlautadaptarr','notifiarr','sabnzbd','nzbdav','infinidysk','infiny'],
  'Downloads'   => ['jdownloader','tg2','unpackerr','gluetun','qbittorrent','pyload'],
  'PS5'         => ['fpkg','ps5','filezilla'],
  'Smart Home'  => ['home-assistant','homeassistant','zigbee','mosquitto','node-red','nodered','esphome','evcc','frigate','scrypted','hermes'],
  'Dokumente'   => ['paperless','sharepoint','immich','nextcloud'],
  'Netzwerk'    => ['adguard','cloudflare','ddns','unifi','nginx','npm','swag','wireguard','wg-easy','traefik','speedtest'],
];
$hpEnv = [];
foreach (explode("\n", (string)shell_exec('docker inspect -f ' . escapeshellarg('{{range .Config.Env}}{{println .}}{{end}}') . ' ' . escapeshellarg($self) . ' 2>/dev/null')) as $l)
  if (preg_match('/^(HOMEPAGE_VAR_\w+)=(.+)$/', trim($l), $m)) $hpEnv[$m[1]] = $m[2];
// Feste Zuordnung per Containername (Vorrang vor Stichwoertern)
$override = ['chromium-web' => 'PS5', 'filezilla' => 'PS5', 'yarr' => 'Arr'];
// Eigene Zuordnungen: Datei groups.override neben diesem Skript, Zeilen "Containername=Gruppe"
$ovFile = getenv('GROUPS_FILE') ?: __DIR__ . '/groups.override';
if (is_file($ovFile)) foreach (file($ovFile) as $l)
  if (preg_match('/^\s*([^#=]+?)\s*=\s*(.+?)\s*$/', $l, $m)) $override[strtolower($m[1])] = $m[2];
$arrWidgets = ['sonarr','radarr','prowlarr','lidarr','readarr'];

$out = []; $skipped = [];
foreach (glob("$tplDir/my-*.xml") as $f) {
  $x = @simplexml_load_file($f);
  if (!$x || !isset($x->Name)) continue;
  $name = (string)$x->Name;
  $i = array_search(strtolower($name), $runLc, true);
  if ($i === false || strtolower($name) === $self) continue;
  $webui = trim((string)$x->WebUI);
  if ($webui === '') { $skipped[] = $name; continue; }

  // Port-Mapping Container->Host aus dem Template
  $ports = []; $cfgPath = '';
  foreach ($x->Config as $c) {
    $t = (string)$c['Type']; $target = (string)$c['Target']; $val = trim((string)$c);
    if ($t === 'Port' && $val !== '') $ports[$target] = $val;
    if ($t === 'Path' && $target === '/config' && $val !== '') $cfgPath = $val;
  }
  $href = str_replace('[IP]', $ip, $webui);
  $href = preg_replace_callback('/\[PORT:(\d+)\]/', fn($m) => $ports[$m[1]] ?? $m[1], $href);
  $base = preg_replace('#^(https?://[^/]+).*$#', '$1', $href);

  $repo = strtolower((string)$x->Repository);
  $key  = strtolower($name) . ' ' . $repo;
  $grp  = $override[strtolower($name)] ?? null;
  if ($grp === null) {
    $grp = 'System';
    foreach ($groups as $g => $words) foreach ($words as $w) if (str_contains($key, $w)) { $grp = $g; break 2; }
  }

  $svc = ['name' => $name, 'href' => $href, 'icon' => trim((string)$x->Icon), 'container' => $running[$i]];
  if (preg_match('#^container:(.+)$#', (string)$x->Network, $m)) $svc['desc'] = 'via ' . $m[1];

  // Widgets mit automatisch ausgelesenem API-Key
  $cfg = $cfgPath ?: "$appdata/$name";
  foreach ($arrWidgets as $w) {
    if (str_contains($key, $w) && is_file("$cfg/config.xml")
        && preg_match('#<ApiKey>([^<]+)</ApiKey>#', file_get_contents("$cfg/config.xml"), $m)) {
      $svc['widget'] = ['type' => $w, 'url' => $base, 'key' => $m[1]]; break;
    }
  }
  if (!isset($svc['widget']) && str_contains($key, 'sabnzbd')) {
    foreach (["$cfg/sabnzbd.ini", "$cfg/config/sabnzbd.ini"] as $ini)
      if (is_file($ini) && preg_match('/^api_key\s*=\s*(\S+)/m', file_get_contents($ini), $m)) {
        $svc['widget'] = ['type' => 'sabnzbd', 'url' => $base, 'key' => $m[1]]; break;
      }
  }
  if (!isset($svc['widget']) && str_contains($key, 'jellyfin') && !str_contains($key, 'jellyseerr') && !str_contains($key, 'jellystat')
      && !empty($hpEnv['HOMEPAGE_VAR_JELLYFIN_KEY'])) {
    $svc['widget'] = ['type' => 'jellyfin', 'url' => $base, 'key' => '{{HOMEPAGE_VAR_JELLYFIN_KEY}}', 'version' => 2,
      'enableBlocks' => true, 'enableNowPlaying' => true, 'enableMediaControl' => false, 'showEpisodeNumber' => true,
      'fields' => ['movies', 'series', 'episodes']];
  }
  $out[$grp][] = $svc;
}

$ur = ['name' => 'Unraid', 'href' => $unraidUrl . '/', 'icon' => 'unraid.png', 'desc' => 'Server ' . $ip];
if (!empty($hpEnv['HOMEPAGE_VAR_UNRAID_KEY']))
  $ur['widget'] = ['type' => 'unraid', 'url' => $unraidUrl, 'key' => '{{HOMEPAGE_VAR_UNRAID_KEY}}',
    'fields' => ['status', 'cpu', 'memoryPercent', 'arrayFree', 'arrayUsedPercent', 'notifications']];
$dk = ['name' => 'Docker', 'href' => $unraidUrl . '/Docker', 'icon' => 'docker.png', 'desc' => 'Container starten/stoppen'];
$out = ['Unraid' => [$ur, $dk]] + $out;

// Schnellzugriff aus quicklinks.txt (Zeilen: Name|URL|Domain fuer Icon)
$qlFile = getenv('QUICKLINKS_FILE') ?: __DIR__ . '/quicklinks.txt';
$ql = [];
if (is_file($qlFile)) foreach (file($qlFile) as $l) {
  $l = trim($l); if ($l === '' || $l[0] === '#') continue;
  $p = array_map('trim', explode('|', $l));
  if (count($p) < 2) continue;
  $dom = $p[2] ?? parse_url($p[1], PHP_URL_HOST);
  $ql[] = ['name' => $p[0], 'href' => $p[1], 'id' => 'ql-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($p[0])),
           'icon' => 'https://www.google.com/s2/favicons?domain=' . $dom . '&sz=128'];
}
if ($ql) $out = ['Schnellzugriff' => $ql] + $out;

$q = fn($s) => '"' . str_replace(['\\','"'], ['\\\\','\\"'], $s) . '"';
$order = array_values(array_unique(array_merge(['Schnellzugriff', 'Unraid'], array_keys($groups), array_keys($out), ['System'])));
foreach ($order as $g) {
  if (empty($out[$g])) continue;
  if (!in_array($g, ['Schnellzugriff', 'Unraid'], true)) usort($out[$g], fn($a, $b) => strcasecmp($a['name'], $b['name']));
  echo "- $g:\n";
  foreach ($out[$g] as $s) {
    echo "    - {$q($s['name'])}:\n";
    echo "        href: {$q($s['href'])}\n";
    if (isset($s['id'])) echo "        id: {$q($s['id'])}\n";
    if ($s['icon'] !== '') echo "        icon: {$q($s['icon'])}\n";
    if (isset($s['desc'])) echo "        description: {$q($s['desc'])}\n";
    if (isset($s['container'])) echo "        server: unraid\n        container: {$q($s['container'])}\n";
    if (isset($s['widget'])) {
      echo "        widget:\n";
      foreach ($s['widget'] as $k => $v) {
        if (is_bool($v)) $v = $v ? 'true' : 'false';
        elseif (is_int($v)) $v = (string)$v;
        elseif (is_array($v)) $v = '[' . implode(', ', array_map($q, $v)) . ']';
        else $v = $q($v);
        echo "          $k: $v\n";
      }
    }
  }
  echo "\n";
}
if ($skipped) fwrite(STDERR, "Ohne WebUI (nicht aufgenommen): " . implode(', ', $skipped) . "\n");
