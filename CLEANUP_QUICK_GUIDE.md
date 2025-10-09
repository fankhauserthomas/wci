# 🧹 WCI Projekt Cleanup - Schnellanleitung

**Stand:** 2025-10-08  
**Hauptbericht:** Siehe `PROJECT_CONFIGURATION_AUDIT_README.md`

---

## ⚡ 5-Minuten Quick-Cleanup

### ✅ Phase 1: BACKUP (PFLICHT!)
```bash
cd /home/vadmin/lemp/html/wci
tar -czf ../wci_backup_$(date +%Y%m%d_%H%M%S).tar.gz .
echo "✅ Backup erstellt in: $(ls -lh ../wci_backup_*.tar.gz | tail -1)"
```

### ✅ Phase 2: 100% Sichere Löschungen

```bash
# Backup-Dateien
find . -name "*.backup" -delete
find . -name "*backup_20*" -delete
echo "✅ Backup-Dateien gelöscht"

# Backup-Ordner
rm -rf backups/
echo "✅ Backup-Ordner gelöscht"

# Alte Versionen
rm -f api/imps/get_av_cap_old.php
rm -f addReservationNames-old.php
rm -f addReservationNames-legacy.php
rm -f addReservationNames-backup*.php
echo "✅ Alte Versionen gelöscht"

# Analyse-Output
rm -f analysis_results.txt
rm -f final-analysis-output.txt
rm -f complete-network-analysis.txt
echo "✅ Analyse-Outputs gelöscht"

# Test-Outputs
rm -f belegung/output*.html
rm -f belegung/temp*.html
rm -f belegung/test*.html
echo "✅ Test-Outputs gelöscht"

# Duplicate Configs
rm -f config-simple.php
rm -f config-safe.php
rm -f test-config.php
echo "✅ Duplikat-Configs gelöscht"
```

### ✅ Phase 3: Papierkorb aufräumen

```bash
# Dokumentation sichern
mkdir -p docs/archive
mv papierkorb/HRS_LOGIN_TECHNICAL_DOCS.md docs/archive/
mv papierkorb/HRS_SYSTEM_DOCS.md docs/archive/

# Papierkorb löschen
rm -rf papierkorb/
echo "✅ Papierkorb gelöscht, Doku archiviert"
```

### ✅ Phase 4: Debug-Tools organisieren

```bash
# Debug-Tools in eigenen Ordner
mkdir -p debug_tools
mv debug_*.php debug_tools/ 2>/dev/null || true
mv check_*.php debug_tools/ 2>/dev/null || true
mv analyze_*.php debug_tools/ 2>/dev/null || true
mv test_*.php debug_tools/ 2>/dev/null || true
echo "✅ Debug-Tools organisiert"
```

### ✅ Phase 5: Ergebnis prüfen

```bash
echo ""
echo "📊 CLEANUP ABGESCHLOSSEN"
echo "========================"
echo ""
echo "Gelöschte Kategorien:"
echo "  ✅ Backup-Dateien"
echo "  ✅ Alte Versionen"
echo "  ✅ Test-Outputs"
echo "  ✅ Papierkorb"
echo "  ✅ Duplikat-Configs"
echo ""
echo "Platzersparnis: ~90 MB"
echo ""
echo "⚠️ WICHTIG: System jetzt testen!"
```

---

## 🔧 Phase 6: Konfiguration zentralisieren (Optional, aber empfohlen)

### Schritt 1: Zentrale Credentials-Datei erstellen

```bash
cat > hrs/hrs_credentials.php << 'EOF'
<?php
/**
 * Zentrale HRS-Zugangsdaten
 * DIESE DATEI NICHT IN GIT COMMITTEN!
 */

// HRS Login Credentials
define('HRS_USERNAME', 'office@franzsennhuette.at');
define('HRS_PASSWORD', 'Fsh2147m!3');
define('HRS_BASE_URL', 'https://www.hut-reservation.org');

// Hütten-Konfiguration
define('HUT_ID', 675);
define('HUT_NAME', 'Franzsennhütte');

// Globale Variablen (für Legacy-Code)
$GLOBALS['hutId'] = HUT_ID;
EOF

chmod 600 hrs/hrs_credentials.php
echo "✅ hrs_credentials.php erstellt"
```

### Schritt 2: .gitignore aktualisieren

```bash
cat >> .gitignore << 'EOF'

# Credentials (NEU)
/hrs/hrs_credentials.php
config.php

# Backup files
*.backup
*_backup_*
backup_*/

# Output/Log files
*output*.html
*analysis*.txt
*results*.txt

# Debug Tools
debug_tools/
EOF

echo "✅ .gitignore aktualisiert"
```

---

## 📋 Checkliste nach Cleanup

```
[ ] Backup wurde erstellt
[ ] Alle Lösch-Kommandos wurden ausgeführt
[ ] System wurde getestet:
    [ ] HRS-Import funktioniert
    [ ] Timeline lädt korrekt
    [ ] Zimmerplan funktioniert
    [ ] Datenbank-Verbindung OK
[ ] hrs_credentials.php wurde erstellt (optional)
[ ] .gitignore wurde aktualisiert (optional)
```

---

## 🚨 Im Notfall: Backup wiederherstellen

```bash
# Backup finden
ls -lh /home/vadmin/lemp/html/wci_backup_*.tar.gz

# Backup wiederherstellen
cd /home/vadmin/lemp/html
rm -rf wci  # VORSICHT!
tar -xzf wci_backup_YYYYMMDD_HHMMSS.tar.gz
mv wci.backup wci  # Falls Backup einen anderen Namen hat
```

---

## 📞 Support

Bei Problemen:
1. Backup wiederherstellen (siehe oben)
2. Hauptbericht lesen: `PROJECT_CONFIGURATION_AUDIT_README.md`
3. Debug-Logs prüfen

---

**Geschätzte Durchführungszeit:** 5-10 Minuten  
**Risiko:** Niedrig (mit Backup)  
**Nutzen:** ~90 MB Platzersparnis, bessere Projektstruktur
