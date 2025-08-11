#!/bin/bash
# WCI Log Rotation System für 1-Jahres-Archivierung

LEMP_DIR="/home/vadmin/lemp"
LOG_DIR="$LEMP_DIR/logs/apache2"
ARCHIVE_DIR="$LEMP_DIR/logs/archive"

echo "=== WCI LOG ROTATION ==="
echo "Date: $(date)"

# Archive-Ordner erstellen
mkdir -p "$ARCHIVE_DIR"

# Aktuelle Log-Größe prüfen
if [ -f "$LOG_DIR/access.log" ]; then
    CURRENT_SIZE=$(stat -c%s "$LOG_DIR/access.log")
    SIZE_MB=$((CURRENT_SIZE / 1024 / 1024))
    
    echo "📊 Aktuelle Log-Größe: ${SIZE_MB}MB"
    
    # Rotation wenn > 100MB
    if [ $SIZE_MB -gt 100 ]; then
        ARCHIVE_NAME="access_$(date +%Y%m%d_%H%M%S).log"
        
        echo "🔄 Rotiere Log-Datei..."
        
        # Container kurz stoppen für saubere Rotation
        cd "$LEMP_DIR"
        docker compose stop web
        
        # Log archivieren und komprimieren
        mv "$LOG_DIR/access.log" "$ARCHIVE_DIR/$ARCHIVE_NAME"
        gzip "$ARCHIVE_DIR/$ARCHIVE_NAME"
        
        # Neue leere Log-Datei erstellen
        touch "$LOG_DIR/access.log"
        chown root:root "$LOG_DIR/access.log"
        
        # Container starten
        docker compose start web
        
        echo "✅ Log rotiert: $ARCHIVE_NAME.gz"
    else
        echo "ℹ️ Log-Rotation nicht nötig (< 100MB)"
    fi
else
    echo "❌ Keine access.log gefunden"
fi

echo
echo "📁 Archive-Status:"
ls -lah "$ARCHIVE_DIR"/ 2>/dev/null || echo "Kein Archiv vorhanden"

echo
echo "💾 Gesamt-Archiv-Größe:"
if [ -d "$ARCHIVE_DIR" ]; then
    du -sh "$ARCHIVE_DIR"
else
    echo "0 bytes"
fi

echo
echo "=== LOG ROTATION COMPLETE ==="
