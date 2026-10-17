#!/bin/bash
# gethomepage auf Unraid einrichten: Template + Config + services.yaml aus allen laufenden Containern
set -e
HOST_IP="${HOST_IP:-192.168.0.20}"
HP_PORT="${HP_PORT:-3010}"
HP_DIR="${HP_DIR:-/mnt/cache/appdata/homepage}"
TPL_DIR="${TPL_DIR:-/boot/config/plugins/dockerMan/templates-user}"

mkdir -p "$HP_DIR/config" "$HP_DIR/icons" "$TPL_DIR"

# --- Unraid-Template ---
cat > "$TPL_DIR/my-homepage.xml" <<EOF
<?xml version="1.0"?>
<Container version="2">
  <Name>homepage</Name>
  <Repository>ghcr.io/gethomepage/homepage:latest</Repository>
  <TemplateURL>https://raw.githubusercontent.com/cosmicflow2512/unraid-homepage/main/templates/my-homepage.xml</TemplateURL>
  <Registry>https://github.com/gethomepage/homepage/pkgs/container/homepage</Registry>
  <Network>bridge</Network>
  <MyIP/>
  <Shell>sh</Shell>
  <Privileged>false</Privileged>
  <Support>https://github.com/gethomepage/homepage</Support>
  <Project>https://gethomepage.dev</Project>
  <Overview>Startseite/Dashboard fuer alle Docker-Container (gethomepage).</Overview>
  <Category>Tools: Network:Management</Category>
  <WebUI>http://[IP]:[PORT:3000]/</WebUI>
  <Icon>https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/homepage.png</Icon>
  <ExtraParams/>
  <PostArgs/>
  <Config Name="WebUI" Target="3000" Default="3010" Mode="tcp" Description="Web-Port" Type="Port" Display="always" Required="true" Mask="false">$HP_PORT</Config>
  <Config Name="Config" Target="/app/config" Default="$HP_DIR/config" Mode="rw" Description="YAML-Konfiguration" Type="Path" Display="always" Required="true" Mask="false">$HP_DIR/config</Config>
  <Config Name="Icons" Target="/app/public/icons" Default="$HP_DIR/icons" Mode="rw" Description="Eigene Icons (/icons/name.png)" Type="Path" Display="always" Required="false" Mask="false">$HP_DIR/icons</Config>
  <Config Name="Docker Socket" Target="/var/run/docker.sock" Default="/var/run/docker.sock" Mode="ro" Description="Container-Status/Stats" Type="Path" Display="advanced" Required="false" Mask="false">/var/run/docker.sock</Config>
  <Config Name="HOMEPAGE_ALLOWED_HOSTS" Target="HOMEPAGE_ALLOWED_HOSTS" Default="$HOST_IP:$HP_PORT" Mode="" Description="Komma-getrennt, ohne Leerzeichen" Type="Variable" Display="always" Required="true" Mask="false">$HOST_IP:$HP_PORT</Config>
</Container>
EOF

# --- settings.yaml ---
cat > "$HP_DIR/config/settings.yaml" <<'EOF'
title: Baum
theme: dark
color: slate
headerStyle: clean
statusStyle: dot
language: de
target: _blank
EOF

# --- widgets.yaml (Kopfzeile) ---
cat > "$HP_DIR/config/widgets.yaml" <<'EOF'
- resources:
    cpu: true
    memory: true
    disk: /

- search:
    provider: google
    focus: true
    showSearchSuggestions: true
    target: _self

- datetime:
    text_size: xl
    locale: de
    format:
      dateStyle: long
      timeStyle: short
      hour12: false
EOF

# --- docker.yaml ---
cat > "$HP_DIR/config/docker.yaml" <<'EOF'
unraid:
  socket: /var/run/docker.sock
EOF

# --- bookmarks.yaml ---
cat > "$HP_DIR/config/bookmarks.yaml" <<EOF
- Server:
    - Unraid:
        - abbr: UR
          href: http://$HOST_IP/
- Dev:
    - GitHub:
        - abbr: GH
          href: https://github.com/cosmicflow2512
EOF

# --- Generator (liest my-*.xml der laufenden Container) ---
cat > "$HP_DIR/gen-services.php" <<'PHPEOF'
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
PHPEOF

# --- Refresh-Skript: jederzeit neu ausfuehrbar, wenn Container dazukommen ---
cat > "$HP_DIR/refresh.sh" <<EOF
#!/bin/bash
cd "$HP_DIR"
[ -f config/services.yaml ] && cp config/services.yaml config/services.yaml.bak
docker ps --format '{{.Names}}' | HOST_IP=$HOST_IP TPL_DIR=$TPL_DIR APPDATA=$(dirname "$HP_DIR") php gen-services.php > config/services.yaml.new \
  && mv config/services.yaml.new config/services.yaml
echo "services.yaml: \$(grep -c 'href:' config/services.yaml) Dienste"
EOF
chmod +x "$HP_DIR/refresh.sh"
"$HP_DIR/refresh.sh"

chown -R nobody:users "$HP_DIR"
echo
echo "Fertig. Jetzt: Docker -> Add Container -> Template 'homepage' waehlen -> Apply"
echo "Danach: http://$HOST_IP:$HP_PORT"
