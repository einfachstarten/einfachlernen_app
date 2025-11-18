# 🧪 Beta Feature Deployment: Last-Minute Notifications

## 🎯 Ziel

Automatische E-Mail-Benachrichtigungen an Beta-User, wenn kurzfristige Termine verfügbar werden.

## 🔒 Beta-Sicherheit

**Dieses Feature ist zu 100% Beta-isoliert:**
- ✅ Betrifft NUR User mit `beta_access = 1`
- ✅ Separate Datenbank-Tabellen mit `IF NOT EXISTS`
- ✅ Foreign Keys mit `ON DELETE CASCADE`
- ✅ Fehler werden geloggt, crashen aber nicht
- ✅ Keine Auswirkung auf normale User

## 📋 Deployment Checklist

### ✅ Phase 1: Vorbereitung (5 Min)

- [ ] Admin-Zugang zum Server vorhanden
- [ ] Calendly Account mit API-Zugriff
- [ ] Beta-User in Datenbank aktiviert (`customers.beta_access = 1`)

### ✅ Phase 2: Datenbank Setup (2 Min)

**Option A: Via Web-Interface (Empfohlen)**

1. Als Admin einloggen
2. Navigiere zu: `https://deine-domain.de/beta/setup_last_minute.php`
3. Prüfe, dass beide Tabellen erstellt wurden:
   - ✅ `last_minute_subscriptions`
   - ✅ `last_minute_notifications`

**Option B: Via Command Line**

```bash
cd /pfad/zu/einfachlernen_app
php beta/setup_cli.php
```

**Erwartete Ausgabe:**
```
🧪 Beta Feature Setup: Last-Minute Notifications
============================================================
📊 Creating database tables...
✅ last_minute_subscriptions - OK
✅ last_minute_notifications - OK
👥 Beta users in database: X
✅ Setup completed successfully!
```

### ✅ Phase 3: Calendly Token Setup (3 Min)

1. **Token holen:**
   - Gehe zu: https://calendly.com/integrations/api_webhooks
   - Erstelle "Personal Access Token"
   - Kopiere Token

2. **Token setzen:**

   **Option A: Via .htaccess (Apache)**
   ```apache
   SetEnv CALENDLY_TOKEN "dein_token_hier"
   ```

   **Option B: Via Environment Variable**
   ```bash
   export CALENDLY_TOKEN="dein_token_hier"
   ```

   **Option C: Via PHP-FPM Pool Config**
   ```ini
   env[CALENDLY_TOKEN] = "dein_token_hier"
   ```

3. **Token prüfen:**
   - Navigiere zu: `https://deine-domain.de/beta/check_calendly_token.php`
   - Sollte zeigen: ✅ Token ist konfiguriert

### ✅ Phase 4: Logs-Verzeichnis (BEREITS ERLEDIGT ✅)

```bash
# Bereits erstellt mit korrekten Permissions:
drwxrwxr-x admin/logs/
```

### ✅ Phase 5: Manueller Test (5 Min)

**Wichtig:** Teste BEVOR du den Cron einrichtest!

```bash
cd /pfad/zu/einfachlernen_app
php admin/last_minute_checker.php
```

**Erwartete Ausgabe:**
```
[YYYY-MM-DD HH:MM:SS] === Last-Minute Checker Started ===
[YYYY-MM-DD HH:MM:SS] Found X active subscriptions
[YYYY-MM-DD HH:MM:SS] Checking slots for user@example.com
[YYYY-MM-DD HH:MM:SS] Found X slots for Lerntraining
[YYYY-MM-DD HH:MM:SS] Notification for user@example.com: sent
[YYYY-MM-DD HH:MM:SS] === Last-Minute Checker Completed ===
```

**Logs prüfen:**
```bash
cat admin/logs/last_minute_checker.log
```

**Häufige Probleme:**

| Fehler | Lösung |
|--------|--------|
| "Calendly token is not configured" | Token setzen (siehe Phase 3) |
| "DB connection failed" | DB-Credentials prüfen |
| "No active subscriptions" | Beta-User muss Feature aktivieren |
| "HTTP 401" von Calendly | Token ist ungültig |

### ✅ Phase 6: Cron-Job Setup (3 Min)

**Automatische Ausführung: 3x täglich**
- 07:00 Uhr (Morgen-Check)
- 12:00 Uhr (Mittags-Check)
- 20:00 Uhr (Abend-Check)

**Option A: Via crontab**

```bash
crontab -e
```

Füge hinzu:
```cron
0 7 * * * /pfad/zu/einfachlernen_app/beta/run_last_minute_checker.sh
0 12 * * * /pfad/zu/einfachlernen_app/beta/run_last_minute_checker.sh
0 20 * * * /pfad/zu/einfachlernen_app/beta/run_last_minute_checker.sh
```

**Option B: Via Hosting Control Panel**

Erstelle 3 Cron-Jobs mit:
- Command: `php /pfad/zu/admin/last_minute_checker.php`
- Zeitpunkte: 7:00, 12:00, 20:00

**Cron-Job testen:**

```bash
# Warte bis nächster Cron-Lauf (z.B. 12:00)
# Dann prüfe Log:
tail -f admin/logs/last_minute_checker.log
```

### ✅ Phase 7: Beta-User aktivieren (2 Min)

1. **Admin-Dashboard:**
   - Gehe zu: `https://deine-domain.de/admin/dashboard.php`
   - Wähle User aus
   - Klicke "Beta-Zugang aktivieren"

2. **User informieren:**
   - User geht zu: `https://deine-domain.de/beta/index.php`
   - Klickt auf "Last-Minute Slots"
   - Aktiviert Feature und wählt Services

### ✅ Phase 8: Monitoring (laufend)

**Logs regelmäßig prüfen:**

```bash
# Letzte Ausführungen
tail -n 100 admin/logs/last_minute_checker.log

# Nach Fehlern suchen
grep "ERROR\|FAILED" admin/logs/last_minute_checker.log

# Erfolgreiche E-Mails zählen
grep "sent$" admin/logs/last_minute_checker.log | wc -l
```

**Datenbank-Monitoring:**

```sql
-- Aktive Subscriptions
SELECT COUNT(*) FROM last_minute_subscriptions WHERE is_active = 1;

-- Versendete Benachrichtigungen (letzte 7 Tage)
SELECT
    DATE(sent_at) as date,
    COUNT(*) as total,
    SUM(email_sent) as successful,
    SUM(CASE WHEN email_sent = 0 THEN 1 ELSE 0 END) as failed
FROM last_minute_notifications
WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(sent_at)
ORDER BY date DESC;
```

## 🎉 Fertig!

Das Beta-Feature ist jetzt live und läuft automatisch!

## 📊 KPIs zum Tracken

- **Subscriptions:** Wie viele Beta-User nutzen das Feature?
- **Slots Found:** Wie viele Termine werden gefunden?
- **Emails Sent:** Wie viele E-Mails werden versendet?
- **Success Rate:** Wie viele E-Mails werden erfolgreich zugestellt?
- **Bookings:** Führen Benachrichtigungen zu Buchungen?

## 🚀 Rollback (falls nötig)

```bash
# 1. Cron-Jobs deaktivieren
crontab -e
# Zeilen mit # auskommentieren

# 2. Feature in UI ausblenden (optional)
# In beta/index.php die Last-Minute Card auskommentieren

# 3. Tabellen NICHT löschen (enthalten User-Daten)
```

## 🔄 Updates

Wenn du Code-Änderungen machst:

1. Teste lokal: `php admin/last_minute_checker.php`
2. Prüfe Logs auf Fehler
3. Deploy Code
4. Cron läuft automatisch weiter

## 📞 Support

Bei Problemen:
1. Prüfe Logs: `admin/logs/last_minute_checker.log`
2. Prüfe DB: Sind Subscriptions aktiv?
3. Prüfe Token: `beta/check_calendly_token.php`
4. Teste manuell: `php admin/last_minute_checker.php`

## 🎓 Für Entwickler

**Architektur:**
```
Beta-User aktiviert Feature
    ↓
User-Settings in DB gespeichert (last_minute_subscriptions)
    ↓
Cron läuft 3x täglich
    ↓
Script prüft Calendly API (nur für aktive Beta-Subscriptions)
    ↓
E-Mail wird versendet (max 3 pro User/Tag)
    ↓
Historie in DB gespeichert (last_minute_notifications)
```

**Sicherheitsfeatures:**
- ✅ Beta-only: `WHERE beta_access = 1`
- ✅ Rate-Limiting: Max 3 E-Mails/Tag/User
- ✅ Error Handling: Try-catch Blöcke
- ✅ Logging: Alle Fehler werden geloggt
- ✅ Isolated: Keine Auswirkung auf normale User

**Datenschutz:**
- User-Daten (E-Mail) werden nur für Beta-User verarbeitet
- User können Feature jederzeit deaktivieren
- Logs enthalten keine sensiblen Daten
- Auf Wunsch können Logs gelöscht werden
