# Server-Sent Events (SSE) Import - Dokumentation

## Übersicht

Der HRS Import für **Daily Summary** nutzt jetzt Server-Sent Events (SSE) für Echtzeit-Fortschritts-Updates während des Imports.

## Vorteile gegenüber normalem Fetch

| Feature | Normales Fetch | SSE |
|---------|---------------|-----|
| **Fortschritt** | ❌ Nur am Ende | ✅ Echtzeit während Verarbeitung |
| **Log-Updates** | ❌ Nur gesammelt am Ende | ✅ Tag-für-Tag Live-Updates |
| **User-Feedback** | ❌ "Lädt..." ohne Details | ✅ Sieht exakten Fortschritt |
| **Fehler-Erkennung** | ❌ Erst nach Timeout | ✅ Sofort sichtbar |
| **Connection** | ❌ Request-Response | ✅ Persistent Stream |
| **Komplexität** | ✅ Einfach | ⚠️ Mittelschwer |

## Architektur

```
┌─────────────────┐           SSE Stream            ┌──────────────────┐
│                 │ ────────────────────────────────>│                  │
│  Browser        │  data: {...}                     │  PHP Script      │
│  (JavaScript)   │  data: {...}                     │  (hrs_imp_*      │
│                 │  data: {...}                     │   _stream.php)   │
└─────────────────┘ <────────────────────────────────┘──────────────────┘
      ▲                    HTTP/1.1 Chunked                  │
      │                    Transfer-Encoding                 │
      │                                                       │
      └───────── EventSource API ─────────────────────────────┘
```

## Implementierung

### 1. PHP Server (hrs_imp_daily_stream.php)

```php
// SSE Headers - WICHTIG!
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx buffering deaktivieren

// Disable PHP output buffering
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

function sendSSE($type, $data = []) {
    $message = array_merge(['type' => $type], $data);
    echo "data: " . json_encode($message) . "\n\n"; // Doppeltes \n\n ist wichtig!
    if (ob_get_level()) ob_flush();
    flush(); // Sofort zum Client senden
}

// Beispiel-Nutzung
sendSSE('start', ['message' => 'Import beginnt...']);
sendSSE('progress', ['current' => 5, 'total' => 10, 'percent' => 50]);
sendSSE('log', ['level' => 'success', 'message' => 'Tag 5 importiert']);
sendSSE('finish', ['message' => 'Fertig!']);
```

### 2. JavaScript Client (timeline-unified.html)

```javascript
const eventSource = new EventSource('../hrs/hrs_imp_daily_stream.php?from=2024-01-01&to=2024-01-07');

eventSource.onmessage = (event) => {
    const data = JSON.parse(event.data);
    
    switch (data.type) {
        case 'start':
            console.log('Import started:', data.message);
            break;
            
        case 'progress':
            // Update progress bar: data.current / data.total
            updateProgressBar(data.percent, data.day);
            break;
            
        case 'log':
            // Add log entry with level (info/success/error/warn)
            addLog(data.message, data.level);
            break;
            
        case 'finish':
            console.log('Import finished!');
            eventSource.close(); // Connection beenden
            break;
            
        case 'error':
            console.error('Import error:', data.message);
            eventSource.close();
            break;
    }
};

eventSource.onerror = (error) => {
    console.error('SSE connection error:', error);
    eventSource.close();
};
```

## Message Types

### `start`
Import beginnt
```json
{
  "type": "start",
  "message": "Initialisiere HRS Import...",
  "dateFrom": "01.01.2024",
  "dateTo": "07.01.2024"
}
```

### `phase`
Neue Phase beginnt
```json
{
  "type": "phase",
  "step": "daily",
  "name": "Daily Summary",
  "message": "Starte Import..."
}
```

### `total`
Gesamtanzahl bekannt
```json
{
  "type": "total",
  "count": 7
}
```

### `progress`
Fortschritts-Update
```json
{
  "type": "progress",
  "current": 3,
  "total": 7,
  "percent": 43,
  "day": "03.01.2024"
}
```

### `log`
Log-Eintrag
```json
{
  "type": "log",
  "level": "success",  // info|success|error|warn
  "message": "✓ Tag 03.01.2024 erfolgreich importiert"
}
```

### `complete`
Phase abgeschlossen
```json
{
  "type": "complete",
  "step": "daily",
  "message": "Import abgeschlossen: 7 von 7 Tagen importiert",
  "totalProcessed": 7,
  "totalInserted": 7
}
```

### `finish`
Gesamter Import fertig
```json
{
  "type": "finish",
  "message": "Import vollständig abgeschlossen!"
}
```

### `error`
Fehler aufgetreten
```json
{
  "type": "error",
  "message": "HRS Login fehlgeschlagen"
}
```

## Workflow (Daily Summary Import)

```
1. User klickt "HRS Daten importieren"
   ↓
2. JavaScript öffnet EventSource
   → GET ../hrs/hrs_imp_daily_stream.php?from=...&to=...
   ↓
3. PHP sendet 'start' Event
   → Modal zeigt: "Initialisiere..."
   ↓
4. PHP sendet 'phase' Event
   → Step 1 (Daily) wechselt auf 'running'
   ↓
5. PHP sendet 'total' Event
   → Modal zeigt: "7 Tage zu importieren"
   ↓
6. PHP verarbeitet Tag 1
   → sendet 'progress' (1/7, 14%)
   → sendet 'log' ("✓ Tag 1 OK")
   → Modal updated: Progress-Bar + Log-Eintrag
   ↓
7. PHP verarbeitet Tag 2-7
   → Für jeden Tag: 'progress' + 'log'
   → User sieht Live-Updates
   ↓
8. PHP sendet 'complete' Event
   → Step 1 (Daily) wechselt auf 'success'
   ↓
9. PHP sendet 'finish' Event
   → EventSource wird geschlossen
   → Import-Handler führt nächsten Step aus (Quota)
```

## Browser-Kompatibilität

| Browser | Support |
|---------|---------|
| Chrome | ✅ 6+ |
| Firefox | ✅ 6+ |
| Safari | ✅ 5+ |
| Edge | ✅ 79+ |
| IE | ❌ Nicht unterstützt |

## Performance

### Overhead
- **Zusätzlicher Traffic:** ~50-100 bytes pro Event
- **Latenz:** <50ms pro Update
- **Memory:** Minimal (kein Buffering)

### Optimierungen
```php
// Throttling: Nur jedes 10. Update senden
if ($dayIndex % 10 === 0) {
    sendSSE('progress', [...]);
}

// Delay für bessere UI-Darstellung
usleep(50000); // 50ms Pause zwischen Tagen
```

## Troubleshooting

### Problem: Events kommen nicht an

**Ursache:** Nginx buffert die Ausgabe

**Lösung:**
```nginx
# In nginx.conf
proxy_buffering off;
proxy_cache off;
```

Oder im PHP-Header:
```php
header('X-Accel-Buffering: no');
```

---

### Problem: Connection wird nach 30s geschlossen

**Ursache:** PHP max_execution_time oder nginx timeout

**Lösung:**
```php
// In PHP
set_time_limit(300); // 5 Minuten

// In nginx.conf
proxy_read_timeout 300s;
```

---

### Problem: "Error parsing SSE message"

**Ursache:** Ungültiges JSON oder falsches Format

**Lösung:**
```php
// IMMER doppeltes \n\n am Ende!
echo "data: " . json_encode($message) . "\n\n";

// Vor flush() prüfen
if (ob_get_level()) ob_flush();
flush();
```

---

### Problem: EventSource.onerror wird sofort gefeuert

**Ursache:** HTTP Status !== 200 oder falscher Content-Type

**Lösung:**
```php
// Zuerst Headers setzen, dann Fehler werfen
header('Content-Type: text/event-stream');
// ... dann erst Logic
```

---

## Testing

### Manual Test
1. Browser-Console öffnen
2. Timeline öffnen
3. 7 Tage im Histogram selektieren
4. "HRS Daten importieren" klicken
5. **Erwartung:**
   - Modal öffnet
   - "Verbinde mit Server..." erscheint
   - Fortschritts-Updates erscheinen Tag-für-Tag
   - Log-Einträge werden live hinzugefügt
   - Nach ~5-10 Sekunden: "Import abgeschlossen"

### Console Test
```javascript
// Manueller SSE-Test
const es = new EventSource('../hrs/hrs_imp_daily_stream.php?from=2024-01-01&to=2024-01-07');
es.onmessage = (e) => console.log('Event:', JSON.parse(e.data));
es.onerror = (e) => console.error('Error:', e);

// Nach 1 Minute schließen
setTimeout(() => es.close(), 60000);
```

### Network Tab
Chrome DevTools → Network Tab:
- Request Type: `eventsource`
- Status: `200 OK` (bleibt offen)
- Content-Type: `text/event-stream`
- Transfer: Chunked
- Timeline: Zeigt eingehende Chunks

## Migration Path

### Phase 1: Daily Summary (✅ Done)
- `hrs_imp_daily_stream.php` erstellt
- JavaScript mit SSE umgebaut
- Live-Progress-Updates implementiert

### Phase 2: Quota (Todo)
- `hrs_imp_quota_stream.php` erstellen
- Analog zu Daily Summary
- 50ms Delay zwischen Einträgen

### Phase 3: Reservations (Todo)
- `hrs_imp_res_stream.php` erstellen
- Komplexer wegen Nested Data
- Progress pro Reservierung

### Phase 4: AV Capacity (Todo)
- Bereits optimiert (get_av_cap_range.php)
- Optional: SSE für API-Calls
- Progress pro API-Request

## Best Practices

### ✅ Do's
- Immer `\n\n` am Ende jeder Message
- `ob_flush()` + `flush()` nach jedem Event
- `X-Accel-Buffering: no` Header setzen
- EventSource bei Finish/Error schließen
- Timeout für lange Imports setzen
- Try-Catch um JSON.parse()

### ❌ Don'ts
- Nicht ohne `Content-Type: text/event-stream`
- Nicht Output buffering aktiviert lassen
- Nicht ohne Error-Handling
- Nicht EventSource vergessen zu schließen
- Nicht zu viele Events senden (>100/sec)
- Nicht ohne Timeout arbeiten

## Performance-Vergleich

### Vorher (Normales Fetch)
```
User Experience:
[00:00] Klick → "Importiere..."
[00:01] ... (keine Updates)
[00:05] ... (keine Updates)
[00:10] ✅ "7 Tage importiert"

Problem: 10 Sekunden ohne Feedback
```

### Nachher (SSE)
```
User Experience:
[00:00] Klick → "Verbinde..."
[00:01] "7 Tage zu importieren"
[00:02] "1/7 (14%) - 01.01.2024" + "✓ Tag 1 OK"
[00:03] "2/7 (29%) - 02.01.2024" + "✓ Tag 2 OK"
[00:04] "3/7 (43%) - 03.01.2024" + "✓ Tag 3 OK"
[00:06] "4/7 (57%) - 04.01.2024" + "✓ Tag 4 OK"
[00:07] "5/7 (71%) - 05.01.2024" + "✓ Tag 5 OK"
[00:09] "6/7 (86%) - 06.01.2024" + "✓ Tag 6 OK"
[00:10] "7/7 (100%) - 07.01.2024" + "✓ Tag 7 OK"
[00:10] ✅ "Import abgeschlossen: 7 von 7 Tagen"

Vorteil: Kontinuierliches Feedback, keine "Ladezeit"
```

## Zusammenfassung

✅ **Implementiert:**
- SSE für Daily Summary Import
- Echtzeit-Progress-Updates
- Tag-für-Tag Log-Einträge
- Automatisches Auto-Scroll im Modal
- Error-Handling mit Timeout

✅ **Features:**
- Live-Fortschritts-Balken (1/7, 2/7, ...)
- Detaillierte Log-Ausgabe (✓/✗/⚠/ℹ️)
- Prozent-Anzeige (14%, 29%, ...)
- Aktueller Tag-Name im Status
- Graceful Error-Handling

✅ **UX-Verbesserung:**
- Kein "schwarzes Loch" mehr
- User sieht sofort was passiert
- Bei Fehler: Sofort sichtbar, nicht erst nach Timeout
- Professional Look & Feel

🎯 **Nächste Schritte:**
- Quota mit SSE implementieren
- Reservations mit SSE implementieren
- Optional: AV Capacity mit SSE
