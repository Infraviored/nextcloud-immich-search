# Plan: Fotosuche (`immich_search`)

Stand 2026-10-04, Version 2.2.0. Ziel: aus der Fotosuche eine vollständige, eigenständige
Foto-App für Nextcloud machen – Suche nach Inhalt, Personen, Sortieren, Zeitleiste – mit
Immich als unsichtbarer Analyse-Maschine dahinter. Wiederverwendbar für jede Nextcloud, die
ein Immich daneben hat, perspektivisch im Nextcloud App Store.

Leitlinien, die für jede Phase gelten:

- **Nextcloud entscheidet die Rechte.** Kein Treffer, kein Vorschaubild, kein Gesicht, das der
  Benutzer nicht auch in Dateien öffnen könnte. Jede neue Funktion geht durch `AssetSearch::visibleItem`.
- **Die echte Datei ist das Objekt.** Aktionen (verschieben, taggen, löschen) laufen über
  Nextcloud, nie über Immich. Immich ist schreibgeschützt eingebunden.
- **Eine App, ein Einstieg.** Alles lebt unter `immich_search`; die globale Nextcloud-Suche und
  die Datei-Seitenleiste sind nur Türen hinein.
- **Handy zuerst mitdenken.** Jede Ansicht wird auf 390 px Breite geprüft (Playwright, Bilder
  unscharf geschaltet).
- **Messen statt schätzen.** Laufzeiten von Hintergrundjobs werden geloggt und in der
  Status-Seite angezeigt.

---

## Phase 0 – Projekt-Grundlagen

| # | Aufgabe | Ergebnis |
|---|---|---|
| 0.1 | Privates GitHub-Repo, Branch `main` | erledigt mit diesem Commit |
| 0.2 | GitHub Actions: `make lint`, `npm run build`, PHP-Syntax gegen 8.1–8.4, ESLint (`@nextcloud/eslint-config`), Stylelint | grüner Haken pro Push |
| 0.3 | Release-Workflow: Tag `vX.Y.Z` → `make dist` → Tarball als Release-Asset | installierbares Paket ohne lokale Toolchain |
| 0.4 | `CHANGELOG.md` (Keep a Changelog), Versionsnummer nur in `appinfo/info.xml` | |
| 0.5 | Tests: PHPUnit für `AssetSearch` (Pfad-Mapping, Rechtefilter, Größenfilter), `PeopleIndex`, `FileTransfer`; Vitest für Router und Auswahl-Logik; Playwright-E2E (Desktop + Handy, Bilder geblurrt) | Fehler in der Rechteprüfung fallen vor dem Ausrollen auf |
| 0.6 | `deploy.sh` als einziger Weg auf flo-server, Version wird automatisch hochgezählt, wenn sich `js/` ändert (Browser-Cache) | kein „alte Ansicht nach Update" mehr |

## Phase 1 – Einstellungsseite in der App

Neuer Reiter **Einstellungen** (`/apps/immich_search/settings`) statt des Dialogs. Admins
sehen alles, Benutzer nur „Darstellung" und „Status". Die Admin-Sektion in den
Nextcloud-Einstellungen verlinkt nur noch hierher.

### 1.1 Status (alle)

- Indexierung: Fotos gesamt / mit Vorschau / mit Inhaltsanalyse / mit Gesichtern, als Balken.
- Warteschlangen von Immich (aktiv, wartend, Fehler) – live, alle 10 s.
- Analyse-Dienst: läuft / pausiert (gpu-guard) / aus, seit wann.
- Letzter Bibliotheks-Scan, letzter Personen-Index (Dauer, Anzahl).
- Knöpfe (Admin): „Fehlendes nachreihen", „Bibliotheken jetzt scannen", „Personen jetzt aktualisieren".
- Ersetzt auf Dauer den Server-Watcher `immich-index-watcher` – als Nextcloud-Hintergrundjob,
  damit es auf jeder Installation funktioniert (Watcher bleibt nur für den ML-Neustart).

### 1.2 Was indexiert wird (Admin)

- **Formate:** Liste mit Häkchen (SVG, ICO, GIF, PSD, RAW, HEIC, Videos …). Die App schreibt
  daraus die Ausschlussmuster aller Immich-Bibliotheken und stößt einen Scan an.
- **Ordner ausschließen:** Muster-Liste plus Vorschlagsliste „verdächtige Ordner" (viele
  winzige Bilder, Namen wie `icons`, `node_modules`, `scalable`, `.cache`) zum Abhaken.
- **Mindestgröße:** wie heute, plus Phase 2 (Ausschluss schon bei Immich).
- **Bibliotheken:** pro Nextcloud-Benutzer: angebunden ja/nein, Anzahl Fotos, letzter Scan.
  Für neue Benutzer: fertiger Compose-Schnipsel zum Kopieren und Knopf „Bibliothek anlegen".

### 1.3 Nextcloud-Suche (Admin, Standard für alle; Benutzer darf abschalten)

- [x] **Fotos nach Inhalt** in der globalen Suche (heute fest an)
- [ ] **Personen** in der globalen Suche: „Tara" → Eintrag mit Gesicht → Personenseite (Phase 3)
- [x] Eintrag **„Alle Treffer in der Fotosuche öffnen"** an erster Stelle (heute fest an) –
      ein-/ausschaltbar, Text anpassbar
- Mindestlänge des Suchbegriffs (heute 3), Anzahl Treffer je Abschnitt, Position des Abschnitts
  (direkt unter Dateien oder weiter unten)
- Nur in bestimmten Apps zeigen (Dateien, Fotos) oder überall

### 1.4 Personen und Rechte (Admin)

Wer darf benennen / zusammenführen / ausblenden (heute: alle, Admins, niemand), wie oft der
Personen-Index läuft, ab wie vielen Bildern eine unbenannte Person gezeigt wird.

### 1.5 Darstellung (Benutzer)

Kachelgröße, Startansicht (Suche / Zeitleiste / Personen), Videos in Treffern ja/nein,
Sortierung (Relevanz / Datum), Dichte der Personenliste.

## Phase 2 – Mini-Bilder gar nicht erst analysieren

Immich kann nur nach Pfad ausschließen, nicht nach Bildgröße. Drei Wege, in dieser Reihenfolge prüfen:

| Weg | Idee | Risiko |
|---|---|---|
| **A. Aufräumen nach dem Scan** | Hintergrundjob fragt Immich nach Bildern unter der Mindestgröße, die noch keine Analyse haben, und nimmt sie aus der Bibliothek (Papierkorb in Immich, Datei bleibt) | **Erst testen:** importiert der nächste Scan sie wieder? Wenn ja → B |
| **B. Exakte Pfade ausschließen** | Die App pflegt die Ausschlussliste mit den Pfaden der Mini-Bilder | lange Musterlisten, Scan wird langsamer |
| **C. Kuratierte Sicht** | Immich liest nicht die Nextcloud-Daten direkt, sondern einen Ordner mit Verknüpfungen nur auf geeignete Dateien, den die App pflegt | sauberste Lösung, aber Server-Umbau; nur wenn A und B scheitern |

Abnahme: Ein neues 64×64-PNG in Nextcloud erzeugt keinen Analyse-Job mehr (prüfbar in der Status-Seite).

## Phase 3 – Nextcloud-Suche erweitern

- **Personen-Provider:** sucht in Personennamen, Ergebnis mit Gesicht und Bildanzahl, nur
  sichtbare Personen. Link auf die Personenseite.
- **Kombinierte Anfrage:** „Tara Strand" → erkennt den Personennamen, sucht „Strand" innerhalb
  ihrer Bilder.
- **„Mehr suchen"-Eintrag** konfigurierbar (1.3); auf dem Handy öffnet er die App im Browser
  (die Android-App kann keine fremden App-Seiten nativ anzeigen).

## Phase 4 – Mehr aus der Auswahl machen

- **Ähnliche Bilder:** an jedem Treffer „mehr wie dieses" (Immich-Suche per Bild statt Text).
- **Massenaktionen:** Nextcloud-Tags setzen (dann auch in Dateien/Android suchbar),
  favorisieren, löschen (Papierkorb), als ZIP herunterladen, teilen, zu Album hinzufügen
  (Nextcloud-Photos-Alben).
- **Verschieben-Komfort:** zuletzt benutzte Zielordner, neuen Ordner direkt im Dialog anlegen,
  Rückgängig für die letzte Verschiebung.

## Phase 5 – Foto-App wie Memories

- **Zeitleiste:** alle sichtbaren Fotos nach Datum, Jahres-/Monats-Scrollleiste, Auswahl wie in der Suche.
- **Karte:** Fotos mit GPS-Daten auf einer Karte (Leaflet + OSM-Kacheln über Nextcloud-Proxy), Auswahl per Rechteck.
- **An diesem Tag:** Rückblick oben in der Zeitleiste.
- **Duplikate:** Immich erkennt sie schon; Ansicht nebeneinander, besseres behalten, Rest in den Papierkorb.
- **Ordner-Ansicht:** Fotos eines Ordners mit Unterordnern, als Galerie.

## Phase 6 – Gesichter genauer

- Alle Gesichter eines Bildes mit Rahmen anzeigen (Overlay im Viewer oder in der App).
- Einzelnes Gesicht einer anderen Person zuweisen oder „ist niemand" (Fehlerkennung).
- Vorschläge: „Ist das Tara?" für ähnliche unbenannte Gesichter, mit Ja/Nein in Serie.
- Personen-Geburtstag → Alter auf Fotos; Lieblingspersonen oben.
- Personen-Index inkrementell (nur geänderte Personen/Freigaben), damit er auch bei
  100.000 Fotos stündlich läuft.

## Phase 7 – Privat-Bereich

- Personen oder Ordner als **privat** markieren (pro Benutzer). Private Treffer erscheinen nur,
  wenn oben „Privat zeigen" an ist; nie in der globalen Nextcloud-Suche.
- Optional mit erneuter Passwort-Abfrage beim Einschalten.
- Sortier-Hilfe dafür: Massenauswahl → „Als privat markieren" / „In privaten Ordner verschieben".

## Phase 8 – In Nextcloud eingebettet

- **Reiter in der Datei-Seitenleiste:** bei jedem Foto „Personen auf diesem Bild" (antippbar)
  und „Ähnliche Bilder".
- **Aktion im Bildbetrachter:** „Ähnliche suchen", „Personen".
- **Dashboard-Widget:** „An diesem Tag" und Indexierungs-Status.

## Phase 9 – Veröffentlichen

- Name und Icon festlegen (heute „Photo search"/„Fotosuche"), Screenshots, App-Store-Text.
- App-Signatur (Nextcloud-Zertifikat), Veröffentlichung im App Store.
- Sicherheits-Review: Rechteprüfung, Rate-Limits auf den Bild-Endpunkten, keine Immich-IDs ohne Rechtecheck.
- Dokumentation: Einrichtung mit Docker-Compose-Beispiel für Nextcloud AIO und klassische Installationen.

---

## Reihenfolge und Umfang

| Schritt | Phasen | grob |
|---|---|---|
| 1 | 0.2–0.6, 1.1, 1.2 (ohne Ordner-Vorschläge), 1.3 | Fundament + das, was gerade fehlt |
| 2 | 2 (Test A, dann umsetzen), 3 | Index sauber, Suche überall |
| 3 | 4 | Sortieren macht Spaß |
| 4 | 5 | echte Foto-App |
| 5 | 6, 7 | Gesichter und Privates |
| 6 | 8, 9 | Integration und Release |

## Offene Fragen

1. Name der App für den Store – bleibt es „Fotosuche/Photo search"?
2. Sollen **Photos** (Nextcloud) und **Memories** abgeschaltet werden, sobald Zeitleiste und Karte da sind?
3. Privat-Bereich: reicht ein Schalter, oder Passwort-Abfrage?
4. Soll die App irgendwann ohne Immich auskommen (eigene Analyse) – oder bleibt Immich gesetzt? (Empfehlung: bleibt.)
