# Timeline Config & Unified Renderer – Analyse, Optimierung und Doku (Stand: 2025-09-20)

## Ziele
- Funktionalität unverändert beibehalten
- Code auf Effizienz, Fehler und Redundanzen prüfen
- Modale Formulare farblich vereinheitlichen (einheitliches Design)
- Liste unnötiger Dateien im Verzeichnis erstellen
- Dokumentation der Struktur, Abhängigkeiten und Empfehlungen

---

## Verzeichnis-Überblick `/wci/zp`
Aktive Dateien (verwendet per `fetch()`/Seitenaufruf):
- `timeline-unified.html` – Hauptseite (Canvas-Renderer, Modals, Debug-Konsole)
- `timeline-unified.js` – Renderer und Interaktionslogik
- `timeline-config.html` – UI zur Konfigurations-/Theme-Auswahl
- `timeline-config.js` – Konfigurationsmanager (Cookies + localStorage)
- API-Endpoints (per `fetch` referenziert):
  - `getZimmerplanData.php`, `getRooms.php`, `getArrangements.php`, `getOrigins.php`, `getCountries.php`, `getHistogramSource.php`, `getAVReservationData.php`
  - Update-Endpoints: `updateRoomDetail.php`, `updateRoomDetailAttributes.php`, `updateReservationMasterData.php`
- `config.php` – DB-Konfiguration
- `README.md` – Dokumentation

Nicht identifiziert: Test-/Debug-/Backup-Dateien – keine gefunden in diesem Verzeichnis.

---

## Wichtige UI-Verknüpfungen
- `timeline-config.html` → schickt Live-Änderungen via `window.opener.postMessage({ type: 'updateMetric', metric, value })`
- `timeline-unified.html` → hört auf `message` und aktualisiert z.B. Menügröße des Radialmenüs
- Theme-/Layoutwerte werden in Cookie `timeline_config` und als Fallback im `localStorage` gespeichert

---

## Modals – Vereinheitlichtes Design
- In `timeline-unified.html` wurden CSS-Variablen eingeführt (Präfix `--modal-*`), bestehende Farben übernommen (keine visuellen Änderungen), alle Modal-Styles verweisen nun auf Variablen:
  - Overlay, Dialog, Header-Text, Body-Text
  - Buttons: Cancel, Primary, Danger, Secondary (+ Hover)
  - Inputs/Textarea inkl. Fokuszustände
  - Dataset-Abschnitte
- Vorteil: Einheitliches Farbschema, zentrale Anpassbarkeit, keine Logikänderung

---

## Redundanzen und kleine Korrekturen
- `timeline-config.html` enthielt zwei „Day Width“-Regler mit identischer `id="day-width"` und `id="day-width-value"`. Der doppelte (zweite) Regler im Bereich „Layout Settings“ wurde entfernt, da bereits unter „Anzeige-Einstellungen“ vorhanden. Vermeidet ID-Konflikte und doppelte Events.
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
- `timeline-unified.html`:
  - CSS-Variablen `--modal-*` eingeführt und bestehende Modal-Styles darauf umgestellt
- `TIMELINE-CONFIG-ANALYSIS.md` hinzugefügt

## Betriebshinweise
- Keine DB-Migrationen nötig
- Keine API-Änderungen
- Keine autonomen Tests, die DB schreiben, wurden hinzugefügt

## Quick-Check
- Öffnen: `timeline-unified.html` (Timeline) und `timeline-config.html` (Konfiguration)
- Slider in Konfiguration ändern → `postMessage` an Timeline → Menügröße reagiert wie zuvor
- Modals (Bestätigung, Bezeichnung, Notiz, Dataset) sehen unverändert aus, werden aber nun zentral über Variablen gestylt
