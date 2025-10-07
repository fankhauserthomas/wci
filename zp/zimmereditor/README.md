# Zimmereditor `index.php` – Technische Dokumentation

## 1. Zweck und Funktionsumfang
Der Zimmereditor stellt eine kombinierte PHP-/JavaScript-Anwendung bereit, mit der Schlafraum-Layouts verwaltet werden können. Die Datei `index.php` liefert sowohl eine JSON-basierte API (für CRUD-Operationen auf Zimmern und Konfigurations-Tabellen) als auch das komplette Frontend (HTML, CSS, JavaScript), das die Bedienoberfläche im Browser rendert. Zu den Kernfunktionen gehören:

- Anzeigen und Bearbeiten einer tabellarischen Zimmerliste.
- Farbgebung, Sichtbarkeitssteuerung und Positionsverwaltung (px/py) pro Zimmer.
- Drag-and-Drop-Sortierung in der Liste und auf einer Canvas-Layout-Vorschau.
- Verwaltung mehrerer Konfigurationstabellen (Anlegen, Überschreiben, Laden, Löschen).
- Responsive UI mit Bootstrap, optimiert für Desktop und mobile Geräte.

## 2. Gesamtarchitektur

### 2.1 Server-Seite (PHP)
- Lädt Konfigurationsdaten über sichere Pfadvarianten (`config.php`, `config-safe.php`).
- Baut eine MySQLi-Verbindung auf und erzwingt UTF-8 (`utf8mb4`).
- Definiert Utility-Funktionen zur Serialisierung, Normalisierung und Validierung von Eingaben.
- Implementiert eine JSON-API mit den Aktionen:
  - `list`: liefert alle Zimmer und bekannte Kategorien.
  - `save`: persistiert neue/aktualisierte/gelöschte Zimmer.
  - `config_list`: listet alle Layout-Konfigurationen (Tabellen) auf.
  - `config_create`: erstellt oder überschreibt Konfigurationstabellen.
  - `config_load`: kopiert eine gespeicherte Konfiguration in die Arbeitskopie.
  - `config_delete`: entfernt eine gespeicherte Konfiguration.
- Kapselt gemeinsame Pfad-Berechnungen in Funktionen (`zimmer_config_table_from_key`, `zimmer_config_normalize_key`, etc.) und nutzt Transaktionen bei kritischen Änderungen.

### 2.2 Client-Seite (HTML/CSS/JS)
- HTML-Struktur umfasst eine Navigationsleiste, eine tabellarische Zimmerliste (`#gridCard`) und eine Vorschaukarte (`#previewCard`) mit Canvas.
- Eigenes CSS (im `<style>`-Block) passt Bootstrap-Theming, Tische, Buttons, Modals und Farb-Picker an.
- JavaScript läuft in einem IIFE, initialisiert App-State, bindet DOM-Elemente, kümmert sich um Netzwerkzugriffe und UI-Interaktionen.
- Bootstrap 5.3 Bundle (CDN) stellt Modalkomponenten bereit.

## 3. Server-seitige Komponenten im Detail

### 3.1 Bootstrap & Konfigurations-Layer
- Sucht nacheinander nach `../config.php`, `../../config.php`, `../config-safe.php`.
- Bricht mit HTTP 500 ab, wenn keine Konfiguration gefunden wird oder wenn die DB-Verbindung scheitert.
- Verwendet wahlweise bereits erzeugtes `$mysqli` oder erstellt es aus Konstanten (`DB_HOST`, ...).

### 3.2 Utility-Funktionen
- `json_response($data, $code)`: Serialisiert beliebige Arrays/Objekte als UTF-8 JSON, setzt Status-Code und beendet das Skript.
- `sanitize_room($row)`: Normalisiert Datenbankzeilen zu bekannten Zahlen-/String-Feldern.
- `isReadOnlyCaption($caption)`: Hilft, die Sonderzeile "Ablage" vor Änderungen zu schützen.
- `zimmer_config_*`-Funktionen: Slug-Erzeugung, Tabellenzuordnung, Existenzprüfungen, Row-Counting, etc.

### 3.3 API-Aktionen
| Aktion | HTTP-Methode | Eingabe | Ausgabe | Hinweise |
|--------|--------------|---------|---------|----------|
| `list` | GET | – | `{ success, data: [...], categories: [...] }` | Sortiert nach `sort`, liefert Sichtbarkeit, Farbe (ARGB) und Position (px/py). |
| `save` | POST JSON | `{ newRows, updatedRows, deletedIds }` | `{ success, inserted, updated, deleted }` | Führt Insert/Update/Delete auf `zp_zimmer` aus, validiert IDs, ignoriert "Ablage" bei Delete. |
| `config_list` | GET | – | `{ success, configs: [...] }` | Listet Arbeitskopie plus alle Tabellen `zp_zimmer_*`, markiert Arbeitskopie als `protected`. |
| `config_create` | POST JSON | `{ name, source?, overwrite? }` | `{ success, created?, configs? }` | Erzeugt neue Tabelle per `CREATE TABLE LIKE` und `INSERT … SELECT`. Optional `overwrite` true => Drop + Create. Beschränkt Tabellennamen auf `[a-z0-9_]`. |
| `config_load` | POST JSON | `{ name }` | `{ success, loaded }` | Kopiert ausgewählte Konfiguration in Arbeitskopie (`TRUNCATE + INSERT`). |
| `config_delete` | POST JSON | `{ name }` | `{ success, deleted, configs }` | Droppt Ziel-Tabelle, verbietet Arbeitskopie. |

Alle Aktionen nutzen `zimmer_config_normalize_key`, validieren Tabellennamen via Regex und umgeben kritische Bereiche mit DB-Transaktionen.

### 3.4 Datenstrukturen
- **Zimmer (`zp_zimmer`)**: `id`, `caption`, `etage`, `kapazitaet`, `kategorie`, `col` (ARGB Hex), `px`, `py`, `visible`, `sort`.
- **Konfigurationstabellen**: identischer Aufbau wie Arbeitskopie (`CREATE TABLE LIKE`).

## 4. Frontend-Struktur

### 4.1 DOM & Layout
- Navbar mit Aktionen: Neu, Anwenden, Filter, Konfigurationsauswahl, "Speichern als…", "Löschen".
- Zwei Karten im Grid (`col-lg-7`/`col-lg-5`):
  - `#gridCard`: enthält Tabelle + Drag-Indikator.
  - `#previewCard`: Canvas für Layout-Vorschau.
- Weitere Hilfselemente: Statusanzeige (`#status`), Konfigurationshinweis (`#configInfo`).

### 4.2 Styling-Höhepunkte
- Farbvariablen über CSS-Custom-Properties, Light/Dark-Balancing innerhalb der Karten.
- Tabellen-Scrollbereich (`.table-wrap`) mit alternierenden Zeilenfarben, Drag-Handles und Buttons.
- Farb-Picker als fest positioniertes Grid (`.color-palette`).
- Vorschaukarte jetzt einheitlich hell (weißes `card-body`).

## 5. JavaScript-Core

### 5.1 State & Element-Referenzen
- `state`: hält Rohdaten (`rows`), gefilterte Ansicht, Auswahl, Dirty-Tracking (`inserted`, `updated`, `deleted`), Canvas-Geometrie, Konfigurationsstatus.
- `els`: DOM-Elemente (Tabelle, Buttons, Canvas, Konfigurationsselect, etc.).
- `normalizeConfigKey(name)`: JS-Pendant zur PHP-Slug-Logik.

### 5.2 API-Wrapper
- `API.list/save/configList/configCreate/configLoad/configDelete`: nutzen `fetch` mit JSON, hängen `credentials: 'same-origin'` und No-Cache-Header an.
- Fehlerbehandlung: wirft `Error`, wenn HTTP-Status oder `success`-Flag scheitern.

### 5.3 Rendering & Interaktion
- `renderTable()`: aktualisiert die `<tbody>` anhand von `state.filtered`.
- `renderRow(r)`: erzeugt pro Zimmer Zeile inkl. Edit felder, Buttons, Drag-Handle, Sichtbarkeits-Toggle, Delete-Button.
- `applyFilter()`: filtert nach Text (Bezeichnung/Kategorie).
- `syncPreviewHeight()`: koppelt Canvas-Höhe an Tabellenbereich, berücksichtigt `devicePixelRatio`.
- `drawPreview()`: zeichnet ein A4-artiges Grid, gruppiert Zimmer nach `(px, py)`, setzt responsive Schriftgrößen (mobile-freundlich).
- `drawGhost()`: zeigt beim Canvas-Drag eine halbtransparente Vorschau; skaliert Schrift analog zu `drawPreview`.
- `redrawCanvas()`: orchestriert Preview- und Ghost-Rendering.

### 5.4 Drag & Drop (Tabelle)
- `startRowDrag`/`onRowDragMove`/`onRowDragEnd`: ermöglichen Sortierung innerhalb der gefilterten Ansicht, inklusive Auto-Scroll.
- `updateDragIndicator()`: zeigt Drop-Position in der Tabelle an.
- `reorderWithinFiltered()`: wendet Reihenfolge auf `state.rows` an, aktualisiert `sort`-Feld und Dirty-Set.

### 5.5 Drag & Drop (Canvas)
- `onCanvasStart/move/end`: erlauben, Zimmer im Layout zu verschieben bzw. neue Spalten/Zeilen anzulegen, wenn der Drag an den Rand gezogen wird.
- `hitTest()`: prüft, welches Zimmerrect angeklickt wurde.
- Ergebnisse werden direkt in den Zimmerdaten (px/py) und Dirty-Set reflektiert.

### 5.6 Datenmanipulation
- `addRow()`: erzeugt Platzhalterzimmer (`Neues Zimmer`).
- `markUpdated(row, patch)`: pflegt Dirty-Tracking, synchronisiert Canvas.
- `sanitize(r)`: konvertiert Werte vor dem Speichern und erzwingt konsistente ARGB-Hexfarben.
- `removeRoom()` / `removeSelected()`: löschen Zimmer (mit Schutz für "Ablage").

### 5.7 Konfigurationsverwaltung
- `refreshConfigs()`: lädt Liste, hält aktuelle Auswahl, aktualisiert Select-Options.
- `updateConfigSelect()`, `updateConfigControls()`, `updateConfigInfo()`: UI-State.
- `onConfigSelectChange()`: Speichert laufende Änderungen, persisted aktuelle Konfiguration (falls nötig) und lädt Zielkonfiguration.
- `switchToConfig()`: orchestriert Laden/Kopieren von Tabellen.
- `persistCurrentConfig()`: schreibt Arbeitskopie als Backup in geladene Konfiguration (Overwrite).
- `handleConfigSaveAs()`: nutzt Modal zur Auswahl zwischen neuer Konfiguration oder Überschreiben bestehender (geschützte Konfigurationen sind ausgeschlossen).
- `handleConfigDelete()`: entfernt Konfigurationstabellen (außer Arbeitskopie).

### 5.8 Initialisierung & Events
- Bindet UI-Events (Filter, Buttons, Select, Window-Resize, Canvas).
- `bootstrap()`: ruft `refreshConfigs()` und `load()` asynchron auf.
- Debug-Hook: `window.__zimmerState` liefert aktuellen State im Browser.

## 6. Modal-Helfer (Bootstrap)
- `ModalHelper` kapselt vier wiederverwendbare Modals:
  - `alert(message)` – Hinweis.
  - `confirm(message)` – Bestätigung mit OK/Abbrechen.
  - `prompt(message, defaultValue)` – Text-Eingabe.
  - `configSaveAs(options)` – Spezialmodal für Konfigurationsspeicherung mit Radiobuttons & Dropdown.
- Jeder Modal-Creator legt HTML dynamisch an und initialisiert `bootstrap.Modal`-Instanzen.
- Handhabt Fokusmanagement, Enter-Taste, Befüllung der Dropdown-Optionen und Validierung.

## 7. Datenfluss-Zusammenfassung
1. **Initial Load**: `bootstrap()` ⇒ `refreshConfigs` ⇒ `load` (→ `API.list`). UI wird gerendert, Canvas angepasst.
2. **Bearbeitung**: Nutzer ändert Werte ⇒ `markUpdated` setzt Dirty-Flags, Canvas aktualisiert.
3. **Speichern**: Button "Anwenden" ⇒ `save()` ⇒ `API.save` ⇒ `load()` (Neuaufbau State) ⇒ `refreshConfigs` ⇒ Statusmeldung.
4. **Konfigurationswechsel**: Select-Change ⇒ `onConfigSelectChange()` ⇒ `save()` & `persistCurrentConfig()` ⇒ `switchToConfig()` (API-Aufruf + Reload).
5. **Speichern als…**: `handleConfigSaveAs()` ⇒ `ModalHelper.configSaveAs()` ⇒ `API.configCreate` mit `overwrite` oder `name` ⇒ `refreshConfigs`.
6. **Löschen**: `handleConfigDelete()` ⇒ `API.configDelete` ⇒ UI aktualisiert.

## 8. Erweiterungsmöglichkeiten & Hinweise
- **Validierung**: Zusätzliche Feldprüfungen können in `sanitize()` ergänzt werden (z.B. Grenzen für `kapazitaet`).
- **Rechte/Authentifizierung**: Aktuell relyt die API auf vorgelagerte Authentifizierung (z.B. via `auth.php`). Bei Bedarf `$_SESSION`/Token-Prüfung ergänzen.
- **Konfigurations-Metadaten**: Weitere Infos (Erstellungsdatum, Autor) könnten als zusätzliche Spalten in den Konfigurationstabellen oder begleitenden Metatabellen gespeichert werden.
- **Preview-Layout**: `drawPreview()` nutzt einfaches Grid. Eine komplexere Positionslogik (z.B. Breite/Höhe aus DB) kann dort erweitert werden.
- **Internationalisierung**: UI-Texte sind fest in Deutsch; für Mehrsprachigkeit könnten Text-Keys und Übersetzungsdateien eingebunden werden.
- **Unit Tests**: Der PHP-Teil nutzt keine Tests; kritische Funktionen wie `zimmer_config_create` könnten mit CLI-Skripten geprüft werden.

## 9. Bekannte Besonderheiten
- **Ablage-Zeile**: Unveränderlich und nicht löschbar, sowohl in Tabelle als auch Canvas gesperrt.
- **Farbcodierung**: Farben werden als ARGB gespeichert, aber als RGB angezeigt; der Alpha-Wert wird derzeit immer auf `FF` gesetzt.
- **Canvas-Auflösung**: Dynamische Anpassung an `devicePixelRatio`, um unscharfe Darstellung auf Retina-Displays zu vermeiden.
- **Overwrite-Schutz**: Geschützte Konfigurationen (`protected = true`, z.B. Arbeitskopie) werden in der „Speichern als…“-Modal nicht zur Überschreibung angeboten.

## 10. Referenzen & Abhängigkeiten
- **Bootstrap 5.3.2** (CDN): Layout-Komponenten, Modals.
- **Fetch API**: HTTP-Kommunikation.
- **MySQL/MariaDB**: Datenhaltung (`zp_zimmer`, `zp_zimmer_*`).
- **Browser-Unterstützung**: ES2020-Features (optional chaining wird NICHT verwendet; kompatibel mit modernen Evergreen-Browsern).

Diese Dokumentation soll als Einstiegspunkt dienen, um Änderungen an `index.php` schneller zu verstehen und sicher umzusetzen. Für größere Refactorings empfiehlt sich, Backend- und Frontend-Teile langfristig in getrennte Module aufzuteilen.