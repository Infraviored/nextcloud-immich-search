# Plan: Heikle Inhalte erkennen (optional)

Stand 2026-10-14, umgesetzt in 4.8.0. Abweichungen vom ersten Plan stehen am Ende. Ziel: Bilder mit Nacktheit (Brust, Genitalien, Po) erkennen und auf Wunsch
ausblenden, markieren oder allein anzeigen. Standardmäßig ist alles aus; wer es nicht einschaltet,
sieht Glimpse genau wie heute.

## Grundsätze

- **Doppelt optional.** Der Admin schaltet die Erkennung frei, jeder Nutzer schaltet sie für sich
  ein. Beides standardmäßig aus.
- **Nichts verschwindet ungefragt.** Auch eingeschaltet zeigt der Filter zunächst „Alle".
  Ausblenden ist eine bewusste Filterwahl.
- **Pro Person.** Jeder hat eigene Markierungen und einen eigenen Filter. Niemand sieht, was ein
  anderer markiert hat.
- **Kein neues Modell, keine neue Rechenlast.** Die Erkennung nutzt die CLIP-Vektoren, die Immich
  ohnehin für die Suche berechnet. Alles bleibt auf dem eigenen Server.
- **Die Hand gewinnt.** Eine Handmarkierung („heikel" / „nicht heikel") schlägt immer die
  Automatik.
- **Getrennt von „Privat".** Privat ist Sperre mit Passwort; das hier ist ein Filter ohne Sperre.

## Wie die Erkennung funktioniert

Immich speichert zu jedem Bild einen Vektor (`smart_search.embedding`, bei SigLIP2 1152 Zahlen).
Ein linearer Klassifikator ist dann genau ein weiterer Vektor `w` plus Schwelle `b`:
heikel, wenn `w · x > b`. Das rechnet Postgres direkt (`embedding <#> w`, pgvector),
ohne dass Vektoren die Datenbank verlassen. Gemessen: ein voller Durchlauf über 62.000 Bilder
dauert 0,5 s.

1. **Start ohne Beispiele.** `w` entsteht aus Text: Glimpse lässt Immichs ML-Dienst
   (`ml_ping_url`, die schon vorhandene Health-Check-Adresse, `/predict`) einige Begriffe
   kodieren, positiv („nude breasts", „exposed nipples", „genitals", „naked buttocks") und
   neutral („a person wearing clothes", „a landscape", „food", …), mit dem Modell, das in Immich
   gerade eingestellt ist. `w` = Mittel der positiven minus Mittel der neutralen.
2. **Empfindlichkeit.** Die Schwelle ist ein Regler in den persönlichen Einstellungen, mit
   Live-Anzeige „markiert jetzt N Bilder". So kalibriert jeder selbst, ohne dass jemand die
   Bilder ansieht.
3. **Lernen aus Korrekturen.** Jede Handmarkierung ist ein Beispiel. Ab etwa 20 Beispielen je
   Seite trainiert Glimpse eine logistische Regression auf den Vektoren dieser Bilder (wenige
   hundert Vektoren, in PHP in unter einer Sekunde), mit den Text-Vektoren als Startwert.
   Das Ergebnis ist wieder nur `w` und `b`.
4. **Videos.** Hat ein Video Standbilder (Suche in Videos), ist es heikel, wenn eines davon es ist.
5. **Neue Bilder.** Ein Hintergrundjob läuft mit dem Personen-Index (stündlich) und rechnet für
   jeden Nutzer mit eingeschalteter Funktion die Menge neu. Ändert sich das Modell in Immich,
   wird `w` aus den Texten und Beispielen neu berechnet (Vektoren verschiedener Modelle passen
   nicht zusammen).

Grenzen, ehrlich: CLIP erkennt Konzepte, keine Körperteile im Bild. Strandfotos, Kunst oder
Hautnahes landen mal drin, Ausschnitte rutschen mal durch. Deshalb Regler, Zeichen auf den
Kacheln und schnelle Korrektur. Reicht das nicht, wäre später ein Detektor wie NudeNet der nächste
Schritt (eigener Dienst, alle Bilder neu rechnen).

## Daten

- `sensitive/<uid>.json` in den App-Daten (wie `private/<uid>.json`): Handmarkierungen
  (fileId → ja/nein, dazu assetId), die erkannte Menge (fileIds), `w`, `b`, das Modell, für das
  `w` gilt, Zeitpunkt.
- Nutzer-Einstellungen (`UserPrefs`): `sensitive_enabled` (aus), `sensitive_mode`
  (`all`/`hide`/`only`, Standard `all`), `sensitive_badge` (an), `sensitive_threshold`.
- Admin-Einstellung (`Config::SETTINGS`): `sensitive_detection` (aus).
- Datenbank-Rolle: zusätzlich `GRANT SELECT ON smart_search TO nextcloud_moves;` (nur lesen).
  Vorhanden sind schon `asset` (id, originalPath) für die Zuordnung Bild ↔ Datei.

Voraussetzungen, die der Admin-Schalter prüft und anzeigt: Datenbankzugang konfiguriert,
`smart_search` lesbar, ML-Adresse gesetzt und erreichbar.

## Server (Backend)

- `Service/SensitiveContent.php`: Modell bauen (Text → `w`), trainieren, Menge berechnen (SQL über
  `MoveTracker`s Verbindung, ausgelagert in einen kleinen gemeinsamen `ImmichDb`), Zuordnung
  Asset → fileId des Nutzers über `AssetSearch` (nur für die markierten Assets), `isSensitive()`,
  `filter(items, mode)`.
- Jede Antwort mit Bildern (Suche, Zeitleiste, Ordner, Karte, Erinnerungen, Personenseite) bekommt
  bei eingeschalteter Funktion pro Bild `sensitive: true`, und je nach Modus wird gefiltert. Das
  passiert an derselben Stelle wie der Privat-Filter.
- **„Ohne heikle"** gilt überall, auch in der Nextcloud-Suchleiste, im Dashboard-Widget, bei „An
  diesem Tag" und bei Personen-Titelbildern.
- **„Nur heikle"**: Zeitleiste listet direkt aus der Menge (nach Datum, wie die Tag-Liste aus
  Nextcloud). Suche mit Text schränkt die Immich-Treffer auf die Menge ein.
- API: `POST /api/sensitive` (fileIds, ja/nein, mehrere auf einmal), `GET /api/sensitive/preview?threshold=`
  (Anzahl für den Regler), `POST /api/sensitive/rebuild`. Rechte wie bei den anderen Aktionen:
  nur eigene sichtbare Dateien.
- Admin: Schalter, Prüfung der Voraussetzungen, `occ glimpse:sensitive --status|--rebuild <uid>`.
- Tests: PHPUnit für Filter, Modus-Logik, Training (synthetische Vektoren), Handmarkierung schlägt
  Automatik, Rechte.

## Weboberfläche

- **Einstellungen → Anzeige:** „Heikle Bilder markieren" (aus). Eingeschaltet erscheinen darunter:
  Empfindlichkeit (Regler mit „markiert jetzt N Bilder"), „Zeichen auf Kacheln" (an), Link
  „Erkannte Bilder durchsehen" (öffnet „Nur heikle").
- **Filtermenü** (Suche und Zeitleiste, neben Person/Videos/Tag): „Alle / Ohne heikle / Nur heikle".
  Ein anderer Wert als „Alle" zeigt sich als Etikett, das man wegklicken kann. Der Modus bleibt
  gespeichert.
- **Kachel:** kleines Zeichen in der Ecke (Material-Icon, dezent, auch auf verschwommenen
  Vorschaubildern erkennbar).
- **Viewer-Leiste:** Knopf „heikel / nicht heikel", ein Klick, Toast mit Rückgängig. Im Modus
  „Ohne heikle" verschwindet das Bild danach aus der Liste.
- **Auswahlleiste:** im Mehr-Menü „Als heikel markieren" / „Als nicht heikel markieren".
- Ist die Funktion aus (Admin oder Nutzer): nichts davon wird angezeigt.

## Android-App

- Einstellungen und Modus kommen vom Server (`prefs`), gelten also sofort mit.
- Filter-Chip im Filterblatt, Zeichen auf Kacheln, Knopf im Viewer, Mehrfachauswahl.
- Eigener PR im App-Repo nach dem Server.

## Prüfen, ohne private Bilder anzusehen

- Funktionstests mit dem Demo-Konto: Markieren per Hand, Modi, Zeichen, Rückgängig, Rechte
  (ein Nutzer sieht die Markierungen des anderen nicht).
- Erkennung: nur Zahlen. Wie viele Bilder bei welcher Schwelle, wie viele Handmarkierungen, wie
  sich die Menge nach dem Training ändert. Keine Bilder aus echten Konten öffnen oder beschreiben;
  Screenshots echter Konten nur verschwommen.
- Kalibrierung des Startwerts für den Regler: Demo-Konto (keine Nacktheit) muss bei Standard
  nahezu 0 ergeben.

## Reihenfolge

1. Backend: `ImmichDb`, `SensitiveContent`, Prefs, Admin-Schalter, API, Filter in allen Antworten,
   Tests.
2. Web: Einstellungen, Filtermenü, Zeichen, Viewer-Knopf, Auswahlleiste.
3. README (Abschnitt „Heikle Inhalte", Grant), SECURITY.md, CHANGELOG, Übersetzungen de/de_DE.
4. PR, Conductor-Review, Fixes, Deploy (eine Versionserhöhung = kurz Wartungsmodus).
5. Android-App: eigener PR, Review, APK.

## Umgesetzt, Abweichungen

- **Modus-Menü in der App-Leiste statt im Filtermenü der Suche.** Der Modus gilt für alle
  Ansichten (Zeitleiste, Karte, Ordner, Personen), die Zeitleiste hat keine Filterzeile. Es sitzt
  neben dem Schloss von „Privat"; ist ein Filter aktiv, steht sein Name auf dem Knopf.
- **Immer trainiert, nicht erst ab 20 Korrekturen.** Erste Vermutungen (Abstand zu heiklen gegen
  neutrale Begriffe) sind die Trainingsbeispiele, Handmarkierungen zählen fünffach. Reine
  Text-Ähnlichkeit hatte im Demo-Konto Rosen und Raketen erwischt (43 von 294); die logistische
  Regression auf den Vermutungen ergibt dort 0.
- **„naked genitals" gestrichen**: zog bei SigLIP2 vor allem Blumen und Strände an.
- **Markieren entfernt nicht sofort aus der Liste.** Zum Durchsehen ruhiger; die Kachel ändert ihr
  Zeichen, beim nächsten Laden ist das Bild weg. Rückgängig über den Toast.
- Der ML-Dienst brauchte bei uns Zugang zum Nextcloud-Netz (Immich-Compose, `networks`).
