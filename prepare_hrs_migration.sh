#!/bin/bash
################################################################################
# HRS Credentials Migration Script
# 
# Automatisiert Phase 1: Vorbereitung der zentralen Credentials-Datei
#
# Verwendung:
#   chmod +x prepare_hrs_migration.sh
#   ./prepare_hrs_migration.sh
#
################################################################################

# Farben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  HRS Credentials Migration - Phase 1: Vorbereitung${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

# Prüfe Verzeichnis
if [ ! -d "hrs" ]; then
    echo -e "${RED}✗ FEHLER: hrs/ Verzeichnis nicht gefunden!${NC}"
    echo "Bitte im WCI Root-Verzeichnis ausführen"
    exit 1
fi

echo -e "${GREEN}▶ Schritt 1: Erstelle zentrale Credentials-Datei${NC}"
echo ""

# 1. Zentrale Config erstellen
if [ -f "hrs/hrs_credentials.php" ]; then
    echo -e "  ${YELLOW}⚠${NC} hrs/hrs_credentials.php existiert bereits"
    read -p "  Überschreiben? [j/N] " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Jj]$ ]]; then
        echo "  ${BLUE}⊘${NC} Übersprungen"
    else
        rm -f hrs/hrs_credentials.php
    fi
fi

if [ ! -f "hrs/hrs_credentials.php" ]; then
    cat > hrs/hrs_credentials.php << 'CREDENTIALS_EOF'
<?php
/**
 * HRS System - Zentrale Konfiguration
 * 
 * Diese Datei enthält ALLE HRS-bezogenen Credentials und Konfigurationen.
 * NIEMALS direkt committen - sollte in .gitignore!
 * 
 * @created 2025-10-09
 * @security CRITICAL - Contains passwords!
 */

// === HRS Login Credentials ===
define('HRS_USERNAME', 'office@franzsennhuette.at');
define('HRS_PASSWORD', 'Fsh2147m!3');
define('HRS_BASE_URL', 'https://www.hut-reservation.org');

// === Hütten-Konfiguration ===
define('HUT_ID', 675);
define('HUT_NAME', 'Franzsennhütte');

// === Legacy-Support (für alten Code) ===
$GLOBALS['hutId'] = HUT_ID;

// === HRS-API Endpoints ===
define('HRS_API_QUOTA', HRS_BASE_URL . '/api/v1/manage/hutQuota');
define('HRS_API_RESERVATION', HRS_BASE_URL . '/api/v1/manage/reservation/list');
define('HRS_API_AVAILABILITY', HRS_BASE_URL . '/getHutAvailability');

// === Helper-Funktionen ===

/**
 * Gibt HRS-Credentials als Array zurück
 */
function getHrsCredentials() {
    return [
        'username' => HRS_USERNAME,
        'password' => HRS_PASSWORD,
        'base_url' => HRS_BASE_URL
    ];
}

/**
 * Gibt Hut-Konfiguration als Array zurück
 */
function getHutConfig() {
    return [
        'id' => HUT_ID,
        'name' => HUT_NAME
    ];
}

/**
 * Validiert, ob Credentials konfiguriert sind
 */
function validateHrsCredentials() {
    if (HRS_PASSWORD === 'HIER_PASSWORT_EINTRAGEN') {
        throw new Exception('HRS Credentials sind nicht konfiguriert! Bitte hrs_credentials.php anpassen.');
    }
    if (HRS_USERNAME === 'HIER_EMAIL_EINTRAGEN') {
        throw new Exception('HRS Username ist nicht konfiguriert! Bitte hrs_credentials.php anpassen.');
    }
    return true;
}
CREDENTIALS_EOF

    chmod 600 hrs/hrs_credentials.php
    echo -e "  ${GREEN}✓${NC} hrs/hrs_credentials.php erstellt (chmod 600)"
else
    echo -e "  ${BLUE}⊘${NC} hrs/hrs_credentials.php bereits vorhanden"
fi

echo ""
echo -e "${GREEN}▶ Schritt 2: Aktualisiere .gitignore${NC}"
echo ""

# 2. .gitignore erweitern
if [ ! -f ".gitignore" ]; then
    echo "# WCI Project" > .gitignore
    echo -e "  ${GREEN}✓${NC} .gitignore erstellt"
fi

if ! grep -q "hrs/hrs_credentials.php" .gitignore 2>/dev/null; then
    cat >> .gitignore << 'GITIGNORE_EOF'

################################################################################
# HRS Credentials (SECURITY - NEVER COMMIT!)
################################################################################
hrs/hrs_credentials.php
GITIGNORE_EOF
    echo -e "  ${GREEN}✓${NC} .gitignore erweitert (hrs/hrs_credentials.php)"
else
    echo -e "  ${BLUE}⊘${NC} .gitignore bereits aktualisiert"
fi

echo ""
echo -e "${GREEN}▶ Schritt 3: Erstelle Template-Datei (für Git)${NC}"
echo ""

# 3. Template erstellen
if [ ! -f "hrs/hrs_credentials.php.template" ]; then
    sed 's/office@franzsennhuette.at/HIER_EMAIL_EINTRAGEN/g; s/Fsh2147m!3/HIER_PASSWORT_EINTRAGEN/g' hrs/hrs_credentials.php > hrs/hrs_credentials.php.template
    echo -e "  ${GREEN}✓${NC} hrs/hrs_credentials.php.template erstellt"
    echo -e "  ${YELLOW}  → Dieses Template KANN ins Git committed werden${NC}"
else
    echo -e "  ${BLUE}⊘${NC} Template bereits vorhanden"
fi

echo ""
echo -e "${GREEN}▶ Schritt 4: Erstelle API-Endpunkt für Frontend${NC}"
echo ""

# 4. API-Endpunkt für Frontend
mkdir -p api
if [ ! -f "api/getHrsConfig.php" ]; then
    cat > api/getHrsConfig.php << 'API_EOF'
<?php
/**
 * API: HRS-Konfiguration für Frontend
 * 
 * Gibt HutID und andere öffentliche HRS-Configs als JSON zurück.
 * KEINE Passwörter/Credentials!
 */

require_once(__DIR__ . '/../hrs/hrs_credentials.php');

header('Content-Type: application/json');
header('Cache-Control: max-age=3600'); // 1 Stunde cachen

echo json_encode([
    'success' => true,
    'hutId' => HUT_ID,
    'hutName' => HUT_NAME,
    'baseUrl' => HRS_BASE_URL
]);
API_EOF
    echo -e "  ${GREEN}✓${NC} api/getHrsConfig.php erstellt"
else
    echo -e "  ${BLUE}⊘${NC} API bereits vorhanden"
fi

echo ""
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Phase 1: Vorbereitung abgeschlossen!${NC}"
echo -e "${BLUE}════════════════════════════════════════════════════════════${NC}"
echo ""

# Zusammenfassung
echo -e "${GREEN}✓ Erstellte Dateien:${NC}"
echo "  - hrs/hrs_credentials.php (NICHT in Git)"
echo "  - hrs/hrs_credentials.php.template (für Git)"
echo "  - api/getHrsConfig.php (API-Endpunkt)"
echo ""
echo -e "${GREEN}✓ Aktualisierte Dateien:${NC}"
echo "  - .gitignore (hrs_credentials.php hinzugefügt)"
echo ""

# Test
echo -e "${YELLOW}════════════════════════════════════════════════════════════${NC}"
echo -e "${YELLOW}  Test: Credentials laden${NC}"
echo -e "${YELLOW}════════════════════════════════════════════════════════════${NC}"
echo ""

php -r "
require 'hrs/hrs_credentials.php';
validateHrsCredentials();
echo '✓ HRS_USERNAME: ' . HRS_USERNAME . PHP_EOL;
echo '✓ HRS_PASSWORD: ' . str_repeat('*', strlen(HRS_PASSWORD)) . PHP_EOL;
echo '✓ HUT_ID: ' . HUT_ID . PHP_EOL;
echo '✓ HUT_NAME: ' . HUT_NAME . PHP_EOL;
echo PHP_EOL;
echo '✓ Validation: OK' . PHP_EOL;
"

echo ""
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  Nächste Schritte (manuell):${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════════${NC}"
echo ""
echo "1. Siehe: HRS_CREDENTIALS_MIGRATION_PLAN.md"
echo ""
echo "Phase 2: HRS-Login migrieren"
echo "  → hrs/hrs_login.php anpassen"
echo ""
echo "Phase 3: HRS-Importer migrieren (5 Dateien)"
echo "  → hrs/hrs_imp_daily.php"
echo "  → hrs/hrs_imp_daily_stream.php"
echo "  → hrs/hrs_imp_quota_stream.php"
echo "  → hrs/hrs_imp_res_stream.php"
echo "  → hrs/hrs_del_quota.php"
echo ""
echo "Phase 4: API-Endpunkte migrieren"
echo "  → api/imps/get_av_cap_range_stream.php"
echo ""
echo "Phase 5: Frontend migrieren"
echo "  → zp/timeline-unified.html"
echo ""
echo "Phase 6: Debug-Tools migrieren"
echo "  → debug_*.php (mehrere Dateien)"
echo ""
echo "Phase 7: config.php erweitern"
echo "  → HUT_ID auch in Master-Config definieren"
echo ""
echo -e "${YELLOW}⚠ WICHTIG: Nach jeder Phase testen!${NC}"
echo ""
echo "Git-Commit empfohlen:"
echo "  git add hrs/hrs_credentials.php.template api/getHrsConfig.php .gitignore"
echo "  git commit -m 'Phase 1: HRS Credentials - Zentrale Config vorbereitet'"
echo ""
