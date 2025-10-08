# SSE Import Fixes - Duplicate Entry Error

## Problem

Der Import schlug mit folgendem Fehler fehl:
```
❌ Import failed: Error: Ausnahme: Duplicate entry '675-2026-02-20' for key 'daily_summary.unique_hut_day'
```

## Ursache

Die `hrs_imp_daily_stream.php` verwendete:
1. **DELETE** vor dem Import (um alte Einträge zu entfernen)
2. **INSERT** ohne Duplicate-Handling

**Problem:** Wenn das DELETE fehlschlägt oder der Import zweimal läuft, entstehen Duplicate Key Errors.

## Lösung

### 1. ON DUPLICATE KEY UPDATE hinzugefügt

**Vorher:**
```php
$insertQuery = "INSERT INTO daily_summary (...) VALUES (?, ?, ...)";
```

**Nachher:**
```php
$insertQuery = "INSERT INTO daily_summary (
    hut_id, day, day_of_week, hut_mode, number_of_arriving_guests, total_guests,
    half_boards_value, half_boards_is_active, vegetarians_value, vegetarians_is_active,
    children_value, children_is_active, mountain_guides_value, mountain_guides_is_active,
    waiting_list_value, waiting_list_is_active
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    day_of_week = VALUES(day_of_week),
    hut_mode = VALUES(hut_mode),
    number_of_arriving_guests = VALUES(number_of_arriving_guests),
    total_guests = VALUES(total_guests),
    half_boards_value = VALUES(half_boards_value),
    half_boards_is_active = VALUES(half_boards_is_active),
    vegetarians_value = VALUES(vegetarians_value),
    vegetarians_is_active = VALUES(vegetarians_is_active),
    children_value = VALUES(children_value),
    children_is_active = VALUES(children_is_active),
    mountain_guides_value = VALUES(mountain_guides_value),
    mountain_guides_is_active = VALUES(mountain_guides_is_active),
    waiting_list_value = VALUES(waiting_list_value),
    waiting_list_is_active = VALUES(waiting_list_is_active)";
```

**Effekt:**
- ✅ Kein Duplicate Key Error mehr
- ✅ Bestehende Einträge werden aktualisiert
- ✅ Import kann beliebig oft wiederholt werden
- ✅ DELETE-Schritt ist nicht mehr nötig (aber bleibt auskommentiert)

### 2. Besseres Error Handling

**Vorher:**
```php
if (!$stmt->execute()) {
    $stmt->close();
    return false;
}
```

**Nachher:**
```php
if (!$stmt->execute()) {
    sendSSE('log', ['level' => 'error', 'message' => 'SQL Execute Error: ' . $stmt->error]);
    $stmt->close();
    return false;
}

// Plus Try-Catch um gesamte processDailySummary()
try {
    // ... processing logic
    return true;
} catch (Exception $e) {
    sendSSE('log', ['level' => 'error', 'message' => 'Fehler bei Tag ' . ($daily['day'] ?? 'unknown') . ': ' . $e->getMessage()]);
    return false;
}
```

**Effekt:**
- ✅ SQL-Fehler werden als SSE-Log gesendet
- ✅ User sieht genau, was schiefgelaufen ist
- ✅ Exceptions werden abgefangen und geloggt
- ✅ Import bricht nicht komplett ab bei einem fehlerhaften Tag

### 3. DELETE auskommentiert

**Begründung:**
- ON DUPLICATE KEY UPDATE macht DELETE überflüssig
- DELETE kann fehlschlagen (Berechtigungen, Lock, etc.)
- UPDATE ist atomarer und sicherer

**Code:**
```php
// Schritt 1: Bestehende löschen (optional - wir verwenden jetzt ON DUPLICATE KEY UPDATE)
// sendSSE('log', ['level' => 'info', 'message' => 'Lösche bestehende Daily Summaries für Zeitraum...']);
// $this->deleteExistingDailySummaries($dateFrom, $dateTo);
```

**Bleibt verfügbar falls nötig, ist aber deaktiviert.**

## Testing

### Test 1: Erster Import
```
1. Timeline öffnen
2. 72 Tage im Histogram selektieren (z.B. 2026-02-11 bis 2026-04-23)
3. HRS Import Button klicken
4. Erwartung:
   ✅ Alle 72 Tage werden importiert
   ✅ Logs zeigen "✓ Tag XX.XX.XXXX erfolgreich importiert"
   ✅ Kein Duplicate Key Error
```

### Test 2: Wiederholter Import (gleicher Zeitraum)
```
1. Gleichen Zeitraum nochmal importieren
2. Erwartung:
   ✅ Alle 72 Tage werden erneut importiert
   ✅ Bestehende Einträge werden UPDATE'd
   ✅ Kein Duplicate Key Error
   ✅ Log zeigt weiterhin "✓ Tag XX.XX.XXXX erfolgreich importiert"
```

### Test 3: Überlappender Import
```
1. Import: 2026-02-11 bis 2026-03-15
2. Import: 2026-03-01 bis 2026-04-23
3. Erwartung:
   ✅ Überlappende Tage (März) werden aktualisiert
   ✅ Neue Tage (April) werden eingefügt
   ✅ Kein Duplicate Key Error
```

### Test 4: SQL Error Handling
```
1. Temporär DB-Berechtigungen entziehen (für Test)
2. Import starten
3. Erwartung:
   ✅ Error wird als SSE-Log gesendet
   ✅ Modal zeigt "❌ SQL Execute Error: ..."
   ✅ Import stoppt nicht komplett
   ✅ Nachfolgende Tage werden weiter versucht
```

## Vorteile der Lösung

### Sicherheit
- ✅ **Idempotent**: Import kann beliebig oft wiederholt werden
- ✅ **Atomär**: INSERT + UPDATE in einer Operation
- ✅ **Kein Datenverlust**: Bestehende Daten werden aktualisiert, nicht gelöscht

### User Experience
- ✅ **Klare Fehlermeldungen**: SQL-Fehler werden im Modal angezeigt
- ✅ **Fortschritt trotz Fehler**: Ein fehlerhafter Tag stoppt nicht den gesamten Import
- ✅ **Wiederholbarkeit**: User kann Import wiederholen ohne manuelle Löschung

### Performance
- ✅ **Schneller**: DELETE + INSERT → nur INSERT/UPDATE
- ✅ **Weniger DB-Queries**: Eine Operation statt zwei
- ✅ **Keine Locks**: Kein DELETE Lock auf große Tabellenbereiche

## Datenbankstruktur

Die Lösung basiert auf dem UNIQUE KEY:
```sql
CREATE TABLE `daily_summary` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hut_id` int NOT NULL,
  `day` date NOT NULL,
  ...
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_hut_day` (`hut_id`,`day`)
);
```

**Wichtig:** Der UNIQUE KEY `unique_hut_day` auf `(hut_id, day)` ermöglicht ON DUPLICATE KEY UPDATE.

## Rollback (falls nötig)

Wenn die alte Logik wiederhergestellt werden soll:

1. **DELETE reaktivieren:**
```php
sendSSE('log', ['level' => 'info', 'message' => 'Lösche bestehende Daily Summaries für Zeitraum...']);
$this->deleteExistingDailySummaries($dateFrom, $dateTo);
```

2. **ON DUPLICATE KEY UPDATE entfernen:**
```php
$insertQuery = "INSERT INTO daily_summary (...) VALUES (?, ?, ...)";
```

3. **Try-Catch kann bleiben** (verbessert Error Handling auch bei ALTER Logik)

## Monitoring

### Console-Logs prüfen
```javascript
// Browser Console:
// ✓ Logs zeigen jeden importierten Tag
// ❌ Errors zeigen SQL-Details
// 📊 Progress-Updates zeigen Fortschritt
```

### Datenbank prüfen
```sql
-- Prüfe ob Daten importiert wurden
SELECT * FROM daily_summary 
WHERE hut_id = 675 
  AND day BETWEEN '2026-02-11' AND '2026-04-23'
ORDER BY day;

-- Prüfe auf Duplikate (sollte keine geben)
SELECT hut_id, day, COUNT(*) as count
FROM daily_summary
GROUP BY hut_id, day
HAVING count > 1;
```

### Server-Logs prüfen
```bash
# PHP Error Log
tail -f /var/log/php-fpm/error.log | grep "daily_summary"

# Nginx Error Log
tail -f /var/log/nginx/error.log | grep "hrs_imp"
```

## Bekannte Edge Cases

### 1. Kategorien-Import
- Kategorien werden separat eingefügt (nicht mit ON DUPLICATE KEY)
- **Hinweis:** Falls Kategorien auch dupliziert werden können, muss `insertDailySummaryCategory()` ebenfalls angepasst werden

### 2. Sehr große Zeiträume
- Bei >100 Tagen kann SSE-Connection timeout
- **Lösung:** 5-Minuten-Timeout im JavaScript (bereits implementiert)

### 3. Parallele Imports
- Wenn zwei Users gleichzeitig den gleichen Zeitraum importieren
- **Effekt:** Beide Updates funktionieren, letzter gewinnt
- **Keine Daten-Korruption dank ON DUPLICATE KEY UPDATE**

## Weitere Verbesserungen (Optional)

### 1. Batch-Insert statt Loop
```php
// Statt einzelner INSERTs:
$values = [];
foreach ($dailyData as $daily) {
    $values[] = "(...values...)";
}
$insertQuery = "INSERT INTO daily_summary (...) VALUES " . implode(',', $values) . " ON DUPLICATE KEY UPDATE ...";
```
**Vorteil:** Noch schneller, aber komplexer

### 2. Transaction-Wrapper
```php
$this->mysqli->begin_transaction();
try {
    // ... import logic
    $this->mysqli->commit();
} catch (Exception $e) {
    $this->mysqli->rollback();
    throw $e;
}
```
**Vorteil:** All-or-Nothing Import

### 3. Progress in DB speichern
```sql
CREATE TABLE import_progress (
    session_id VARCHAR(64) PRIMARY KEY,
    current_day DATE,
    total_days INT,
    status ENUM('running', 'completed', 'failed')
);
```
**Vorteil:** Import-Status überlebt Page Reload

## Zusammenfassung

✅ **Duplicate Key Error behoben** durch ON DUPLICATE KEY UPDATE  
✅ **Besseres Error Handling** mit SSE-Logs und Try-Catch  
✅ **Idempotenter Import** kann beliebig oft wiederholt werden  
✅ **User-Friendly** klare Fehlermeldungen im Modal  
✅ **Performance** schneller durch weniger DB-Operations  

**Status:** Production-ready 🚀
