#!/bin/bash
# Homarr auf Unraid: Template mit erzeugtem Schluessel + Sync-Skript
set -e
HA_DIR="${HA_DIR:-/mnt/cache/appdata/homarr}"
SYNC_DIR="${SYNC_DIR:-/mnt/cache/appdata/homarr-sync}"
TPL_DIR="${TPL_DIR:-/boot/config/plugins/dockerMan/templates-user}"
RAW=https://raw.githubusercontent.com/cosmicflow2512/unraid-homepage/main
mkdir -p "$HA_DIR" "$SYNC_DIR" "$TPL_DIR"

if [ ! -f "$HA_DIR/SECRET_ENCRYPTION_KEY.txt" ]; then
  openssl rand -hex 32 > "$HA_DIR/SECRET_ENCRYPTION_KEY.txt"
  chmod 600 "$HA_DIR/SECRET_ENCRYPTION_KEY.txt"
fi
KEY=$(cat "$HA_DIR/SECRET_ENCRYPTION_KEY.txt")
curl -fsSL "$RAW/templates/my-homarr.xml" \
  | sed "s#Mask=\"true\"></Config>#Mask=\"true\">$KEY</Config>#" > "$TPL_DIR/my-homarr.xml"

curl -fsSL -o "$SYNC_DIR/homarr-sync.php" "$RAW/homarr/homarr-sync.php"
# Schnellzugriff und Gruppen vom Homepage-Setup uebernehmen, falls vorhanden
for f in quicklinks.txt groups.override; do
  [ -f "$SYNC_DIR/$f" ] || { [ -f /mnt/cache/appdata/homepage/$f ] && cp /mnt/cache/appdata/homepage/$f "$SYNC_DIR/$f"; } || true
done
[ -f "$SYNC_DIR/quicklinks.txt" ] || cat > "$SYNC_DIR/quicklinks.txt" <<'EOF'
Kleinanzeigen|https://www.kleinanzeigen.de/|kleinanzeigen.de
KNX-User-Forum|https://knx-user-forum.de/|knx-user-forum.de
mydealz|https://www.mydealz.de/|mydealz.de
Amazon|https://www.amazon.de/|amazon.de
Amazon Retourenkauf|https://www.amazon.de/s?k=Amazon+Retourenkauf|amazon.de
idealo|https://www.idealo.de/|idealo.de
YouTube|https://www.youtube.com/|youtube.com
EOF
[ -f "$SYNC_DIR/sync.env" ] || cat > "$SYNC_DIR/sync.env" <<'EOF'
# API-Key aus Homarr: Verwaltung -> Tools -> API
HOMARR_API_KEY=
HOMARR_URL=http://192.168.0.20:7575
HOST_IP=192.168.0.20
UNRAID_URL=https://192.168.0.20:444
# optional
JELLYFIN_KEY=
UNRAID_KEY=
EOF
chmod 600 "$SYNC_DIR/sync.env"
cat > "$SYNC_DIR/sync.sh" <<EOF
#!/bin/bash
set -a; . "$SYNC_DIR/sync.env"; set +a
docker ps --format '{{.Names}}' | php "$SYNC_DIR/homarr-sync.php"
EOF
chmod +x "$SYNC_DIR/sync.sh"
echo "Template angelegt. Schluessel: $HA_DIR/SECRET_ENCRYPTION_KEY.txt"
echo "Danach: API-Key in $SYNC_DIR/sync.env eintragen und $SYNC_DIR/sync.sh ausfuehren"
