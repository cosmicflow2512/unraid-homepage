# unraid-homepage

[gethomepage](https://gethomepage.dev) als Startseite/Neuer-Tab-Dashboard auf Unraid. Die `services.yaml` wird automatisch aus den Unraid-Templates (`my-*.xml`) aller laufenden Container erzeugt, mit WebUI-URL, Host-Port, Icon und Container-Status.

## Was das Skript macht

- legt das Unraid-Template `my-homepage.xml` in `/boot/config/plugins/dockerMan/templates-user` ab
- schreibt `settings.yaml`, `widgets.yaml` (CPU/RAM, Google-Suche, Uhrzeit), `docker.yaml`, `bookmarks.yaml`
- erzeugt `services.yaml` aus allen laufenden Containern, gruppiert in Medien, Arr, Downloads, Smart Home, Dokumente, Netzwerk und System
- liest API-Keys für Sonarr, Radarr, Prowlarr, Lidarr, Readarr (`config.xml`) und SABnzbd (`sabnzbd.ini`) aus der Appdata und hängt die passenden Widgets an
- legt `refresh.sh` an, mit dem die `services.yaml` nach neuen Containern neu erzeugt wird (alte Version → `services.yaml.bak`)

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/cosmicflow2512/unraid-homepage/main/install-homepage.sh | bash
```

Danach im WebGUI: **Docker → Add Container → Template „homepage“ → Apply**.
Aufruf: `http://<SERVER-IP>:3010`

### Variablen (optional, vor `bash` setzen)

| Variable | Standard |
|---|---|
| `HOST_IP` | `192.168.0.20` |
| `HP_PORT` | `3010` |
| `HP_DIR` | `/mnt/cache/appdata/homepage` |
| `TPL_DIR` | `/boot/config/plugins/dockerMan/templates-user` |

Beispiel: `curl -fsSL …/install-homepage.sh | HOST_IP=192.168.1.10 HP_PORT=3020 bash`

## Nur das Template

```bash
curl -fsSL -o /boot/config/plugins/dockerMan/templates-user/my-homepage.xml \
  https://raw.githubusercontent.com/cosmicflow2512/unraid-homepage/main/templates/my-homepage.xml
```

## Neue Container übernehmen

```bash
/mnt/cache/appdata/homepage/refresh.sh
```

## Als Chrome-Neuer-Tab

Chrome kann den Neuer-Tab nicht selbst auf eine URL setzen. Eine Erweiterung wie [New Tab Redirect](https://github.com/jimschubert/newtab-redirect) oder New Tab Override übernimmt das; die Dashboard-URL dort eintragen.

## Hinweise

- Benötigt die PHP-CLI auf Unraid (`php -v`).
- Container ohne WebUI-Eintrag im Template werden übersprungen und am Ende aufgelistet.
- Container im Netzwerk eines anderen Containers (z. B. Gluetun) bekommen die Beschreibung „via <Container>“.
- Jellyfin-/Home-Assistant-Widgets brauchen einen manuell erzeugten Token und werden nicht automatisch angelegt.
- Die erzeugte `services.yaml` enthält API-Keys im Klartext. Sie liegt nur lokal in der Appdata, nicht im Repo.
