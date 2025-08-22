# HRS (Hut Reservation System) Module

Dieser Ordner enthält alle produktiven HRS-Authentifizierungs- und Import-Module.

## 📁 Datei-Übersicht

### 🔧 **Produktive Module**
- **`hrs_login.php`** - Standalone HRS-Authentifizierungs-Klasse
- **`hrs_imp_res.php`** - Reservierungs-Import-System für AV-Res-webImp
- **`test_hrs_login.php`** - Test und Beispiel für hrs_login.php

### 📚 **Dokumentation**
- **`../HRS_LOGIN_TECHNICAL_DOCS.md`** - Detaillierte technische Dokumentation
- **`../HRS_SYSTEM_DOCS.md`** - System-Übersicht und Migration-Info

## 🚀 **Verwendung**

### Reservierungs-Import
```bash
cd /home/vadmin/lemp/html/wci/hrs
php hrs_imp_res.php 20.08.2025 31.08.2025
```

### Standalone Login-Test
```bash
cd /home/vadmin/lemp/html/wci/hrs
php test_hrs_login.php
```

### Integration in andere Projekte
```php
require_once 'hrs/hrs_login.php';

$hrsAuth = new HRSLogin();
if ($hrsAuth->login()) {
    $response = $hrsAuth->makeRequest('/api/v1/your-endpoint', 'GET');
}
```

## 🗂️ **Archiv**

Veraltete experimentelle Dateien wurden nach `../papierkorb/` verschoben:
- `hrs.php` (experimentell, 32 Tage alt)
- `hrs-simple.php` (experimentell, 32 Tage alt) 
- `hrs-fileget.php` (experimentell, 32 Tage alt)

## ✅ **Status**

- **Login-System:** ✅ Production Ready
- **Import-System:** ✅ Production Ready  
- **Dokumentation:** ✅ Vollständig
- **Tests:** ✅ Funktionsfähig

**Letztes Update:** 2025-08-22
