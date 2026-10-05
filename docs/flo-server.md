# Betrieb auf flo-server

Wie die App hier eingebunden ist. Das Allgemeine steht im README.

| Was | Wo |
|---|---|
| Immich | `~/docker-projects/immich/` (Compose, `api-key`, `admin-login.txt`), Web-UI `http://192.168.178.2:2283` (nur LAN) |
| Mounts | `/mnt/nas/nextcloud/<uid>/files` → `/mnt/nextcloud/<uid>:ro`, derzeit `florian` und `tara` |
| Bibliotheken | „Nextcloud florian", „Nextcloud tara", beide gehören Flos Immich-Konto (= Dienstkonto) |
| Nextcloud | AIO, Container `nextcloud-aio-nextcloud`, App unter `custom_apps/glimpse` |
| Ausrollen | `make deploy` (erhöht die Version selbst, wenn sich etwas geändert hat) |

## Verschieben ohne Neu-Analyse

Immich kennt Dateien externer Bibliotheken nur am Pfad. Damit verschobene oder umbenannte
Bilder nicht neu analysiert werden, trägt die App jeden Umzug direkt in Immichs Datenbank ein
(`lib/Service/MoveTracker.php`).

| Was | Wo |
|---|---|
| Netz | `immich_postgres` hängt zusätzlich im Netz `nextcloud-aio` (Compose, `networks:`) |
| Datenbank-Rolle | `nextcloud_moves`: darf nur `id`, `originalPath`, `libraryId`, `checksumAlgorithm` von `asset` lesen und `originalPath`, `originalFileName`, `checksum` ändern |
| Passwort | `~/docker-projects/immich/nextcloud-db-password` (600), in Nextcloud verschlüsselt |
| Einrichten | `occ glimpse:configure --db-host immich_postgres --db-user nextcloud_moves --db-password-stdin < nextcloud-db-password` |

Fällt die Verbindung aus, klappt das Verschieben trotzdem; nur analysiert Immich das Bild
dann beim nächsten Scan neu (Warnung im Nextcloud-Log). Nicht abgedeckt: Änderungen direkt
auf der Platte und Verschieben zwischen Benutzern (das ist in Nextcloud eine Kopie).

## Neuen Benutzer aufnehmen

1. Mount in `~/docker-projects/immich/docker-compose.yml` ergänzen, `docker compose up -d immich-server`
2. In Immich (als Flo) externe Bibliothek mit Importpfad `/mnt/nextcloud/<uid>` anlegen, scannen

Mehr nicht: die App braucht keine Konfiguration pro Benutzer.

## Hintergrundjobs der App

| Job | Takt | Zweck |
|---|---|---|
| Personen- und Karten-Index | stündlich, übersprungen wenn nichts geändert | wer welche Personen/Marker sieht |
| Mini-Bilder | alle 15 min | neue Bilder unter 256 px aus Immich nehmen |

Laufen über den Nextcloud-Cron der AIO. Von Hand: `occ glimpse:people-index`,
`occ glimpse:prune --full`. Status: in der App unter Einstellungen → Status.

PHP-Änderungen werden bis zu 60 s gecacht (`opcache.revalidate_freq`): nach `make deploy`
kurz warten, bevor man testet.

## GPU und Indexierung

- Immich-ML teilt sich die GPU mit VibeVoice. `gpu-guard` (`/usr/local/sbin/gpu-guard.sh`)
  stoppt den ML-Container, wenn weniger als 1,5 GB VRAM frei sind.
- `immich-index-watcher.timer` (alle 10 min, `/usr/local/sbin/immich-index-watcher.sh`) startet
  ML wieder, sobald 8 GB frei sind, und reiht fehlende Analysen nach, wenn Immichs
  Warteschlange leer ist. Grund: Die Warteschlange liegt nur im Redis-Speicher, und am
  2026-10-04 blieb die Hälfte der Bilder ohne Analyse, weil sie beim Nachreihen noch keine
  Vorschau hatten.
- Neue Fotos holt Immich per nächtlichem Bibliotheks-Scan (00:00) und analysiert sie danach
  von selbst.

```bash
journalctl -t immich-index-watcher -n 20     # was der Waechter getan hat
```
