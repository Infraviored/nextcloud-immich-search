# Fotosuche (Nextcloud-App `immich_search`)

Sucht in den Nextcloud-Fotos nach Bildinhalt ("Frau am Strand", "Hund im Schnee") und nach
Personen. Die Bildanalyse macht Immich (CLIP-Modell und Gesichtserkennung); diese App ist nur
die Oberfläche in Nextcloud. Eigenbau für flo-server, ersetzt die App `integration_immich`.

## Wo die Suche auftaucht

| Ort | Wie |
|---|---|
| Globale Suchleiste (Lupe oben, jede Seite) | Abschnitt „Fotos nach Inhalt", ab 3 Zeichen |
| Nextcloud-Android-App | Dieselbe Suche, die Treffer öffnen die Datei in der App |
| Eigene Seite „Fotosuche" im Menü | Raster mit Nachladen, Filter nach Person und Foto/Video |

Ein Treffer ist immer die **echte Nextcloud-Datei**: Klick öffnet sie im Nextcloud-Viewer
(Videos spielen dort ab), das Ordner-Symbol springt in den Ordner.

## Wie es zusammenhängt

```
Browser / Android ── Nextcloud ── immich_search ──HTTP──▶ Immich (192.168.178.2:2283)
                                     │                        │
                                     └── Dateipfad ◀──────────┘ originalPath
```

Immich liest die Nextcloud-Dateien schreibgeschützt als externe Bibliothek unter
`/mnt/nextcloud/<benutzer>/` (`~/docker-projects/immich/docker-compose.yml`). Daraus wird
der Nextcloud-Pfad (`/mnt/nextcloud/florian/Fotos/a.jpg` → `/Fotos/a.jpg`).
Bilder und Vorschauen holt Nextcloud serverseitig, darum geht alles auch von unterwegs,
obwohl Immich nur im Heimnetz lauscht.

## Einrichten

Pro Benutzer: Persönliche Einstellungen → Weitere Einstellungen → „Fotosuche (Immich)",
oder per occ (Schlüssel über stdin, taucht so nirgends auf):

```bash
docker exec -i -u www-data nextcloud-aio-nextcloud php occ immich_search:configure florian \
    --url http://192.168.178.2:2283 --key-stdin < ~/docker-projects/immich/api-key
docker exec -u www-data nextcloud-aio-nextcloud php occ immich_search:configure \
    --default-url http://192.168.178.2:2283     # Standard für alle ohne eigene Adresse
```

Der API-Schlüssel liegt verschlüsselt in den Benutzereinstellungen.

## Ändern und ausrollen

Kein Build-Schritt: PHP und reines JavaScript.

```bash
./deploy.sh      # prüft PHP-Syntax, kopiert in den Container, aktiviert/aktualisiert
```

Bei Änderungen an `appinfo/info.xml` die Version erhöhen.

| Datei | Aufgabe |
|---|---|
| `lib/Service/AssetSearch.php` | Suche bei Immich, Abbildung auf Nextcloud-Dateien |
| `lib/Service/ImmichClient.php` | HTTP zu Immich, Fehler als `ImmichException` |
| `lib/Service/Config.php` | Adresse und (verschlüsselter) Schlüssel pro Benutzer |
| `lib/Search/ImmichProvider.php` | Globale Suche / Android |
| `lib/Controller/ApiController.php` | JSON-API und Vorschaubilder für die eigene Seite |
| `js/search.js`, `css/search.css` | Seite „Fotosuche" |

## Grenzen

- Ist der ML-Container von Immich aus (z. B. von `gpu-guard` gestoppt, weil VibeVoice den
  Grafikspeicher braucht), liefert die Suche nichts. Die globale Suche zeigt dann einfach
  keinen Abschnitt, die Seite eine Fehlermeldung.
- Personen erscheinen im Filter erst, wenn sie in Immich einen Namen haben.
- Dateien, die seit dem letzten Immich-Scan verschoben wurden, öffnen nur als Vorschau.
