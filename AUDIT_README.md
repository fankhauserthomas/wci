# 📋 WCI Projekt - Audit Dokumentation

> **Stand:** 2025-10-09 | **Version:** 2.0 (mit HP-DB Korrektur)

---

## 🎯 Wichtigste Erkenntnisse

| Problem | Status | Details |
|---------|--------|---------|
| **HRS-Credentials** | 🔴 13 Duplikate | Zentrale Config nötig |
| **Hut ID (675)** | 🔴 50+ Hardcoded | define('HUT_ID', 675) nötig |
| **DB-Configs** | 🟢 2 Master ✅ + 🔴 4 Duplikate | Duplikate löschen |
| **Löschbare Dateien** | 🗑️ 75+ Dateien, ~90 MB | Cleanup möglich |

---

## 📚 Dokumentation (Bitte lesen!)

### 1. **Start hier:** [AUDIT_SUMMARY.txt](AUDIT_SUMMARY.txt)
   Schnelle Terminal-Übersicht mit allen wichtigen Commands

### 2. **Vollständiger Bericht:** [PROJECT_CONFIGURATION_AUDIT_README.md](PROJECT_CONFIGURATION_AUDIT_README.md)
   - 🔍 Detaillierte Analyse aller 3 Punkte
   - 🗑️ Löschbare Dateien (Kategorie A-D)
   - 🔧 Sofort-Maßnahmen & Commands
   - 📎 4 Appendices mit vollständigen Listen

### 3. **Datenbank-Architektur:** [DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md)
   - 🗄️ Zwei separate Datenbanken erklärt
   - 📊 Booking-DB vs. HP-DB
   - 💡 Best Practices & Connection-Pooling

### 4. **Wichtige Korrektur:** [AUDIT_CORRECTION_HP_DB.md](AUDIT_CORRECTION_HP_DB.md)
   - ⚠️ `/hp-db-config.php` ist PRODUKTIV!
   - ✅ Separate HP-Datenbank (192.168.2.81)
   - 🔒 NICHT löschen!

### 5. **Index:** [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)
   - 📖 Übersicht aller Dokumente
   - 🔗 Quick-Links nach Thema
   - ✅ Checklisten

---

## ⚡ Quick Start

### 1. Backup erstellen (ZUERST!)
```bash
cd /home/vadmin/lemp/html/wci
tar -czf ../wci_backup_$(date +%Y%m%d_%H%M%S).tar.gz .
```

### 2. Dokumentation lesen
```bash
# Terminal-freundlich:
cat AUDIT_SUMMARY.txt

# Oder in VS Code:
code PROJECT_CONFIGURATION_AUDIT_README.md
```

### 3. Cleanup durchführen
```bash
# Siehe Commands in AUDIT_SUMMARY.txt
# oder PROJECT_CONFIGURATION_AUDIT_README.md → Phase 3-5
```

---

## ⚠️ Wichtige Hinweise

### ✅ NIEMALS LÖSCHEN:
- `/config.php` - Booking-DB Master
- `/hp-db-config.php` - HP-DB Master (**WICHTIG!**)
- `/hrs/hrs_login.php` - HRS-Login
- `/index.php` - Hauptseite

### 🔒 Sicherheit:
Diese Dokumentation enthält **Passwörter**!
```bash
chmod 600 *.md *.txt  # Schützen
# oder nach Verwendung löschen
```

---

## 📊 Zwei separate Datenbanken!

| # | Config | Server | Database | Zweck |
|---|--------|--------|----------|-------|
| 1 | `/config.php` | 192.168.15.14 | booking_franzsen | Zimmerplan |
| 2 | `/hp-db-config.php` | 192.168.2.81 | fsh-res | Halbpension |

⚠️ **Beide sind produktiv und dürfen NICHT gelöscht werden!**

---

## 📈 Geschätzte Ersparnis nach Cleanup

- 🗑️ **75+ Dateien** löschbar
- 💾 **~90 MB** Speicherplatz
- ✅ **Keine Duplikate** mehr
- 🔒 **Zentrale Configs** für bessere Wartung

---

## 🏁 Checkliste

- [ ] Backup erstellt
- [ ] Alle 5 Dokumente gelesen
- [ ] Zentrale Configs erstellt
- [ ] Duplikate gelöscht (**NICHT hp-db-config.php!**)
- [ ] System getestet

---

**Erstellt von:** GitHub Copilot AI Assistant  
**Version:** 2.0 (HP-DB Korrektur)  
**Kontakt:** Siehe Dokumentation für Details

---

🔗 **Alle Dokumente:**
- [AUDIT_SUMMARY.txt](AUDIT_SUMMARY.txt) - Schnell-Übersicht
- [PROJECT_CONFIGURATION_AUDIT_README.md](PROJECT_CONFIGURATION_AUDIT_README.md) - Hauptbericht
- [DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md) - DB-Architektur
- [AUDIT_CORRECTION_HP_DB.md](AUDIT_CORRECTION_HP_DB.md) - HP-DB Korrektur
- [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) - Vollständiger Index
