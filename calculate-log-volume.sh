#!/bin/bash
# WCI Data Volume Calculator

echo "🧮 WCI LOG VOLUME CALCULATOR"
echo "============================="

# Aktuelle Daten vom laufenden System
LEMP_DIR="/home/vadmin/lemp"
LOG_FILE="$LEMP_DIR/logs/apache2/access.log"

if [ ! -f "$LOG_FILE" ]; then
    echo "❌ Log-Datei nicht gefunden: $LOG_FILE"
    exit 1
fi

echo "📊 Aktuelle Log-Analyse:"

# Log-Größe
LOG_SIZE=$(stat -c%s "$LOG_FILE")
LOG_SIZE_KB=$((LOG_SIZE / 1024))

# Log-Alter (ungefähr)
LOG_AGE_SEC=$(stat -c %Y "$LOG_FILE")
CURRENT_SEC=$(date +%s)
AGE_SEC=$((CURRENT_SEC - LOG_AGE_SEC))
AGE_HOURS=$((AGE_SEC / 3600))

echo "  - Aktuelle Größe: ${LOG_SIZE_KB}KB"
echo "  - Log-Alter: ca. ${AGE_HOURS} Stunden"

# Zeilen zählen
LINES=$(wc -l < "$LOG_FILE")
echo "  - Anzahl Einträge: $LINES"

if [ $LINES -gt 0 ] && [ $AGE_HOURS -gt 0 ]; then
    # Pro Stunde
    LINES_PER_HOUR=$((LINES / AGE_HOURS))
    KB_PER_HOUR=$((LOG_SIZE_KB / AGE_HOURS))
    
    echo
    echo "⏱️ Durchschnittsraten:"
    echo "  - Anfragen/Stunde: $LINES_PER_HOUR"
    echo "  - KB/Stunde: $KB_PER_HOUR"
    
    # Hochrechnung für 1 Jahr
    HOURS_YEAR=8760  # 365 * 24
    
    LINES_YEAR=$((LINES_PER_HOUR * HOURS_YEAR))
    KB_YEAR=$((KB_PER_HOUR * HOURS_YEAR))
    MB_YEAR=$((KB_YEAR / 1024))
    GB_YEAR=$((MB_YEAR / 1024))
    
    echo
    echo "📈 1-Jahres-Prognose:"
    echo "  - Anfragen: $(printf "%'d" $LINES_YEAR)"
    echo "  - Größe: ${KB_YEAR}KB (${MB_YEAR}MB / ${GB_YEAR}GB)"
    
    # Mit Kompression (ca. 90% Reduktion)
    KB_COMPRESSED=$((KB_YEAR / 10))
    MB_COMPRESSED=$((KB_COMPRESSED / 1024))
    
    echo "  - Komprimiert (gzip): ${MB_COMPRESSED}MB"
    
    echo
    echo "💾 Speicher-Empfehlungen:"
    if [ $GB_YEAR -lt 1 ]; then
        echo "  ✅ GERING: < 1GB pro Jahr"
        echo "  ✅ Standard-Festplatte völlig ausreichend"
    elif [ $GB_YEAR -lt 5 ]; then
        echo "  ✅ MODERAT: ${GB_YEAR}GB pro Jahr"
        echo "  ✅ Problemlos auf jedem System"
    elif [ $GB_YEAR -lt 20 ]; then
        echo "  ⚠️ MITTEL: ${GB_YEAR}GB pro Jahr"
        echo "  ⚠️ Log-Rotation alle 3 Monate empfohlen"
    else
        echo "  ⚠️ HOCH: ${GB_YEAR}GB pro Jahr"
        echo "  ⚠️ Monatliche Log-Rotation empfohlen"
    fi
    
else
    echo "❌ Nicht genug Daten für Prognose"
fi

echo
echo "🔧 Setup-Status:"
docker compose ps | grep -q "web.*Up" && echo "  ✅ Apache Container läuft" || echo "  ❌ Apache Container nicht aktiv"
[ -d "$LEMP_DIR/logs/apache2" ] && echo "  ✅ Log-Verzeichnis existiert" || echo "  ❌ Log-Verzeichnis fehlt"
[ -f "$LOG_FILE" ] && echo "  ✅ Log-Datei wird geschrieben" || echo "  ❌ Log-Datei fehlt"

echo
echo "============================="
