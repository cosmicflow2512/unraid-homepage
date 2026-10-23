# Homarr-Variante

Baut ein komplettes [Homarr](https://homarr.dev)-Board (v2) per API aus allen laufenden Unraid-Containern:

- Apps für jeden Container mit WebUI (URL, Port und Icon aus dem Unraid-Template)
- Container-Gruppen: Schnellzugriff, Unraid, Medien, Arr, Downloads, PS5, Smart Home, Dokumente, Netzwerk, System
- Integrationen: Sonarr, Radarr, Prowlarr, Lidarr, Readarr, SABnzbd (Keys aus der Appdata), Jellyfin und Unraid (Key in `sync.env`)
- Status-Gruppe mit Downloads (SABnzbd) und Mediaserver (Jellyfin)
- Docker-Widget mit Start/Stop/Restart
- Board `Baum` als Startboard

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/cosmicflow2512/unraid-homepage/main/homarr/install-homarr.sh | bash
```

1. Unraid → Docker → Add Container → Template **homarr** → Apply.
2. `http://<SERVER-IP>:7575` öffnen, Admin-Konto anlegen.
3. Homarr → Verwaltung → Tools → API → API-Key erzeugen und in `/mnt/cache/appdata/homarr-sync/sync.env` bei `HOMARR_API_KEY=` eintragen.
4. `/mnt/cache/appdata/homarr-sync/sync.sh`

## Wichtig

- Jeder Lauf von `sync.sh` baut das Board **komplett neu auf**. Von Hand im Editor verschobene Kacheln gehen dabei verloren. Apps und Integrationen werden über Name bzw. Art+URL abgeglichen und nicht doppelt angelegt.
- Der Docker-Socket ist beschreibbar eingebunden (nötig für Start/Stop). Homarr nur im LAN betreiben, nicht über einen öffentlichen Tunnel.
- `SECRET_ENCRYPTION_KEY` liegt in `/mnt/cache/appdata/homarr/SECRET_ENCRYPTION_KEY.txt`. Ohne ihn sind gespeicherte Integrations-Zugangsdaten nicht mehr lesbar.
- Testlauf ohne Änderungen: `DRY_RUN=1` vor `sync.sh` setzen.

Gruppenzuordnung und Schnellzugriff wie bei der Homepage-Variante über `groups.override` und `quicklinks.txt` im Ordner `homarr-sync`.
