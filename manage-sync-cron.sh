#!/bin/bash
# Script zur manuellen Cron-Installation für Sync

CRON_USER="vadmin"
CRON_LINE="0 * * * * /usr/bin/php /home/vadmin/lemp/html/wci/sync-cron.php >> /home/vadmin/lemp/html/wci/logs/sync.log 2>&1"

echo "🔄 Sync Cron Management Script"
echo "=============================="
echo ""

case "$1" in
    "install")
        echo "📥 Installiere Sync Cron-Job (stündlich)..."
        
        # Prüfe ob bereits vorhanden
        if crontab -l 2>/dev/null | grep -q "sync-cron.php"; then
            echo "⚠️  Sync Cron-Job bereits vorhanden!"
        else
            # Füge zur Crontab hinzu
            (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
            echo "✅ Sync Cron-Job erfolgreich installiert!"
        fi
        ;;
        
    "remove")
        echo "🗑️  Entferne Sync Cron-Job..."
        crontab -l 2>/dev/null | grep -v "sync-cron.php" | crontab -
        echo "✅ Sync Cron-Job erfolgreich entfernt!"
        ;;
        
    "status")
        echo "📊 Cron Status:"
        if crontab -l 2>/dev/null | grep -q "sync-cron.php"; then
            echo "✅ Sync Cron-Job ist AKTIV"
            echo ""
            echo "🕐 Aktuelle Sync-Einträge:"
            crontab -l 2>/dev/null | grep "sync-cron.php"
        else
            echo "❌ Sync Cron-Job ist INAKTIV"
        fi
        ;;
        
    "list")
        echo "📋 Alle Cron-Jobs:"
        crontab -l 2>/dev/null || echo "Keine Cron-Jobs vorhanden"
        ;;
        
    *)
        echo "Verwendung: $0 {install|remove|status|list}"
        echo ""
        echo "install  - Installiert stündlichen Sync Cron-Job"
        echo "remove   - Entfernt Sync Cron-Job"
        echo "status   - Zeigt Status des Sync Cron-Jobs"
        echo "list     - Zeigt alle Cron-Jobs an"
        exit 1
        ;;
esac

echo ""
