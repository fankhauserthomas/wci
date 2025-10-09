#!/bin/bash
# WCI Realistic Volume Estimation

echo "📊 REALISTISCHE WCI VOLUME-SCHÄTZUNG"
echo "===================================="

echo
echo "🏨 Typisches WCI Hotel-System:"
echo "  - 50-200 Zimmer"
echo "  - 5-15 gleichzeitige Nutzer"
echo "  - Heartbeat alle 30s (ping.php)"
echo "  - Sync alle 5 Min (syncTrigger.php)"
echo "  - Reservierungen, Check-ins, etc."

echo
echo "📈 Geschätzte Anfragen pro Tag:"

# Heartbeat: 30s = 120/h = 2880/Tag
HEARTBEAT_DAY=2880
echo "  - Heartbeat (ping.php): $HEARTBEAT_DAY"

# Sync: 5min = 12/h = 288/Tag  
SYNC_DAY=288
echo "  - Sync (syncTrigger.php): $SYNC_DAY"

# Benutzer-Aktivität: 10 Nutzer * 50 Klicks/Tag
USER_DAY=500
echo "  - Benutzer-Aktivität: $USER_DAY"

# System-Checks, APIs, etc.
SYSTEM_DAY=200
echo "  - System/API-Calls: $SYSTEM_DAY"

TOTAL_DAY=$((HEARTBEAT_DAY + SYNC_DAY + USER_DAY + SYSTEM_DAY))
echo "  - GESAMT pro Tag: $TOTAL_DAY"

# Jahr berechnen
TOTAL_YEAR=$((TOTAL_DAY * 365))
echo "  - GESAMT pro Jahr: $(printf "%'d" $TOTAL_YEAR)"

echo
echo "💾 Log-Größen Schätzung:"

# Apache Log Entry ≈ 200 bytes durchschnittlich
BYTES_PER_ENTRY=200
BYTES_YEAR=$((TOTAL_YEAR * BYTES_PER_ENTRY))
KB_YEAR=$((BYTES_YEAR / 1024))
MB_YEAR=$((KB_YEAR / 1024))
GB_YEAR=$((MB_YEAR / 1024))

echo "  - Raw Logs: ${MB_YEAR}MB (${GB_YEAR}GB)"

# Mit Kompression
MB_COMPRESSED=$((MB_YEAR / 10))
echo "  - Komprimiert: ${MB_COMPRESSED}MB"

echo
echo "🗄️ Speicher-Bedarf Übersicht:"
echo "┌─────────────────────┬────────────┬──────────────┐"
echo "│ Zeitraum            │ Raw        │ Komprimiert  │"
echo "├─────────────────────┼────────────┼──────────────┤"
echo "│ 1 Woche             │ $((MB_YEAR / 52))MB        │ $((MB_COMPRESSED / 52))MB           │"
echo "│ 1 Monat             │ $((MB_YEAR / 12))MB        │ $((MB_COMPRESSED / 12))MB           │"
echo "│ 3 Monate            │ $((MB_YEAR / 4))MB       │ $((MB_COMPRESSED / 4))MB          │"
echo "│ 1 Jahr              │ ${MB_YEAR}MB       │ ${MB_COMPRESSED}MB         │"
echo "└─────────────────────┴────────────┴──────────────┘"

echo
echo "✅ EMPFEHLUNG für WCI-System:"

if [ $MB_YEAR -lt 500 ]; then
    echo "  📦 KLEIN: < 500MB/Jahr"
    echo "  ✅ Monatliche Rotation reicht völlig"
    echo "  ✅ 5GB Festplatte für 10 Jahre Archiv"
elif [ $MB_YEAR -lt 2000 ]; then
    echo "  📦 MITTEL: ${MB_YEAR}MB/Jahr"
    echo "  ✅ Wöchentliche Rotation empfohlen"
    echo "  ✅ 20GB Festplatte für 10 Jahre Archiv"
else
    echo "  📦 GROSS: ${MB_YEAR}MB/Jahr"
    echo "  ⚠️ Tägliche Rotation bei High-Traffic"
    echo "  ⚠️ ${GB_YEAR}GB+ für 1-Jahres-Archiv"
fi

echo
echo "🚀 Performance-Impact:"
echo "  ✅ Logging: < 1% CPU overhead"
echo "  ✅ Rotation: 2-3 Sekunden Downtime/Monat"
echo "  ✅ Analyse: Läuft im Hintergrund"

echo
echo "===================================="
