<?php
// Erzeugt services.yaml fuer gethomepage aus den Unraid-Templates (my-*.xml) der laufenden Container
$tplDir  = getenv('TPL_DIR') ?: '/boot/config/plugins/dockerMan/templates-user';
$ip      = getenv('HOST_IP') ?: '192.168.0.20';
$appdata = getenv('APPDATA') ?: '/mnt/cache/appdata';
$self    = strtolower(getenv('SELF_NAME') ?: 'homepage');
$running = array_filter(array_map('trim', file('php://stdin')));
$runLc   = array_map('strtolower', $running);

$groups = [
  'Medien'      => ['jellyfin','jellyseerr','seerr','jellystat','plex','emby','audiobookshelf','navidrome','music-assistant','musicassistant','tdarr','fileflows'],
  'Arr'         => ['sonarr','radarr','prowlarr','bazarr','lidarr','readarr','nzbhydra','recyclarr','profilarr','sabthrottle','strm'],
  'Downloads'   => ['sabnzbd','jdownloader','nzbdav','infinidysk','infiny','tg2','unpackerr','gluetun','qbittorrent','pyload','fpkg','ps5'],
  'Smart Home'  => ['home-assistant','homeassistant','zigbee','mosquitto','node-red','nodered','esphome','evcc','frigate','hermes'],
  'Dokumente'   => ['paperless','sharepoint','immich','nextcloud'],
  'Netzwerk'    => ['adguard','cloudflare','ddns','unifi','nginx','npm','swag','wireguard','wg-easy','traefik','speedtest'],
];
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
  $grp  = 'System';
  foreach ($groups as $g => $words) foreach ($words as $w) if (str_contains($key, $w)) { $grp = $g; break 2; }

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
  $out[$grp][] = $svc;
}

$q = fn($s) => '"' . str_replace(['\\','"'], ['\\\\','\\"'], $s) . '"';
$order = array_merge(array_keys($groups), ['System']);
foreach ($order as $g) {
  if (empty($out[$g])) continue;
  usort($out[$g], fn($a, $b) => strcasecmp($a['name'], $b['name']));
  echo "- $g:\n";
  foreach ($out[$g] as $s) {
    echo "    - {$q($s['name'])}:\n";
    echo "        href: {$q($s['href'])}\n";
    if ($s['icon'] !== '') echo "        icon: {$q($s['icon'])}\n";
    if (isset($s['desc'])) echo "        description: {$q($s['desc'])}\n";
    echo "        server: unraid\n        container: {$q($s['container'])}\n";
    if (isset($s['widget'])) {
      echo "        widget:\n";
      foreach ($s['widget'] as $k => $v) echo "          $k: {$q($v)}\n";
    }
  }
  echo "\n";
}
if ($skipped) fwrite(STDERR, "Ohne WebUI (nicht aufgenommen): " . implode(', ', $skipped) . "\n");
