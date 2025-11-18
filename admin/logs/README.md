# 🧪 Beta Feature Logs

Dieses Verzeichnis enthält Logs für Beta-Features.

## Last-Minute Checker

- **File:** `last_minute_checker.log`
- **Zweck:** Loggt alle Ausführungen des Last-Minute Slot Checkers
- **Format:** `[YYYY-MM-DD HH:MM:SS] Message`
- **Rotation:** Manuell (bei Bedarf alte Logs löschen)

### Beispiel Log-Einträge:

```
[2024-11-18 07:00:01] === Last-Minute Checker Started ===
[2024-11-18 07:00:02] Found 3 active subscriptions
[2024-11-18 07:00:02] Checking slots for user@example.com
[2024-11-18 07:00:05] Found 2 slots for Lerntraining
[2024-11-18 07:00:07] Notification for user@example.com: sent
[2024-11-18 07:00:07] === Last-Minute Checker Completed ===
```

## Monitoring

Prüfe Logs regelmäßig auf:
- ❌ Fehler bei API-Calls
- ❌ E-Mail-Versand Fehler
- ✅ Erfolgreiche Benachrichtigungen
- 📊 Anzahl gefundener Slots

## Sicherheit

- Logs enthalten keine sensiblen Daten (nur E-Mail-Adressen)
- Nur Beta-User werden geloggt
- Keine Auswirkung auf normale User
