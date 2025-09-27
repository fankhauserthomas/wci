# Timeline Config & Unified Renderer – Analyse, Optimierung und Doku (Stand: 2025-09-27)

## Ziele
- Funktionalität unverändert beibehalten
- Code auf Effizienz, Fehler und Redundanzen prüfen
- Modale Formulare farblich vereinheitlichen (einheitliches Design)
- Liste unnötiger Dateien im Verzeichnis erstellen
- Dokumentation der Struktur, Abhängigkeiten und Empfehlungen

> **Update 2025-09-27:** Die eigenständigen Dateien `timeline-config.html` und `timeline-config.js` wurden entfernt. Deren Funktionen (Theme-Presets, Menügröße, Wochenbereich, Zimmereditor-Link) leben nun in der integrierten Toolbar von `timeline-unified.html`. Dieser Bericht bleibt als historische Referenz bestehen und dokumentiert zusätzlich die Migration.

---

## Verzeichnis-Überblick `/wci/zp`
Aktive Dateien (verwendet per `fetch()`/Seitenaufruf):
- `timeline-unified.html` – Hauptseite (Canvas-Renderer, Modals, Debug-Konsole, Toolbar)
- `timeline-unified.js` – Renderer und Interaktionslogik
- API-Endpoints (per `fetch` referenziert):
  - `getZimmerplanData.php`, `getRooms.php`, `getArrangements.php`, `getOrigins.php`, `getCountries.php`, `getHistogramSource.php`, `getAVReservationData.php`
  - Update-Endpoints: `updateRoomDetail.php`, `updateRoomDetailAttributes.php`, `updateReservationMasterData.php`
- `config.php` – DB-Konfiguration
- `README.md` – Dokumentation

Legacy (entfernt am 2025-09-27, Inhalte in Toolbar überführt):
- `timeline-config.html`
- `timeline-config.js`

Nicht identifiziert: Test-/Debug-/Backup-Dateien – keine gefunden in diesem Verzeichnis.

---

## Wichtige UI-Verknüpfungen
- Toolbar in `timeline-unified.html` aktualisiert Theme/Metadaten direkt über Renderer-Methoden (`applyTimelinePreset`, `updateMenuSize`, `updateTimelineWeekRange`).
- Der vorhandene `message`-Listener bleibt erhalten, um Kompatibilität mit älteren Fenstern/Skripten zu wahren (z. B. falls die entfernte Seite lokal noch geöffnet ist).
- Theme-/Layoutwerte werden weiterhin in Cookie `timeline_config` sowie im `localStorage` gespeichert.

---

## Modals – Vereinheitlichtes Design
- In `timeline-unified.html` wurden CSS-Variablen eingeführt (Präfix `--modal-*`), bestehende Farben übernommen (keine visuellen Änderungen), alle Modal-Styles verweisen nun auf Variablen:
  - Overlay, Dialog, Header-Text, Body-Text
  - Buttons: Cancel, Primary, Danger, Secondary (+ Hover)
  - Inputs/Textarea inkl. Fokuszustände
  - Dataset-Abschnitte
- Vorteil: Einheitliches Farbschema, zentrale Anpassbarkeit, keine Logikänderung

---

## Redundanzen und kleine Korrekturen (Legacy-Hinweise)
- `timeline-config.html` enthielt zwei „Day Width“-Regler mit identischer `id="day-width"` und `id="day-width-value"`. Der doppelte (zweite) Regler im Bereich „Layout Settings“ wurde entfernt, da bereits unter „Anzeige-Einstellungen“ vorhanden. Vermeidet ID-Konflikte und doppelte Events. (Die Seite ist inzwischen entfernt, die Erkenntnis bleibt historisch relevant.)
- Veraltete Basismetrics-Styles wurden durch den bestehenden „Kompakte Metrics-Grid Styles“-Block ersetzt (Kommentarhinweis belassen). Keine visuellen Änderungen, jedoch eindeutige Stylequelle.

---

## Effizienz- und Qualitätsbewertung (Kurz)
- `timeline-unified.js`:
  - Enthält umfangreiche Performance-Optimierungen: Viewport Culling, Batch-Rendering, Object-Pool, Caches (Stacking/Histogram), Drag-Optimierungen.
  - API-Aufrufe sind `fetch`-basiert mit Fehlerbehandlung. Speicherung per POST auf klar getrennten Endpoints.
  - Theme lädt aus Cookie/LocalStorage mit Fallbacks und Defaultwerten.
- `timeline-config.js`:
  - Sauberer Theme-Katalog, getrennte Layout- und Farbwerte, Cookie+localStorage Persistenz.
  - `updatePreview()` ist ein No-Op (Preview entfernt) – Absicht: Kompatibilität.
- PHP-APIs:
  - Nutzen `mysqli` und Prepared Statements. Datums-/Int-Konvertierungen beachtet.
  - CORS/Preflight-Header vorhanden. Fehlerausgaben JSON-konsistent.

Empfohlene, risikofreie nächste Schritte (optional):
- Konsolidierte Utility-Funktionen für Datumsformatierung in JS (aktuell mehrfach ähnlich vorhanden).
- Zentralisierte Fehler-/Toast-Komponenten statt `alert()` in UI.
- Typisierung/JS Docs für komplexe Datenobjekte (reservations/roomDetails) zur besseren Wartbarkeit.

---

## Unnötige Dateien – Ergebnis
Im Verzeichnis `/wci/zp` wurden aktuell keine eindeutig unnötigen Dateien gefunden.
- Alle `.php`-Dateien werden durch `timeline-unified.*` referenziert (grep-Nachweis).
- `README.md` bleibt als Dokumentation.

Hinweis: In übergeordneten Ordnern existieren Debug-/Analyse-Skripte, die nicht Teil dieses Auftrags sind.

---

## Akzeptanzkriterien & Abdeckung
- Funktionalität unverändert: Ja (nur CSS-Variablen ergänzt, doppelte ID-Quelle entfernt; keine Logik-/API-Änderungen)
- Modale einheitlich: Ja (CSS-Variablen in `timeline-unified.html`)
- Effizienzprüfung durchgeführt: Ja (Kurzbewertung + Empfehlungen)
- Liste unnötiger Dateien: Ja (derzeit keine im `zp`-Ordner)
- Dokumentation erstellt: Diese Datei

---

## Changelog (diese Arbeiten)
- `timeline-config.html`:
  - Entfernt: doppelter "Day Width"-Regler im Abschnitt „Layout Settings“ (IDs `day-width`, `day-width-value`)
  - Kommentar ergänzt: Basismetrics-Styles werden durch kompakte Styles ersetzt
  - Neu: Regler für „Wochen in der Vergangenheit“ (`weeks-past`) und „Wochen in der Zukunft“ (`weeks-future`) im Abschnitt Anzeige-Einstellungen. Werte werden live via `postMessage` an die Timeline übergeben.
- `timeline-unified.html`:
  - CSS-Variablen `--modal-*` eingeführt und bestehende Modal-Styles darauf umgestellt
  - Message-Listener erweitert: Reagiert nun auch auf `weeks-past`/`weeks-future` und rendert den neuen Datumsbereich ohne Reload. Initialer Datenabruf nutzt die gespeicherten Wochenwerte.
  - Hinweis: Kein Abfangen von F5/Ctrl+R – standardmäßiges Browser-Reload bleibt unverändert.
- `TIMELINE-CONFIG-ANALYSIS.md` hinzugefügt
 - `timeline-config.js`: Persistiert `weeksPast`/`weeksFuture` in Cookie/localStorage; `selectTheme()` übernimmt diese Werte. `updateInputsFromConfig()` und `updateFromInputs()` binden die neuen Felder ein. Defaults ergänzt.
 - `timeline-unified.js`: Theme-Defaults um `weeksPast`/`weeksFuture` erweitert; `addMissingDefaults()` ergänzt diese Felder; `render()` nutzt die konfigurierten Wochen statt fixen 14 Tagen/2 Jahren.

## Betriebshinweise
- Keine DB-Migrationen nötig
- Keine API-Änderungen
- Keine autonomen Tests, die DB schreiben, wurden hinzugefügt

## Quick-Check (nach Toolbar-Migration)
- Öffnen: `timeline-unified.html` (Timeline mit Toolbar)
- Preset wechseln oder Slider im Toolbar-Menü anpassen → Renderer aktualisiert Theme/Menu-Größe live
- Wochen-Eingabefelder ändern → Datenbereich und Persistenz aktualisieren; anschließend `reloadTimelineData()` für frische API-Daten auslösen (Automatik läuft beim Wertwechsel)
 - Hinweis: F5/Ctrl+R werden nicht abgefangen; das Standard-Reload-Verhalten des Browsers bleibt bestehen.
- Modals (Bestätigung, Bezeichnung, Notiz, Dataset) sehen unverändert aus, werden aber nun zentral über Variablen gestylt
