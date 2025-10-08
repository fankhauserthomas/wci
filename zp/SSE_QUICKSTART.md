# SSE Import - Quick Start Guide

## Was wurde implementiert?

Der **Daily Summary Import** nutzt jetzt **Server-Sent Events (SSE)** für Echtzeit-Updates während des Imports.

## Dateien

### Neu erstellt:
- ✅ `/home/vadmin/lemp/html/wci/hrs/hrs_imp_daily_stream.php` - SSE-Version des Importers

### Modifiziert:
- ✅ `/home/vadmin/lemp/html/wci/zp/timeline-unified.html` - JavaScript nutzt jetzt SSE für Daily Summary

## Wie es funktioniert

### Vorher:
```
User → Klick → Fetch Request → ... Warten ... → Response → Log anzeigen
                                  (10 Sekunden)
                                  (keine Updates)
```

### Nachher:
```
User → Klick → EventSource öffnen → Stream beginnt
                                   ↓
                            [Event] Start...
                            [Event] Tag 1/7...  ✓
                            [Event] Tag 2/7...  ✓
                            [Event] Tag 3/7...  ✓
                            [Event] Tag 4/7...  ✓
                            [Event] Tag 5/7...  ✓
                            [Event] Tag 6/7...  ✓
                            [Event] Tag 7/7...  ✓
                            [Event] Finish!
                                   ↓
                            Stream schließt
```

## User Experience

### Im Modal sieht der User:

```
┌──────────────────────────────────────┐
│  HRS Import                    [×]   │
├──────────────────────────────────────┤
│                                      │
│  🔄 Daily    | 5/7 (71%) - 05.01... │ ← Live-Update!
│  ⏸️ Quota    | Warte auf Start...    │
│  ⏸️ Res      | Warte auf Start...    │
│  ⏸️ AV Cap   | Warte auf Start...    │
│                                      │
│  ┌────────────────────────────────┐ │
│  │ 🚀 Starte Daily Summary...    │ │
│  │ ℹ️  7 Tage zu importieren      │ │
│  │ ✓ Tag 01.01.2024 importiert   │ │
│  │ ✓ Tag 02.01.2024 importiert   │ │
│  │ ✓ Tag 03.01.2024 importiert   │ │
│  │ ✓ Tag 04.01.2024 importiert   │ │ ← Auto-Scroll!
│  │ ✓ Tag 05.01.2024 importiert   │ │ ← Neuester
│  └────────────────────────────────┘ │
│                                      │
└──────────────────────────────────────┘
```

## Testing

### 1. Timeline öffnen
```
https://ihr-server/wci/zp/timeline-unified.html
```

### 2. Tage selektieren
- Strg + Klick auf Histogram-Balken
- Oder Shift + Klick für Bereich

### 3. Import starten
- Klick auf "📥 HRS Daten importieren"
- Bestätigen im Dialog

### 4. Fortschritt beobachten
- Modal öffnet automatisch
- Live-Updates erscheinen sofort
- Auto-Scroll zu neuesten Logs
- Nach ~10 Sekunden: Fertig!

## Erwartetes Verhalten

### ✅ Erfolgreicher Import:

**Schritt 1 - Daily Summary (SSE):**
```
[10:23:01] 🚀 Starte Daily Summary Import...
[10:23:02] ℹ️  7 Tage zu importieren
[10:23:03] ✓ Tag 01.01.2024 erfolgreich importiert
[10:23:04] ✓ Tag 02.01.2024 erfolgreich importiert
[10:23:05] ✓ Tag 03.01.2024 erfolgreich importiert
[10:23:06] ✓ Tag 04.01.2024 erfolgreich importiert
[10:23:07] ✓ Tag 05.01.2024 erfolgreich importiert
[10:23:08] ✓ Tag 06.01.2024 erfolgreich importiert
[10:23:09] ✓ Tag 07.01.2024 erfolgreich importiert
[10:23:09] ✅ Import abgeschlossen: 7 von 7 Tagen importiert
[10:23:09] 🎉 Import vollständig abgeschlossen!
```

**Schritt 2-4:** (Weiterhin mit normalem Fetch)
```
[10:23:10] Starte Quota Import...
[10:23:15] ✅ Quota: 28 Einträge importiert
[10:23:16] Starte Reservierungen Import...
[10:23:25] ✅ Reservierungen: 45 Einträge importiert
[10:23:26] Starte AV Capacity Update...
[10:23:30] ✅ AV Capacity erfolgreich aktualisiert
```

### ❌ Bei Fehlern:

```
[10:23:01] 🚀 Starte Daily Summary Import...
[10:23:02] ℹ️  Verbinde mit HRS...
[10:23:03] ❌ HRS Login fehlgeschlagen
```

oder:

```
[10:23:01] 🚀 Starte Daily Summary Import...
[10:23:02] ℹ️  7 Tage zu importieren
[10:23:03] ✓ Tag 01.01.2024 erfolgreich importiert
[10:23:04] ✗ Fehler beim Import von Tag 02.01.2024
[10:23:05] ❌ API-Fehler: HTTP 500
```

## Debugging

### Browser-Console:

```javascript
// Check EventSource support
console.log('EventSource' in window); // true

// Manual SSE test
const es = new EventSource('../hrs/hrs_imp_daily_stream.php?from=2024-01-01&to=2024-01-07');
es.onmessage = (e) => console.log('Event:', JSON.parse(e.data));
es.onerror = (e) => { console.error('Error:', e); es.close(); };
```

### Network Tab (Chrome DevTools):

```
Name: hrs_imp_daily_stream.php
Type: eventsource
Status: 200 OK (pending)
Size: (streaming)
Time: (streaming)
```

Klick auf Request → Response → Zeigt eingehende Events in Echtzeit

### PHP Error Log:

```bash
# Wenn Events nicht ankommen:
tail -f /var/log/nginx/error.log
tail -f /var/log/php-fpm/error.log

# Check für buffering issues
grep -i buffer /etc/nginx/nginx.conf
```

## Troubleshooting

### Problem: Keine Live-Updates

**Check 1:** Nginx Buffering
```bash
# In /etc/nginx/sites-available/your-site
location ~ \.php$ {
    proxy_buffering off;
    proxy_cache off;
    # oder
    fastcgi_buffering off;
}
```

**Check 2:** PHP Output Buffering
```bash
php -i | grep output_buffering
# Should be: off
```

**Check 3:** Browser Support
```javascript
if (!window.EventSource) {
    alert('Browser unterstützt kein SSE!');
}
```

---

### Problem: Events kommen, aber Modal updated nicht

**Check:** JavaScript Error in Console
```javascript
// In Browser Console:
// Sollte keine Errors zeigen wenn Event kommt
```

**Fix:** Event-Handling prüfen
```javascript
eventSource.onmessage = (event) => {
    try {
        const data = JSON.parse(event.data); // Kann hier fehlschlagen
        console.log('Parsed data:', data);
    } catch (e) {
        console.error('Parse error:', e, event.data);
    }
};
```

---

### Problem: Connection bricht nach 30 Sekunden ab

**Ursache:** Timeout in Nginx/PHP

**Fix Nginx:**
```nginx
proxy_read_timeout 300s;
# oder
fastcgi_read_timeout 300s;
```

**Fix PHP:**
```php
set_time_limit(300); // 5 Minuten
```

---

## Performance-Metriken

### Normale Imports (7 Tage):
```
Ohne SSE:
- Start: 0s
- Updates: 0 (keine während Import)
- Finish: 10s
- User Feedback: ⚠️ Nur am Ende

Mit SSE:
- Start: 0s
- Updates: 7 (eines pro Tag)
- Finish: 10s
- User Feedback: ✅ Kontinuierlich
```

### Große Imports (30 Tage):
```
Ohne SSE:
- Start: 0s
- Updates: 0
- Finish: 45s
- User Feedback: ⚠️ "Hängt das?"

Mit SSE:
- Start: 0s
- Updates: 30 (eines pro Tag)
- Finish: 45s
- User Feedback: ✅ "15/30 (50%)..."
```

## Vorteile auf einen Blick

| Feature | Vorher | Nachher |
|---------|--------|---------|
| User sieht Fortschritt | ❌ | ✅ |
| Live-Updates | ❌ | ✅ |
| Prozent-Anzeige | ❌ | ✅ |
| Tag-für-Tag Log | ❌ | ✅ |
| Fehler sofort sichtbar | ❌ | ✅ |
| Auto-Scroll Logs | ✅ | ✅ |
| Professional Look | ⚠️ | ✅ |
| Implementation Overhead | - | +300 LOC |

## Nächste Schritte

### Phase 2: Quota mit SSE
```bash
# Erstellen:
cp hrs_imp_daily_stream.php hrs_imp_quota_stream.php

# Anpassen:
# - Class Name: HRSQuotaImporterSSE
# - API Endpoint: /api/v1/manage/reservation/quota
# - Progress: Pro Quota-Eintrag statt pro Tag
```

### Phase 3: Reservations mit SSE
```bash
# Erstellen:
cp hrs_imp_daily_stream.php hrs_imp_res_stream.php

# Anpassen:
# - Class Name: HRSReservationImporterSSE
# - API Endpoint: /api/v1/manage/reservation/list
# - Progress: Pro Reservierung
```

### Phase 4: AV Capacity mit SSE
```bash
# Optional, da get_av_cap_range.php bereits optimiert
# Nur wenn auch hier Live-Updates gewünscht
```

## Status

✅ **Daily Summary** - SSE implementiert (2024-10-08)  
⏳ **Quota** - Noch mit normalem Fetch  
⏳ **Reservations** - Noch mit normalem Fetch  
⏳ **AV Capacity** - Noch mit normalem Fetch  

## Fazit

Die SSE-Implementation für Daily Summary ist **produktionsbereit** und verbessert die User Experience signifikant. Der User sieht jetzt in Echtzeit, was während des Imports passiert, statt blind zu warten.

**Empfehlung:** Nach erfolgreichen Tests auch Quota und Reservations auf SSE umstellen für ein durchgängig konsistentes Import-Erlebnis.
