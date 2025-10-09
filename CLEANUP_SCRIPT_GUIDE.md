# 🧹 WCI Cleanup - Script Anleitung

**Script:** `cleanup_safe.sh`  
**Erstellt:** 2025-10-09  
**Modus:** SAFE (verschiebt statt löschen)

---

## ⚡ Schnellstart

### 1. Git Commit + Push (ZUERST!)
```bash
cd /home/vadmin/lemp/html/wci
git add -A
git commit -m "Pre-cleanup state: Before removing duplicates (Audit 2025-10-09)"
git push origin main
```

### 2. Cleanup ausführen
```bash
./cleanup_safe.sh
```

### 3. System testen
- ✅ HRS-Import funktioniert?
- ✅ Zimmerplan funktioniert?
- ✅ HP-Arrangements funktionieren?
- ✅ Keine Fehler in Browser-Konsole?

### 4. Nach 1-2 Tagen: Archiv löschen
```bash
# Wenn alles funktioniert:
rm -rf _CLEANUP_ARCHIVE_20251009/
```

---

## 📋 Was macht das Script?

### Phase 1: Config-Duplikate ❌ (4 Dateien)
```
config-simple.php       → _CLEANUP_ARCHIVE_20251009/01_config_duplicates/
config-safe.php         → _CLEANUP_ARCHIVE_20251009/01_config_duplicates/
test-config.php         → _CLEANUP_ARCHIVE_20251009/01_config_duplicates/
tests/config-simple.php → _CLEANUP_ARCHIVE_20251009/01_config_duplicates/
```

### Phase 2: Backup-Dateien 💾 (15+ Dateien)
```
papierkorb/*backup*.php → _CLEANUP_ARCHIVE_20251009/02_backups/
belegung/*backup*.php   → _CLEANUP_ARCHIVE_20251009/02_backups/
backups/                → _CLEANUP_ARCHIVE_20251009/02_backups/
css/*backup*.css        → _CLEANUP_ARCHIVE_20251009/02_backups/
```

### Phase 3: Alte Versionen 🗂️ (5 Dateien)
```
api/imps/get_av_cap_old.php     → _CLEANUP_ARCHIVE_20251009/03_old_versions/
addReservationNames-old.php     → _CLEANUP_ARCHIVE_20251009/03_old_versions/
addReservationNames-legacy.php  → _CLEANUP_ARCHIVE_20251009/03_old_versions/
addReservationNames-backup*.php → _CLEANUP_ARCHIVE_20251009/03_old_versions/
```

### Phase 4: Output/Logs 📄 (10+ Dateien)
```
analysis_results.txt            → _CLEANUP_ARCHIVE_20251009/04_outputs/
complete-network-analysis.txt   → _CLEANUP_ARCHIVE_20251009/04_outputs/
belegung/output*.html           → _CLEANUP_ARCHIVE_20251009/04_outputs/
belegung/test*.html             → _CLEANUP_ARCHIVE_20251009/04_outputs/
```

### Phase 5: Shell-Scripts 🔧 (4 Dateien)
```
complete-network-analysis.sh → _CLEANUP_ARCHIVE_20251009/05_scripts/
estimate-volume.sh           → _CLEANUP_ARCHIVE_20251009/05_scripts/
calculate-log-volume.sh      → _CLEANUP_ARCHIVE_20251009/05_scripts/
final-ultra-analysis.sh      → _CLEANUP_ARCHIVE_20251009/05_scripts/
```

### Phase 6: Papierkorb 🗑️ (37 Dateien)
```
⚠️ AUSKOMMENTIERT im Script!
Wenn du sicher bist, aktiviere im Script:
  papierkorb/ → _CLEANUP_ARCHIVE_20251009/06_papierkorb/
```

---

## 🔄 Wiederherstellung (bei Problemen)

### Einzelne Datei wiederherstellen:
```bash
# Beispiel: config-simple.php zurückholen
cp _CLEANUP_ARCHIVE_20251009/01_config_duplicates/config-simple.php .
```

### Automatische Wiederherstellung (alle Dateien):
```bash
./_CLEANUP_ARCHIVE_20251009/restore_all.sh
```

### Manuell alles zurückkopieren:
```bash
cp -r _CLEANUP_ARCHIVE_20251009/01_config_duplicates/* .
cp -r _CLEANUP_ARCHIVE_20251009/02_backups/* .
# etc...
```

### Git-Rollback (wenn Git committed war):
```bash
git reset --hard HEAD
git clean -fd
```

---

## ✅ Sicherheits-Features des Scripts

### Was wird NICHT verschoben:
- ✅ `/config.php` - Booking-DB Master
- ✅ `/hp-db-config.php` - HP-DB Master (**WICHTIG!**)
- ✅ `/hrs/hrs_login.php` - HRS-Login
- ✅ `/hrs/hrs_imp_*_stream.php` - HRS-Importer
- ✅ `/zp/timeline-unified.js` - Hauptanwendung
- ✅ `/index.php` - Hauptseite
- ✅ Alle produktiven PHP-Dateien

### Sicherheitschecks:
- ✅ Verzeichnis-Check (muss in `/wci/` sein)
- ✅ Bestätigung vor Ausführung (j/N)
- ✅ Dateien werden nur verschoben, nicht gelöscht
- ✅ Detailliertes Log: `_CLEANUP_ARCHIVE_20251009/cleanup.log`
- ✅ Auto-Restore-Script wird erstellt

---

## 📊 Erwartete Ergebnisse

| Phase | Dateien | Größe (ca.) | Kategorie |
|-------|---------|-------------|-----------|
| 1 | 4 | ~4 KB | Config-Duplikate |
| 2 | 15+ | ~20 MB | Backup-Dateien |
| 3 | 5 | ~5 MB | Alte Versionen |
| 4 | 10+ | ~50 MB | Output/Logs |
| 5 | 4 | ~10 KB | Shell-Scripts |
| **6** | **37** | **~15 MB** | **Papierkorb (optional)** |
| **GESAMT** | **~40** | **~75 MB** | |

*Ohne Papierkorb: ~40 Dateien, ~75 MB*  
*Mit Papierkorb: ~77 Dateien, ~90 MB*

---

## ⚠️ Troubleshooting

### Problem: "Falsches Verzeichnis!"
```bash
cd /home/vadmin/lemp/html/wci
./cleanup_safe.sh
```

### Problem: "Permission denied"
```bash
chmod +x cleanup_safe.sh
./cleanup_safe.sh
```

### Problem: Datei nicht gefunden (beim Cleanup)
```
✓ Normal! Das Script überspringt automatisch nicht vorhandene Dateien.
  Siehe: "⊘ ... (nicht gefunden, übersprungen)" im Output
```

### Problem: System funktioniert nicht mehr nach Cleanup
```bash
# Option 1: Automatische Wiederherstellung
./_CLEANUP_ARCHIVE_20251009/restore_all.sh

# Option 2: Einzelne Datei zurück
cp _CLEANUP_ARCHIVE_20251009/01_config_duplicates/config-simple.php .

# Option 3: Git zurücksetzen (wenn committed)
git reset --hard HEAD
```

### Problem: Script hängt
```
Ctrl+C drücken
Prüfe was schon verschoben wurde:
  ls -la _CLEANUP_ARCHIVE_20251009/
Führe restore_all.sh aus wenn nötig
```

---

## 🔍 Log-Datei analysieren

### Log ansehen:
```bash
# Terminal-Output:
cat _CLEANUP_ARCHIVE_20251009/cleanup.log

# Mit Pager:
less _CLEANUP_ARCHIVE_20251009/cleanup.log

# Nur Fehler:
grep ERROR _CLEANUP_ARCHIVE_20251009/cleanup.log

# Anzahl verschobener Dateien:
grep MOVED _CLEANUP_ARCHIVE_20251009/cleanup.log | wc -l
```

### Log-Format:
```
WCI Cleanup Log - Wed Oct  9 14:30:00 2025
==========================================

[14:30:05] MOVED: config-simple.php → _CLEANUP_ARCHIVE_20251009/01_config_duplicates/config-simple.php
[14:30:05] MOVED: config-safe.php → _CLEANUP_ARCHIVE_20251009/01_config_duplicates/config-safe.php
...

==========================================
SUMMARY:
  Moved:    42
  Skipped:  8
  Errors:   0
==========================================
```

---

## 📈 Nach dem Cleanup

### 1. Platzersparnis prüfen:
```bash
du -sh _CLEANUP_ARCHIVE_20251009/
# Erwartung: ~75 MB (ohne Papierkorb)
```

### 2. Was ist noch da:
```bash
# Config-Duplikate weg?
ls -lh config*.php
# Sollte nur noch zeigen: config.php, hp-db-config.php

# Backups weg?
ls backups/ 2>/dev/null
# Sollte "not found" zeigen

# Alte Versionen weg?
ls -lh api/imps/get_av_cap_old.php 2>/dev/null
# Sollte "not found" zeigen
```

### 3. Test-Checkliste (WICHTIG!):

#### Basis-Tests:
- [ ] Hauptseite öffnen: `http://your-server/wci/`
- [ ] Browser-Konsole: Keine 404-Fehler?
- [ ] PHP-Fehler-Log: Keine neuen Fehler?
  ```bash
  tail -f /var/log/nginx/error.log
  # oder
  tail -f /var/log/apache2/error.log
  ```

#### HRS-System:
- [ ] HRS-Import-Seite öffnen
- [ ] Quota-Import starten
- [ ] Reservierungs-Import starten
- [ ] AV-Capacity-Import starten
- [ ] Keine Fehler im SSE-Stream?

#### Zimmerplan:
- [ ] Timeline lädt vollständig
- [ ] Histogram wird angezeigt
- [ ] Reservierungen werden gerendert
- [ ] Datum-Navigation funktioniert

#### HP-System:
- [ ] HP-Arrangements öffnen
- [ ] Daten werden geladen
- [ ] Tisch-Planung funktioniert
- [ ] HP-DB Verbindung OK?

#### Datenbanken:
```bash
# Booking-DB testen:
mysql -h 192.168.15.14 -u root -p booking_franzsen -e "SELECT COUNT(*) FROM \`AV-Res\`;"

# HP-DB testen:
mysql -h 192.168.2.81 -u fsh -p fsh-res -e "SELECT COUNT(*) FROM hp_arrangements;"
```

---

## 🎯 Empfohlener Workflow (3-Tage-Plan)

### **Tag 1: Cleanup & Sofort-Test** (Mittwoch, 9.10.)
```bash
# Morgens (ruhige Zeit):
08:00 - Git Commit + Push
08:05 - ./cleanup_safe.sh ausführen
08:10 - Sofort-Tests durchführen:
        ✓ Hauptseite
        ✓ HRS-Import
        ✓ Zimmerplan
        ✓ HP-System

# Bei Problemen:
./_CLEANUP_ARCHIVE_20251009/restore_all.sh
```

### **Tag 2-3: Monitoring** (Donnerstag, Freitag)
```bash
# System normal verwenden
# Achten auf:
  - Browser-Konsole-Fehler
  - PHP-Fehler-Logs
  - User-Beschwerden
  - Fehlende Funktionen

# Bei Problemen:
  - Einzelne Dateien zurückkopieren
  - Oder komplettes Restore
```

### **Tag 4: Finale Löschung** (Montag, 13.10.)
```bash
# Wenn alles problemlos läuft:
rm -rf _CLEANUP_ARCHIVE_20251009/

# Git Commit:
git add -A
git commit -m "Cleanup completed: Removed archive after successful testing"
git push

# Dokumentation aufräumen (optional):
mv *AUDIT*.md docs/archive/ 2>/dev/null
mv DATABASE_ARCHITECTURE.md docs/archive/ 2>/dev/null
```

---

## 🚀 Papierkorb aktivieren (optional)

Wenn du auch den Papierkorb aufräumen möchtest:

### 1. Script bearbeiten:
```bash
nano cleanup_safe.sh
# oder
code cleanup_safe.sh
```

### 2. Suche diese Zeilen (ca. Zeile 170):
```bash
# AUSKOMMENTIERT - NUR AKTIVIEREN WENN SICHER!
# echo -e "${GREEN}▶${NC} Verschiebe kompletten Papierkorb..."
```

### 3. Entferne die `#` vor den Zeilen:
```bash
# Aktiviert:
echo -e "${GREEN}▶${NC} Verschiebe kompletten Papierkorb..."
if [ -d "papierkorb" ]; then
    # ...
fi
```

### 4. Script erneut ausführen:
```bash
./cleanup_safe.sh
# Nur der Papierkorb wird jetzt verschoben (Rest ist schon im Archiv)
```

---

## 📞 Support & Weitere Infos

### Dokumentation:
- [CLEANUP_QUICK_GUIDE.md](CLEANUP_QUICK_GUIDE.md) - Allgemeine Cleanup-Anleitung
- [PROJECT_CONFIGURATION_AUDIT_README.md](PROJECT_CONFIGURATION_AUDIT_README.md) - Vollständiger Audit
- [AUDIT_SUMMARY.txt](AUDIT_SUMMARY.txt) - Terminal-Übersicht
- [DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md) - DB-Architektur

### Bei Problemen:
1. Log prüfen: `cat _CLEANUP_ARCHIVE_20251009/cleanup.log`
2. Restore-Script: `./_CLEANUP_ARCHIVE_20251009/restore_all.sh`
3. Git-Rollback: `git reset --hard HEAD`

### Script-Features:
- ✅ Farbiger Output (grün = OK, gelb = Info, rot = Fehler)
- ✅ Detailliertes Logging
- ✅ Automatisches Restore-Script
- ✅ Kategorie-basierte Archivierung
- ✅ Fehler-resilient (überspringt fehlende Dateien)

---

## 💡 Tipps

### Dry-Run (Vorher testen ohne Änderungen):
Das Script zeigt beim Ausführen bereits an, was verschoben wird.
Bei "Fortfahren? [j/N]" → "N" drücken = Abbruch ohne Änderungen

### Archiv-Struktur:
```
_CLEANUP_ARCHIVE_20251009/
├── cleanup.log              ← Detailliertes Log
├── restore_all.sh           ← Auto-Restore Script
├── 01_config_duplicates/    ← Config-Duplikate
├── 02_backups/              ← Backup-Dateien
├── 03_old_versions/         ← Alte Versionen
├── 04_outputs/              ← Output/Logs
├── 05_scripts/              ← Shell-Scripts
└── 06_papierkorb/           ← Papierkorb (optional)
```

### Nach erfolgreicher Bereinigung:
Erstelle eine zentrale HRS-Credentials-Datei (siehe Audit-Bericht):
```bash
# /hrs/hrs_credentials.php erstellen
# define('HRS_USERNAME', '...');
# define('HRS_PASSWORD', '...');
# define('HUT_ID', 675);
```

---

**Script erstellt von:** GitHub Copilot AI Assistant  
**Datum:** 2025-10-09  
**Version:** 1.0 (Safe Mode)  
**Getestet:** Nein (noch nicht ausgeführt)

🎉 **Viel Erfolg beim Cleanup!**
