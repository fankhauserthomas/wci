# 📚 WCI Projekt Dokumentations-Index

**Erstellt:** 2025-10-09  
**Projekt:** WCI Booking System - Franzsennhütte

---

## 📖 Verfügbare Dokumentation

### 🔍 Audit & Analyse

1. **[PROJECT_CONFIGURATION_AUDIT_README.md](PROJECT_CONFIGURATION_AUDIT_README.md)**  
   📄 **Hauptbericht** - Vollständiger Konfigurations-Audit  
   - HRS-Zugangsdaten Analyse (13 Fundstellen)
   - Hut ID Analyse (50+ Fundstellen)
   - Datenbank-Konfigurationen (2 Master, 4+ Duplikate)
   - Löschbare Dateien (75+ Dateien, ~90 MB)
   - Papierkorb-Analyse (39 Dateien)
   - Sofort-Maßnahmen & Cleanup-Anweisungen

2. **[AUDIT_CORRECTION_HP_DB.md](AUDIT_CORRECTION_HP_DB.md)**  
   ✅ **Korrektur** - Wichtige Klarstellung zur HP-Datenbank  
   - `/hp-db-config.php` ist **PRODUKTIV** und wichtig!
   - Separate Halbpensions-Datenbank auf 192.168.2.81
   - Verwendung in 20+ Dateien dokumentiert

3. **[DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md)**  
   🗄️ **Architektur** - Vollständige Datenbank-Dokumentation  
   - Zwei separate Datenbanken erklärt
   - Booking-DB vs. HP-DB
   - Verwendungsmuster & Best Practices
   - Connection-Pooling & Fehlerbehandlung
   - Backup-Strategien

---

## 🎯 Quick Links nach Thema

### Configuration Management
- ✅ Master-Configs: `/config.php` + `/hp-db-config.php`
- ❌ Zu löschen: `config-simple.php`, `config-safe.php`, `test-config.php`
- 📖 Details: [Abschnitt 3 im Audit-Bericht](PROJECT_CONFIGURATION_AUDIT_README.md#3️⃣-datenbank-konfigurationen)

### HRS-System
- ✅ Master-Login: `/hrs/hrs_login.php`
- 🔴 13 Duplikate gefunden
- 💡 Empfehlung: Neue zentrale `/hrs/hrs_credentials.php` erstellen
- 📖 Details: [Abschnitt 1 im Audit-Bericht](PROJECT_CONFIGURATION_AUDIT_README.md#1️⃣-hrs-zugangsdaten-usernamepassword)

### Hut ID
- 🔴 50+ Hardcoded-Stellen gefunden
- 💡 Empfehlung: `define('HUT_ID', 675)` in `/config.php`
- 📖 Details: [Abschnitt 2 im Audit-Bericht](PROJECT_CONFIGURATION_AUDIT_README.md#2️⃣-hut-id-definition-675---franzsennhütte)

### Datenbanken
- ✅ Booking-DB: 192.168.15.14 → `booking_franzsen`
- ✅ HP-DB: 192.168.2.81 → `fsh-res`
- 📖 Details: [DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md)

### Cleanup
- 🗑️ Papierkorb: 37 von 39 Dateien löschbar
- 🗑️ Backups: 15+ Dateien löschbar
- 🗑️ Alte Versionen: 10+ Dateien löschbar
- 💾 Geschätzte Ersparnis: ~90 MB
- 📖 Details: [Appendix A-D im Audit-Bericht](PROJECT_CONFIGURATION_AUDIT_README.md#-sicher-löschbare-dateien)

---

## ⚡ Schnellstart-Checklisten

### ✅ Vor dem Cleanup (WICHTIG!)
```bash
# 1. Backup erstellen
cd /home/vadmin/lemp/html/wci
tar -czf ../wci_backup_$(date +%Y%m%d_%H%M%S).tar.gz .

# 2. Git Status checken (falls verwendet)
git status

# 3. Dokumentation gelesen?
# - PROJECT_CONFIGURATION_AUDIT_README.md
# - DATABASE_ARCHITECTURE.md
# - AUDIT_CORRECTION_HP_DB.md
```

### 🔧 Phase 1: Zentrale Configs erstellen
```bash
# 1. HRS Credentials zentral
# Siehe: PROJECT_CONFIGURATION_AUDIT_README.md → Phase 2

# 2. HUT_ID zentral
# Siehe: PROJECT_CONFIGURATION_AUDIT_README.md → Phase 2
```

### 🗑️ Phase 2: Duplikate löschen
```bash
# WICHTIG: hp-db-config.php NICHT löschen!
cd /home/vadmin/lemp/html/wci

# Config-Duplikate
rm -f config-simple.php
rm -f config-safe.php
rm -f test-config.php
rm -f tests/config-simple.php

# Siehe: PROJECT_CONFIGURATION_AUDIT_README.md → Phase 3-5
```

### 🧪 Phase 3: Testen
```bash
# 1. HRS-Import testen
# 2. Zimmerplan öffnen
# 3. HP-Arrangements prüfen
# 4. Datenbank-Verbindungen checken

# Siehe: PROJECT_CONFIGURATION_AUDIT_README.md → Checkliste
```

---

## 🚨 Wichtige Warnungen

### ❌ NIEMALS LÖSCHEN:
- `/config.php` - Booking-DB Master
- `/hp-db-config.php` - HP-DB Master (SEPARATE DATENBANK!)
- `/hrs/hrs_login.php` - Aktiver HRS-Login
- `/hrs/hrs_imp_*_stream.php` - Aktive Importer
- `/zp/timeline-unified.js` - Hauptanwendung
- `/index.php` - Hauptseite

### 🔒 Credentials in Berichten:
⚠️ Alle Audit-Berichte enthalten **Passwörter**!

```bash
# Berichte schützen:
chmod 600 *.md

# Oder nach Verwendung löschen:
# rm AUDIT_*.md
```

---

## 📞 Support & Weitere Infos

### Bei Fragen zu:
- **HRS-System:** Siehe `/hrs/` Dokumentation
- **Datenbanken:** Siehe [DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md)
- **Cleanup:** Siehe [PROJECT_CONFIGURATION_AUDIT_README.md](PROJECT_CONFIGURATION_AUDIT_README.md)
- **HP-DB:** Siehe [AUDIT_CORRECTION_HP_DB.md](AUDIT_CORRECTION_HP_DB.md)

### Weitere Dokumentation im Projekt:
```bash
# Alle Markdown-Dateien finden:
find . -name "*.md" -type f | sort

# Projekt-spezifische Docs:
ls -lh /home/vadmin/lemp/html/wci/*.md
ls -lh /home/vadmin/lemp/html/wci/hrs/*.md
ls -lh /home/vadmin/lemp/html/wci/zp/*.md
```

---

## 📈 Statistiken

| Metrik | Wert |
|--------|------|
| **Analysierte Dateien** | 430+ |
| **HRS-Credential-Duplikate** | 13 |
| **HUT_ID Hardcoded** | 50+ |
| **Config-Duplikate** | 4+ |
| **Löschbare Dateien** | 75+ |
| **Papierkorb-Dateien** | 39 |
| **Platzersparnis möglich** | ~90 MB |
| **Master-Configs (OK)** | 2 |

---

## 🏁 Nächste Schritte

1. ✅ Dokumentation gelesen
2. ⏳ Backup erstellt
3. ⏳ Zentrale Configs erstellt
4. ⏳ Duplikate gelöscht
5. ⏳ System getestet
6. ⏳ Checkliste abgearbeitet

---

**Erstellt von:** GitHub Copilot AI Assistant  
**Letzte Aktualisierung:** 2025-10-09  
**Version:** 2.0 (mit HP-DB Korrektur)

🔗 **Alle Dokumentation:**
- [PROJECT_CONFIGURATION_AUDIT_README.md](PROJECT_CONFIGURATION_AUDIT_README.md) - Hauptbericht
- [DATABASE_ARCHITECTURE.md](DATABASE_ARCHITECTURE.md) - DB-Architektur
- [AUDIT_CORRECTION_HP_DB.md](AUDIT_CORRECTION_HP_DB.md) - HP-DB Korrektur
- [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) - Diese Datei
