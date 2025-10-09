# 🚨 KRITISCHES PROBLEM

## Problem:
- HRS API findet **keine Quotas** für 12.02.2026 (gibt leeres Array zurück)
- Aber HRS-System **hat** Quota "Auto-120226" mit 80 Plätzen!
- Deshalb: "Cannot overlap quotas" Fehler beim Erstellen

## Mögliche Ursachen:
1. **Authentication Problem**: Session/Cookies funktionieren nicht für die Quota-API
2. **API Filter falsch**: Datumsformat oder Parameter stimmen nicht
3. **Permissions**: User hat keine Berechtigung Quotas abzurufen

## Temporäre Lösung:
**Manuell im HRS-System die "Auto-120226" Quota löschen!**

Dann funktioniert der Upload.

## Langfristige Lösung:
1. **HRS API Debug**: Direkten API-Call mit korrekten Cookies testen
2. **Alternative**: Import-basierte Synchronisation statt API
3. **Workaround**: Bei "Cannot overlap" Fehler → Manuelles Löschen erzwingen

## Test-Command:
```bash
# Direkt die Quota im HRS löschen (wenn ID bekannt):
curl -X DELETE "https://www.hut-reservation.org/api/v1/manage/deleteQuota?hutId=675&quotaId=XXXXX&..."
```

