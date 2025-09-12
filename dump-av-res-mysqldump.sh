#!/bin/bash
# dump-av-res-mysqldump.sh - 100% identische Kopie mit mysqldump
# Erstellt eine perfekte 1:1 Kopie der Tabelle AV-Res von lokal zu remote

LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')] [AV-RES-MYSQLDUMP]"

# DB Konfiguration
LOCAL_HOST="localhost"
LOCAL_USER="root"
LOCAL_PASS='Fsh2147m!1'
LOCAL_DB="booking_franzsen"

REMOTE_HOST="booking.franzsennhuette.at"
REMOTE_USER="booking_franzsen"
REMOTE_PASS='~2Y@76'
REMOTE_DB="booking_franzsen"

TEMP_DUMP="/tmp/av-res-dump-$(date '+%Y%m%d_%H%M%S').sql"
BACKUP_DUMP="/tmp/av-res-backup-$(date '+%Y%m%d_%H%M%S').sql"

echo "$LOG_PREFIX Starte mysqldump-basierte AV-Res Kopie"

# 1. Teste lokale DB Verbindung
echo "$LOG_PREFIX Teste lokale DB Verbindung..."
mysql -h"$LOCAL_HOST" -u"$LOCAL_USER" -p"$LOCAL_PASS" -D"$LOCAL_DB" -e "SELECT 1" 2>/dev/null
if [ $? -ne 0 ]; then
    echo "$LOG_PREFIX ❌ FEHLER: Lokale DB Verbindung fehlgeschlagen"
    echo "$LOG_PREFIX Prüfe Verbindung zu $LOCAL_HOST als $LOCAL_USER"
    exit 1
fi
echo "$LOG_PREFIX ✅ Lokale DB Verbindung erfolgreich"

# 2. Teste Remote DB Verbindung
echo "$LOG_PREFIX Teste Remote DB Verbindung..."
mysql -h"$REMOTE_HOST" -u"$REMOTE_USER" -p"$REMOTE_PASS" -D"$REMOTE_DB" -e "SELECT 1" 2>/dev/null
if [ $? -ne 0 ]; then
    echo "$LOG_PREFIX ❌ FEHLER: Remote DB Verbindung fehlgeschlagen"
    echo "$LOG_PREFIX Prüfe Verbindung zu $REMOTE_HOST als $REMOTE_USER"
    exit 1
fi
echo "$LOG_PREFIX ✅ Remote DB Verbindung erfolgreich"

# 3. Backup der Remote-Tabelle erstellen
echo "$LOG_PREFIX Erstelle Backup der Remote-Tabelle..."
mysqldump --single-transaction --routines --triggers \
  -h"$REMOTE_HOST" -u"$REMOTE_USER" -p"$REMOTE_PASS" \
  "$REMOTE_DB" "AV-Res" > "$BACKUP_DUMP" 2>/tmp/mysqldump_error.log

if [ $? -eq 0 ]; then
    echo "$LOG_PREFIX Remote-Backup erstellt: $BACKUP_DUMP"
else
    echo "$LOG_PREFIX WARNUNG: Remote-Backup fehlgeschlagen"
    echo "$LOG_PREFIX Fehlerdetails: $(cat /tmp/mysqldump_error.log)"
fi

# 4. Dump der lokalen Tabelle erstellen (mit kompletter Struktur)
echo "$LOG_PREFIX Erstelle Dump der lokalen AV-Res Tabelle..."
mysqldump --single-transaction --routines --triggers --add-drop-table \
  -h"$LOCAL_HOST" -u"$LOCAL_USER" -p"$LOCAL_PASS" \
  "$LOCAL_DB" "AV-Res" > "$TEMP_DUMP" 2>/tmp/mysqldump_local_error.log

if [ $? -ne 0 ]; then
    echo "$LOG_PREFIX ❌ FEHLER: Lokaler Dump fehlgeschlagen"
    echo "$LOG_PREFIX Fehlerdetails: $(cat /tmp/mysqldump_local_error.log)"
    exit 1
fi

echo "$LOG_PREFIX Lokaler Dump erstellt: $TEMP_DUMP"

# 5. Anzahl Datensätze prüfen (vor Import)
LOCAL_COUNT=$(mysql -h"$LOCAL_HOST" -u"$LOCAL_USER" -p"$LOCAL_PASS" -D"$LOCAL_DB" -se "SELECT COUNT(*) FROM \`AV-Res\`" 2>/dev/null)
echo "$LOG_PREFIX Lokale Datensätze: $LOCAL_COUNT"

# 6. Dump in Remote-DB importieren (überschreibt komplett)
echo "$LOG_PREFIX Importiere Dump in Remote-DB..."
mysql -h"$REMOTE_HOST" -u"$REMOTE_USER" -p"$REMOTE_PASS" "$REMOTE_DB" < "$TEMP_DUMP" 2>/tmp/mysql_import_error.log

if [ $? -ne 0 ]; then
    echo "$LOG_PREFIX ❌ FEHLER: Remote-Import fehlgeschlagen"
    echo "$LOG_PREFIX Fehlerdetails: $(cat /tmp/mysql_import_error.log)"
    echo "$LOG_PREFIX Backup verfügbar unter: $BACKUP_DUMP"
    exit 1
fi

# 7. Verifikation
REMOTE_COUNT=$(mysql -h"$REMOTE_HOST" -u"$REMOTE_USER" -p"$REMOTE_PASS" -D"$REMOTE_DB" -se "SELECT COUNT(*) FROM \`AV-Res\`" 2>/dev/null)
echo "$LOG_PREFIX Remote Datensätze nach Import: $REMOTE_COUNT"

if [ "$LOCAL_COUNT" = "$REMOTE_COUNT" ]; then
    echo "$LOG_PREFIX ✅ VERIFIKATION ERFOLGREICH: $LOCAL_COUNT = $REMOTE_COUNT"
    echo "$LOG_PREFIX ✅ 100% KOPIE ABGESCHLOSSEN"
    
    # Cleanup temp file
    rm -f "$TEMP_DUMP"
    echo "$LOG_PREFIX Temporäre Datei gelöscht"
    
else
    echo "$LOG_PREFIX ❌ VERIFIKATION FEHLGESCHLAGEN: $LOCAL_COUNT ≠ $REMOTE_COUNT"
    echo "$LOG_PREFIX Temp-Dump behalten: $TEMP_DUMP"
    echo "$LOG_PREFIX Backup verfügbar: $BACKUP_DUMP"
    exit 1
fi

echo "$LOG_PREFIX Mysqldump-Kopie erfolgreich abgeschlossen"
echo "$LOG_PREFIX Remote-Backup verfügbar: $BACKUP_DUMP"
