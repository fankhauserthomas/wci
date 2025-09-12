#!/bin/bash
# Log-Rotation für sync.log
# Wird täglich um 2:00 Uhr ausgeführt

LOG_FILE="/home/vadmin/lemp/html/wci/logs/sync.log"
LOG_DIR="/home/vadmin/lemp/html/wci/logs"

# Prüfe ob Log existiert und größer als 10MB ist
if [ -f "$LOG_FILE" ] && [ $(stat -c%s "$LOG_FILE") -gt 10485760 ]; then
    echo "$(date): Rotating sync.log ($(du -h $LOG_FILE | cut -f1))"
    
    # Rotiere die Logs (behalte 7 Tage)
    [ -f "$LOG_DIR/sync.log.6" ] && rm "$LOG_DIR/sync.log.6"
    [ -f "$LOG_DIR/sync.log.5" ] && mv "$LOG_DIR/sync.log.5" "$LOG_DIR/sync.log.6"
    [ -f "$LOG_DIR/sync.log.4" ] && mv "$LOG_DIR/sync.log.4" "$LOG_DIR/sync.log.5"
    [ -f "$LOG_DIR/sync.log.3" ] && mv "$LOG_DIR/sync.log.3" "$LOG_DIR/sync.log.4"
    [ -f "$LOG_DIR/sync.log.2" ] && mv "$LOG_DIR/sync.log.2" "$LOG_DIR/sync.log.3"
    [ -f "$LOG_DIR/sync.log.1" ] && mv "$LOG_DIR/sync.log.1" "$LOG_DIR/sync.log.2"
    
    # Komprimiere und archiviere aktuelles Log
    gzip -c "$LOG_FILE" > "$LOG_DIR/sync.log.1.gz"
    
    # Neues leeres Log erstellen
    > "$LOG_FILE"
    
    echo "$(date): Log rotation completed"
else
    echo "$(date): Log rotation not needed ($(du -h $LOG_FILE 2>/dev/null | cut -f1 || echo 'file not found'))"
fi
