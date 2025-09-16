# ERD Display Logging API

## 📋 Installation

1. **Kopieren Sie `log_api.php` in Ihr Server-Verzeichnis:**
   ```
   http://192.168.15.14:8080/wci/erd/log_api.php
   ```

2. **Berechtigung setzen (Linux/Apache):**
   ```bash
   chmod 755 log_api.php
   mkdir logs
   chmod 755 logs
   ```

3. **Testen Sie die API:**
   ```
   http://192.168.15.14:8080/wci/erd/log_api.php?device_id=30c6f7fa8ff4
   ```

## 🔧 API-Endpoints

### 📝 Log-Eintrag erstellen
```bash
POST /log_api.php
Content-Type: application/json

{
  "device_id": "30c6f7fa8ff4",
  "action": "WAKE_UP",
  "details": "Deep Sleep Timer Wake-up",
  "uptime": 12345,
  "memory_free": 280000,
  "wifi_rssi": -45
}
```

### 📊 Statistiken abrufen
```
GET /log_api.php?device_id=30c6f7fa8ff4&stats=1
```

### 📥 Log-Datei downloaden
```
GET /log_api.php?device_id=30c6f7fa8ff4&download=1
```

## 🎯 Funktionen

- ✅ **Automatische Log-Rotation** (1MB pro Datei, 10 Dateien)
- ✅ **JSON-Format** für strukturierte Daten
- ✅ **Statistiken-Dashboard** mit Effizienz-Metriken
- ✅ **Sichere Device-ID Validierung**
- ✅ **Timezone-Support** (Europe/Zurich)
- ✅ **CORS-Support** für Web-Zugriff
- ✅ **Download-Funktion** für Log-Archive

## 📁 Verzeichnisstruktur

```
/wci/erd/
├── log_api.php           # Haupt-API
├── logs/                 # Log-Verzeichnis (automatisch erstellt)
│   ├── erd_display_30c6f7fa8ff4.log     # Aktuelle Logs
│   ├── erd_display_30c6f7fa8ff4_1.log   # Rotation 1
│   └── erd_display_30c6f7fa8ff4_2.log   # Rotation 2
├── T13.bmp              # Ihre BMP-Dateien
├── T13.crc              # CRC-Dateien
└── README_API.md        # Diese Datei
```

## 📈 Beispiel-Statistiken

```json
{
  "device_id": "30c6f7fa8ff4",
  "total_entries": 145,
  "first_entry": "2025-09-12T14:30:15+02:00",
  "last_entry": "2025-09-12T18:45:22+02:00",
  "actions": {
    "WAKE_UP": 48,
    "CRC_CHECK_START": 48,
    "NO_UPDATE_NEEDED": 45,
    "IMAGE_UPDATE": 3,
    "DEEP_SLEEP_START": 48
  },
  "avg_sleep_duration": 30,
  "total_wake_ups": 48,
  "image_updates": 3,
  "efficiency_ratio": 6.25,
  "uptime_stats": {
    "min": 1.2,
    "max": 3.8,
    "avg": 2.1
  }
}
```

## 🔒 Sicherheit

- Device IDs müssen 12-stellige Hex-Werte sein (MAC-Format ohne Doppelpunkte)
- Automatische Validierung aller Eingaben
- Sichere Datei-Operationen mit Locking
- CORS-Header für kontrollierten Zugriff

## 🚀 Erweiterungsmöglichkeiten

- **Web-Dashboard:** HTML-Interface für Log-Visualisierung
- **Benachrichtigungen:** E-Mail/Webhook bei Problemen
- **Datenbank-Backend:** MySQL/SQLite für große Datenmengen
- **Multi-Device:** Verwaltung mehrerer ESP32-Geräte
- **Real-time:** WebSocket für Live-Monitoring
