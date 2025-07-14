# HttpUtils - Robuste HTTP-Kommunikation

Die HttpUtils-Bibliothek stellt robuste HTTP-Kommunikation mit automatischen Wiederholungen, exponential backoff und Verbindungsüberwachung bereit.

## Integration in neue Skripte

### 1. HTML-Einbindung

Fügen Sie das HttpUtils-Skript in Ihre HTML-Dateien ein:

```html
<script src="js/http-utils.js"></script>
<script src="ihr-script.js"></script>
```

### 2. Verwendung in JavaScript

#### Einfache GET-Requests

```javascript
// Alt (unsicher):
fetch('api.php').then(r => r.json()).then(data => {...});

// Neu (robust):
const data = window.HttpUtils
  ? await HttpUtils.requestJson('api.php', {}, { retries: 3, timeout: 10000 })
  : await fetch('api.php').then(r => r.json());
```

#### POST-Requests mit JSON

```javascript
// Alt (unsicher):
fetch("api.php", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(data),
}).then((r) => r.json());

// Neu (robust):
const result = window.HttpUtils
  ? await HttpUtils.postJson("api.php", data, { retries: 2, timeout: 8000 })
  : await fetch("api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    }).then((r) => r.json());
```

#### Batch-Requests (für mehrere parallele Anfragen)

```javascript
const requests = ids.map((id) => ({
  url: "updateItem.php",
  options: {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ id, action: "update" }),
  },
}));

const { results, errors, successCount } = await HttpUtils.batchRequest(
  requests,
  {
    concurrency: 3, // Max 3 parallele Requests
    retryOptions: { retries: 2, timeout: 8000 },
    onProgress: (completed, total) => console.log(`${completed}/${total}`),
  }
);
```

## Konfigurationsoptionen

### Retry-Optionen

```javascript
{
  retries: 3,                    // Anzahl Wiederholungen
  retryDelay: 1000,             // Start-Verzögerung in ms
  timeout: 10000,               // Timeout in ms
  backoffMultiplier: 2,         // Exponential backoff Faktor
  retryOn: [408, 429, 500, 502, 503, 504, 0]  // HTTP-Status für Retry
}
```

## Verbindungsüberwachung

HttpUtils stellt automatisch einen Connection Monitor bereit:

```javascript
// Verbindungsstatus prüfen
if (window.connectionMonitor && !window.connectionMonitor.isOnline()) {
  alert("Keine Internetverbindung verfügbar");
  return;
}

// Verbindungsqualität anzeigen
HttpUtils.showConnectionStatus(window.connectionMonitor);
```

## Fehlerbehandlung

```javascript
try {
  const data = await HttpUtils.requestJson("api.php");
  // Erfolg
} catch (error) {
  console.error("Request failed:", error);
  // Robust error handling
  if (error.message.includes("network") || error.message.includes("timeout")) {
    alert("Netzwerkfehler. Bitte Verbindung prüfen und erneut versuchen.");
  } else {
    alert("Fehler: " + error.message);
  }
}
```

## Migration bestehender fetch()-Aufrufe

### Schritt 1: Fallback-Pattern verwenden

```javascript
const loadData = async () => {
  const data = window.HttpUtils
    ? await HttpUtils.requestJson("api.php", {}, { retries: 3 })
    : await fetch("api.php").then((r) => r.json());

  return data;
};
```

### Schritt 2: Error Handling verbessern

```javascript
try {
  const data = await loadData();
  // Process data
} catch (error) {
  console.error("Detailed error:", error);
  showUserFriendlyError(error);
}
```

## Bereits integrierte Dateien

✅ **Vollständig integriert:**

- `reservation.js` - Alle fetch-Operationen mit robuster Batch-Verarbeitung
- `timeline.js` - Datenladung mit Retry-Logik
- `script.js` - Haupt-Datenladung und QR-Code-Generation
- `ReservationDetails.js` - Alle API-Aufrufe
- `GastDetail.html` - Inline-Skript mit HttpUtils

✅ **HTML-Dateien mit HttpUtils:**

- `index.html`
- `reservation.html`
- `ReservationDetails.html`
- `simple-timeline.html`
- `GastDetail.html`
- `test-rooms.html`

## Best Practices

1. **Immer Fallback verwenden** für Kompatibilität
2. **Timeouts anpassen** je nach Anwendungsfall (schnelle Checks: 5s, große Daten: 15s)
3. **Concurrency begrenzen** bei Batch-Requests (2-3 parallel)
4. **User Feedback** bei längeren Operationen
5. **Verbindungscheck** vor kritischen Operationen
6. **Error Logging** für Debugging

## Support für instabile Verbindungen

HttpUtils ist speziell für instabile WLAN-Verbindungen optimiert:

- Automatische Wiederholungen bei Netzwerkfehlern
- Exponential backoff verhindert Server-Überlastung
- Connection quality monitoring
- Graceful degradation bei Problemen

## Zukünftige Erweiterungen

- Progressive Web App (PWA) Caching
- Background sync für offline operations
- Request queuing bei Verbindungsproblemen
- Detaillierte Metriken und Analytics
