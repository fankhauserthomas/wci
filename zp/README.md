# Zimmerplan & Timeline Modul (`wci/zp`)

Dieses Verzeichnis bündelt alle Frontend- und Backend-Komponenten für den Zimmerplan der Franz-Senn-Hütte. Die Module decken drei Hauptbereiche ab:

1. **Tagesansicht (`zp_day.php`)** – Drag-&-Drop-Planung einzelner Tage inklusive Sidebar, Ablage und Live-Farblogik aus AV-Daten.
2. **Timeline** – Canvas-basierte Mehrwochenansicht (`timeline-unified.html`/`.js`) plus Konfigurator (`timeline-config.html`/`.js`).
3. **Zimmereditor (`zimmereditor/index.php`)** – Verwaltungsoberfläche für Stammdaten der Schlafräume samt Layout-Vorschau.

Alle Oberflächen greifen auf gemeinsame PHP-APIs zu, die auf der lokalen MySQL-Instanz basieren (verbindungsdetails → `config.php`).

---

## Inhaltsverzeichnis
- [Verzeichnisstruktur](#verzeichnisstruktur)
- [Frontend-Komponenten](#frontend-komponenten)
- [Backend-APIs](#backend-apis)
- [Datenquellen & Tabellen](#datenquellen--tabellen)
- [Konfiguration & Sync](#konfiguration--sync)
- [Entwicklung & Tests](#entwicklung--tests)
- [Weiterführende Dokumente](#weiterführende-dokumente)

---

## Verzeichnisstruktur

| Pfad | Beschreibung |
| --- | --- |
| `zp_day.php` | Hauptansicht für den Zimmerplan eines Tages (Drag & Drop, mobile Optimierungen, Ablage).
| `timeline-unified.html` | Canvas-basierte Timeline UI mit Modals, Radialmenü und Sticky Notes.
| `timeline-unified.js` | Rendering-, Interaktions- und Datenlogik der Timeline (Canvas, Caching, Radial-Menü, Histogramme).
| `timeline-config.html` | Konfigurationsoberfläche für Themes, Layout-Metriken und Wochenbereich.
| `timeline-config.js` | Persistenz der Konfiguration (Cookie + `localStorage`), `postMessage`-Brücke zur Timeline.
| `zimmereditor/index.php` | CRUD-Oberfläche für `zp_zimmer` inkl. Farbwähler, Drag-Sortierung, Layout-Vorschau.
| `api_*.php`, `get*.php`, `update*.php` | REST-ähnliche Endpunkte für Reservierungen, Zimmer, Arrangements, Attribute usw. (Details unten).
| `assignRoomsToReservation.php` | Speichert Zuordnungen ganzer Aufenthalte zu Räumen mit Kapazitätsprüfung.
| `README.md` | Diese Datei.
| `TIMELINE-CONFIG-ANALYSIS.md` | Technische Analyse und Änderungslog zum Timeline-Refactoring.


Unterordner:

| Pfad | Inhalt |
| --- | --- |
| `zimmereditor/` | Enthält `index.php` (liefert HTML + JS aus einer Datei).

---

## Frontend-Komponenten

### Tagesansicht – `zp_day.php`
* Flex-Layout mit Sidebar (Datum, Ablage, Zurück-Button) und scrollbarem Grid.
* Drag-and-drop von Reservierungen zwischen Zimmern und Ablage (Pointer Events & Touch-Optimierung).
* Dynamische Farbgebung aus AV-Daten (Anreise/Abreise-Akzente, Gastinformationen, Meta-Text).
* Intelligente Höhenberechnung der Zimmer (Desktop vs. Touch), dreispaltiges Layout bei hoher Belegung.
* Auto-Scroll beim Ziehen, Konfliktprüfung, Kapazitätschecks und Logging.

### Timeline – `timeline-unified.html` & `timeline-unified.js`
* Single-Canvas-Renderer für Master-Reservierungen und Zimmerdetails (inkl. Viewport-Culling, Object Pools, Sticky Notes).
* Radialmenü (`TimelineRadialMenu`) für Farbwahl, Arrangement-Anpassung, Kapazitäten und Kommandos.
* Modale Dialoge (einheitlich via CSS-Variablen), Histogramm-Darstellung, Suche, Mehrfachauswahllogik.
* Lädt Daten über `getZimmerplanData.php`, `getRooms.php`, `getArrangements.php`, `getOrigins.php`, `getHistogramSource.php` usw.
* Debug-Helfer (z. B. `sessionStorage.debugStickyNotes = true`).

### Timeline-Konfigurator – `timeline-config.html` & `timeline-config.js`
* UI zur Anpassung von Farben, Schriftgrößen, Balkenhöhen, Wochenbereich (Vergangenheit/Zukunft).
* Persistiert Einstellungen in `timeline_config`-Cookie plus `localStorage`.
* Sendet Live-Updates über `postMessage` an die Timeline (z. B. `updateMetric`, `weeks-past`, `weeks-future`).

### Zimmereditor – `zimmereditor/index.php`
* Tabellarische Bearbeitung von `zp_zimmer` (Bezeichnung, Kapazität, Kategorie, Farbe, Sichtbarkeit).
* Drag-Sortierung, Filter, Farbwähler mit fixer Palette, Schutz der Ablage-Zeile (nicht lösch-/änderbar).
* Canvas-Vorschau zur Kontrolle der Raum-Grid-Positionen (`px`/`py`).
* Speichert Änderungen gesammelt (`?action=save`) und blockiert Inkonsistenzen (Transaktionen).

---

## Backend-APIs

| Datei | Zweck | Wichtige Parameter |
| --- | --- | --- |
| `api_room_data.php` | Liefert Tages- bzw. Bereichsreservierungen + Zimmerliste (für Tagesansicht). | `date` oder `von`/`bis` |
| `api_update_room.php` | Weist eine Detail-Reservierung (`AV_ResDet`) einem neuen Zimmer zu. | JSON: `reservation_id`, `new_zimmer_id` |
| `assignRoomsToReservation.php` | Schreibt Raumzuweisungen für einen gesamten Aufenthalt (mit Kapazitätsvalidierung). | JSON: `res_id`, `start`, `end`, `assignments` |
| `getZimmerplanData.php` | Liefert Timeline-Daten (Master + Details, Statistik) für Zeitraum. | `start`, `end` |
| `getRooms.php` | Zimmerliste mit optionaler Belegungsberechnung (`mode=day` oder Zeitraum). | `day` / `start`,`end` |
| `getArrangements.php` | Arrangements (ID, Kürzel) aus Tabelle `arr`. | – |
| `getOrig​ins.php` | Herkunftsländer aus Tabelle `origin`. | – |
| `getCountries.php` | Länder (Fallback auf Demo-Daten falls Tabelle fehlt). | – |
| `getHistogramSource.php` | Rohdaten für Aufenthalts-Histogramme (Kapazitäten je AV-Reservierung). | `start`, `end` |
| `getAVReservationData.php` | Stammdaten zu einer AV-Reservierung. | `av_res_id` |
| `updateReservationMasterData.php` | Aktualisiert Felder aus `AV-Res` (Notizen, Kontaktdaten, Kapazitäten, optional AV-geschützte Felder). | JSON: `av_res_id`, `updates` |
| `updateRoomDetail.php` | Speichert Drag-&-Drop-Verschiebungen einzelner Detailblöcke (Zimmer + Datum). | JSON: `detail_id`, `res_id`, `room_id`, `start_date`, `end_date` |
| `updateRoomDetailAttributes.php` | Aktualisiert Detail-Attribute (Farbe, Arrangement, Notiz, Hund, Sticky-Offsets). | JSON: `scope`, `detail_id`/`res_id`, `updates` |

Alle Endpunkte nutzen `mysqli`, Prepared Statements und setzen CORS/JSON-Header. Fehler führen zu konsistenten JSON-Antworten (`success: false`).

---

## Datenquellen & Tabellen

Haupttabellen (MySQL):

| Tabelle | Verwendung |
| --- | --- |
| `zp_zimmer` | Stammdaten der Zimmer (Anzeigename, Kapazität, Position, Farbe, Sichtbarkeit). |
| `AV_ResDet` | Detailzeilen einzelner Reservierungen (Zimmer, Zeitraum, Anzahl, Farbe, Notiz, Hund). |
| `AV-Res` | Master-Reservierungen (An-/Abreise, Kontaktdaten, Kapazitäten, Arrangements, Bemerkungen). |
| `arr` | Arrangement-Stammdaten. |
| `origin`, `countries` | Herkunfts- bzw. Länderlisten (für Auswahllisten).

Kapazitätsprüfungen berücksichtigen `storno`, Hundeflag, Arrangements sowie parallele Belegung durch Fremdreservierungen.

---

## Konfiguration & Sync

* `../config.php` (Projektwurzel) stellt die MySQL-Verbindung bereit (lokal + Remote-Credentials) und aktiviert Auto-Sync (`triggerAutoSync`).
* `zp/config.php` ist lediglich ein Wrapper und bindet die zentrale Konfiguration ein – es enthält keine eigenen Zugangsdaten mehr.
* `SYNC_ENABLED` + `SYNC_BATCH_SIZE` steuern Hintergrundsynchronisation via `syncTrigger.php`.
* Sensible Zugangsdaten sollten außerhalb des Repos verwaltet werden (z. B. über `.env`/Server-Konfiguration); dieses Repository enthält derzeit Klartextwerte.

---

## Entwicklung & Tests

**Voraussetzungen**
* PHP 8.x mit `mysqli`
* MySQL/MariaDB mit oben genannten Tabellen
* Webserver (Apache/nginx) oder PHP-Dev-Server

**Lokaler Start (Beispiel)**

```bash
php -S 0.0.0.0:8080 -t /home/vadmin/lemp/html
```

Danach:
* Tagesansicht: `/wci/zp/zp_day.php`
* Timeline: `/wci/zp/timeline-unified.html`
* Konfigurator: `/wci/zp/timeline-config.html`
* Zimmereditor: `/wci/zp/zimmereditor/index.php`

**Debug-Hinweise**
* Timeline Sticky-Notes Debug: `sessionStorage.setItem('debugStickyNotes', 'true')` im Browser.
* Tagesansicht schreibt ausführliche `console.debug`-Logs für Pointer-Events.
* Auto-Sync (exec) lässt sich durch `define('SYNC_ENABLED', false)` deaktivieren.

---

## Weiterführende Dokumente

* `TIMELINE-CONFIG-ANALYSIS.md` – Detaillierte Analyse von Timeline & Konfigurator (Änderungshistorie, Empfehlungen).
* `papierkorb/` (Root) – enthält archivierte Debug-/Testscripte, u. a. `debug_day_occupancy.php` und `debug_tables.php` (bereits verschoben).

---

Bei Fragen zu Datenstrukturen oder API-Erweiterungen bitte zunächst `TIMELINE-CONFIG-ANALYSIS.md` sowie den Quellcode der jeweiligen Endpunkte konsultieren. Änderungen an der DB-Struktur sollten stets über Migrationen oder dokumentierte SQL-Skripte erfolgen.
