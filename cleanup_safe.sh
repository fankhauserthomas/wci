#!/bin/bash
################################################################################
# WCI Project Cleanup Script (SAFE MODE)
# 
# Verschiebt redundante Dateien in Archiv-Ordner statt sie zu löschen
# Basiert auf: PROJECT_CONFIGURATION_AUDIT_README.md (2025-10-09)
#
# Verwendung:
#   chmod +x cleanup_safe.sh
#   ./cleanup_safe.sh
#
# Nach erfolgreichem Test (1-2 Tage):
#   rm -rf _CLEANUP_ARCHIVE_20251009/
#
################################################################################

set -e  # Exit on error

# Farben für Output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Archiv-Ordner mit Datum
ARCHIVE_DIR="_CLEANUP_ARCHIVE_20251009"
LOG_FILE="${ARCHIVE_DIR}/cleanup.log"

# Counter
MOVED_COUNT=0
SKIPPED_COUNT=0
ERROR_COUNT=0

################################################################################
# Funktionen
################################################################################

print_header() {
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  WCI PROJECT CLEANUP (SAFE MODE)${NC}"
    echo -e "${BLUE}  $(date '+%Y-%m-%d %H:%M:%S')${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
}

create_archive() {
    if [ ! -d "$ARCHIVE_DIR" ]; then
        echo -e "${GREEN}✓${NC} Erstelle Archiv-Ordner: ${ARCHIVE_DIR}"
        mkdir -p "$ARCHIVE_DIR"
        echo "WCI Cleanup Log - $(date)" > "$LOG_FILE"
        echo "==========================================" >> "$LOG_FILE"
        echo "" >> "$LOG_FILE"
    fi
}

safe_move() {
    local file="$1"
    local category="$2"
    
    if [ -f "$file" ] || [ -d "$file" ]; then
        # Erstelle Unterordner im Archiv für Kategorie
        local target_dir="${ARCHIVE_DIR}/${category}"
        mkdir -p "$target_dir"
        
        # Bewege Datei/Ordner
        local filename=$(basename "$file")
        local target="${target_dir}/${filename}"
        
        echo -e "  ${YELLOW}→${NC} ${file} → ${target_dir}/"
        mv "$file" "$target"
        
        # Log
        echo "[$(date '+%H:%M:%S')] MOVED: $file → $target" >> "$LOG_FILE"
        ((MOVED_COUNT++))
    else
        echo -e "  ${BLUE}⊘${NC} ${file} (nicht gefunden, übersprungen)"
        ((SKIPPED_COUNT++))
    fi
}

safe_move_pattern() {
    local pattern="$1"
    local category="$2"
    local description="$3"
    
    echo -e "${GREEN}▶${NC} ${description}"
    
    local found=0
    for file in $pattern; do
        if [ -e "$file" ]; then
            safe_move "$file" "$category"
            found=1
        fi
    done
    
    if [ $found -eq 0 ]; then
        echo -e "  ${BLUE}⊘${NC} Keine Dateien gefunden für: $pattern"
        ((SKIPPED_COUNT++))
    fi
}

################################################################################
# MAIN SCRIPT
################################################################################

print_header

# Prüfe ob wir im richtigen Verzeichnis sind
if [ ! -f "config.php" ] || [ ! -d "hrs" ]; then
    echo -e "${RED}✗ FEHLER: Falsches Verzeichnis!${NC}"
    echo "Bitte im WCI Root-Verzeichnis ausführen (/home/vadmin/lemp/html/wci)"
    exit 1
fi

echo -e "${YELLOW}⚠ WICHTIG: Dieses Script verschiebt Dateien in: ${ARCHIVE_DIR}${NC}"
echo -e "${YELLOW}⚠ Git sollte bereits committed und gepusht sein!${NC}"
echo ""
read -p "Fortfahren? [j/N] " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Jj]$ ]]; then
    echo "Abgebrochen."
    exit 0
fi

create_archive

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE} PHASE 1: Config-Duplikate${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

safe_move "config-simple.php" "01_config_duplicates"
safe_move "config-safe.php" "01_config_duplicates"
safe_move "test-config.php" "01_config_duplicates"
safe_move "tests/config-simple.php" "01_config_duplicates"

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE} PHASE 2: Backup-Dateien${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

safe_move_pattern "papierkorb/*backup*.php" "02_backups" "Papierkorb-Backups"
safe_move_pattern "papierkorb/*backup_*.php" "02_backups" "Datierte Papierkorb-Backups"
safe_move_pattern "belegung/*backup_*.php" "02_backups" "Belegungs-Backups"
safe_move "belegung/belegung_tab_backup_20250829_105953.php" "02_backups"
safe_move "backups" "02_backups"
safe_move "reservierungen/trash" "02_backups"
safe_move_pattern "css/*backup*.css" "02_backups" "CSS-Backups"
safe_move "test_js_backup.html" "02_backups"

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE} PHASE 3: Alte API-Versionen${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

safe_move "api/imps/get_av_cap_old.php" "03_old_versions"
safe_move "addReservationNames-old.php" "03_old_versions"
safe_move "addReservationNames-legacy.php" "03_old_versions"
safe_move "addReservationNames-backup-old.php" "03_old_versions"
safe_move "addReservationNames-backup.php" "03_old_versions"

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE} PHASE 4: Output/Log-Dateien${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

safe_move "analysis_results.txt" "04_outputs"
safe_move "final-analysis-output.txt" "04_outputs"
safe_move "complete-network-analysis.txt" "04_outputs"
safe_move_pattern "belegung/output*.html" "04_outputs" "Belegungs-Outputs"
safe_move_pattern "belegung/temp*.html" "04_outputs" "Temp-Outputs"
safe_move_pattern "belegung/test*.html" "04_outputs" "Test-Outputs"

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE} PHASE 5: Shell-Scripts (Analyse)${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

safe_move "complete-network-analysis.sh" "05_scripts"
safe_move "final-ultra-analysis.sh" "05_scripts"
safe_move "estimate-volume.sh" "05_scripts"
safe_move "calculate-log-volume.sh" "05_scripts"

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE} PHASE 6: Papierkorb (Optional - auskommentiert)${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

echo -e "${YELLOW}⚠ Papierkorb-Verschiebung ist auskommentiert!${NC}"
echo -e "${YELLOW}⚠ Wenn du sicher bist, aktiviere die Zeile im Script.${NC}"
echo ""

# AUSKOMMENTIERT - NUR AKTIVIEREN WENN SICHER!
# echo -e "${GREEN}▶${NC} Verschiebe kompletten Papierkorb..."
# if [ -d "papierkorb" ]; then
#     # Dokumentation vorher retten
#     if [ -f "papierkorb/HRS_LOGIN_TECHNICAL_DOCS.md" ]; then
#         mkdir -p docs/archive
#         mv papierkorb/*.md docs/archive/ 2>/dev/null || true
#         echo "  ${GREEN}✓${NC} Markdown-Docs nach docs/archive/ verschoben"
#     fi
#     
#     safe_move "papierkorb" "06_papierkorb"
# else
#     echo -e "  ${BLUE}⊘${NC} Papierkorb-Ordner nicht gefunden"
#     ((SKIPPED_COUNT++))
# fi

################################################################################
# ZUSAMMENFASSUNG
################################################################################

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE} CLEANUP ABGESCHLOSSEN${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

echo -e "${GREEN}✓ Verschoben:${NC}    ${MOVED_COUNT} Dateien/Ordner"
echo -e "${BLUE}⊘ Übersprungen:${NC} ${SKIPPED_COUNT} (nicht gefunden)"
if [ $ERROR_COUNT -gt 0 ]; then
    echo -e "${RED}✗ Fehler:${NC}       ${ERROR_COUNT}"
fi

echo ""
echo -e "${GREEN}Archiv-Ordner:${NC} ${ARCHIVE_DIR}/"
echo -e "${GREEN}Log-Datei:${NC}     ${LOG_FILE}"

# Log-Statistik
echo "" >> "$LOG_FILE"
echo "==========================================" >> "$LOG_FILE"
echo "SUMMARY:" >> "$LOG_FILE"
echo "  Moved:    $MOVED_COUNT" >> "$LOG_FILE"
echo "  Skipped:  $SKIPPED_COUNT" >> "$LOG_FILE"
echo "  Errors:   $ERROR_COUNT" >> "$LOG_FILE"
echo "==========================================" >> "$LOG_FILE"

# Größe des Archivs anzeigen
if [ -d "$ARCHIVE_DIR" ]; then
    ARCHIVE_SIZE=$(du -sh "$ARCHIVE_DIR" | cut -f1)
    echo -e "${GREEN}Archiv-Größe:${NC}  ${ARCHIVE_SIZE}"
fi

echo ""
echo -e "${YELLOW}════════════════════════════════════════════════════════════${NC}"
echo -e "${YELLOW} NÄCHSTE SCHRITTE:${NC}"
echo -e "${YELLOW}════════════════════════════════════════════════════════════${NC}"
echo ""
echo "1. System testen (HRS-Import, Zimmerplan, HP-DB, etc.)"
echo "2. Bei Problemen: Dateien zurückkopieren aus ${ARCHIVE_DIR}/"
echo "3. Nach 1-2 Tagen erfolgreicher Tests:"
echo "   rm -rf ${ARCHIVE_DIR}/"
echo ""
echo -e "${GREEN}Wiederherstellung bei Bedarf:${NC}"
echo "   cp -r ${ARCHIVE_DIR}/01_config_duplicates/* ."
echo "   # oder einzelne Dateien:"
echo "   cp ${ARCHIVE_DIR}/01_config_duplicates/config-simple.php ."
echo ""

# Erstelle Wiederherstellungs-Script
RESTORE_SCRIPT="${ARCHIVE_DIR}/restore_all.sh"
cat > "$RESTORE_SCRIPT" << 'RESTORE_EOF'
#!/bin/bash
# Wiederherstellungs-Script
# WARNUNG: Kopiert ALLE Dateien zurück!

echo "WARNUNG: Dies stellt ALLE verschobenen Dateien wieder her!"
read -p "Wirklich fortfahren? [j/N] " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Jj]$ ]]; then
    echo "Abgebrochen."
    exit 0
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

for category_dir in "$SCRIPT_DIR"/*/; do
    if [ -d "$category_dir" ]; then
        echo "Stelle wieder her: $(basename "$category_dir")"
        cp -rv "$category_dir"* . 2>/dev/null || true
    fi
done

echo "Wiederherstellung abgeschlossen!"
RESTORE_EOF

chmod +x "$RESTORE_SCRIPT"
echo -e "${GREEN}✓ Wiederherstellungs-Script erstellt:${NC} ${RESTORE_SCRIPT}"

echo ""
echo -e "${GREEN}✓ Cleanup erfolgreich abgeschlossen!${NC}"
echo ""
