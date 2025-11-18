# 🧪 Cron-Job Setup für Last-Minute Notifications

## Übersicht

Das Last-Minute Feature prüft automatisch 3x täglich nach verfügbaren Terminen:
- **07:00 Uhr** - Morgen-Check
- **12:00 Uhr** - Mittags-Check
- **20:00 Uhr** - Abend-Check

## 🔒 Beta-Sicherheit

- ✅ Script prüft NUR Beta-User (`beta_access = 1`)
- ✅ Normale User werden NICHT kontaktiert
- ✅ Isolierter Prozess ohne Auswirkung auf normalen Betrieb
- ✅ Fehler werden geloggt aber crashen nicht

## Installation

### Option 1: Via crontab (Linux/macOS)

```bash
# Crontab öffnen
crontab -e

# Diese Zeilen hinzufügen:
0 7 * * * /usr/bin/php /pfad/zu/einfachlernen_app/admin/last_minute_checker.php >> /pfad/zu/einfachlernen_app/admin/logs/cron.log 2>&1
0 12 * * * /usr/bin/php /pfad/zu/einfachlernen_app/admin/last_minute_checker.php >> /pfad/zu/einfachlernen_app/admin/logs/cron.log 2>&1
0 20 * * * /usr/bin/php /pfad/zu/einfachlernen_app/admin/last_minute_checker.php >> /pfad/zu/einfachlernen_app/admin/logs/cron.log 2>&1
```

**Wichtig:** Ersetze `/pfad/zu/einfachlernen_app` mit dem echten Pfad!

### Option 2: Via Hosting Control Panel

Wenn dein Hoster ein Control Panel hat (cPanel, Plesk, etc.):

1. Gehe zu "Cron Jobs" oder "Geplante Aufgaben"
2. Erstelle 3 neue Cron-Jobs:

**Job 1 (Morgen):**
- Zeit: `0 7 * * *` (7:00 Uhr täglich)
- Command: `php /pfad/zu/admin/last_minute_checker.php`

**Job 2 (Mittag):**
- Zeit: `0 12 * * *` (12:00 Uhr täglich)
- Command: `php /pfad/zu/admin/last_minute_checker.php`

**Job 3 (Abend):**
- Zeit: `0 20 * * *` (20:00 Uhr täglich)
- Command: `php /pfad/zu/admin/last_minute_checker.php`

### Option 3: Manuelles Testen (vor Cron-Setup)

Teste das Script erst manuell:

```bash
cd /pfad/zu/einfachlernen_app
php admin/last_minute_checker.php
```

Prüfe dann die Logs:
```bash
cat admin/logs/last_minute_checker.log
```

## Cron-Format Erklärung

```
┌───────────── Minute (0-59)
│ ┌───────────── Stunde (0-23)
│ │ ┌───────────── Tag des Monats (1-31)
│ │ │ ┌───────────── Monat (1-12)
│ │ │ │ ┌───────────── Wochentag (0-7, 0=Sonntag)
│ │ │ │ │
* * * * *

0 7 * * *  = Jeden Tag um 7:00 Uhr
0 12 * * * = Jeden Tag um 12:00 Uhr
0 20 * * * = Jeden Tag um 20:00 Uhr
```

## Monitoring

### Logs prüfen

```bash
# Letzte 50 Zeilen anzeigen
tail -n 50 admin/logs/last_minute_checker.log

# Live-Monitoring (während Cron läuft)
tail -f admin/logs/last_minute_checker.log

# Nach Fehlern suchen
grep "ERROR" admin/logs/last_minute_checker.log
grep "FAILED" admin/logs/last_minute_checker.log
```

### Erfolgskriterien

Ein erfolgreicher Cron-Lauf sieht so aus:

```
[2024-11-18 07:00:01] === Last-Minute Checker Started ===
[2024-11-18 07:00:02] Found 3 active subscriptions
[2024-11-18 07:00:02] Checking slots for user@example.com
[2024-11-18 07:00:05] Found 2 slots for Lerntraining
[2024-11-18 07:00:07] Notification for user@example.com: sent
[2024-11-18 07:00:07] === Last-Minute Checker Completed ===
```

## Troubleshooting

### Problem: "Calendly token is not configured"

**Lösung:** Setze `CALENDLY_TOKEN` Environment Variable (siehe `beta/check_calendly_token.php`)

### Problem: "DB connection failed"

**Lösung:** Prüfe DB-Credentials in `admin/config.php`

### Problem: "Email sending failed"

**Lösung:** Prüfe SMTP-Settings in `admin/config.php`

### Problem: Cron läuft nicht

```bash
# Prüfe ob Cron-Service läuft
systemctl status cron  # oder: service cron status

# Prüfe Cron-Logs
tail /var/log/syslog | grep CRON
```

## Deaktivierung

Zum temporären Deaktivieren:

```bash
# Crontab öffnen
crontab -e

# Zeilen mit # auskommentieren:
# 0 7 * * * /usr/bin/php /pfad/...
# 0 12 * * * /usr/bin/php /pfad/...
# 0 20 * * * /usr/bin/php /pfad/...
```

Oder: User können Feature selbst deaktivieren unter `beta/last_minute_settings.php`

## Performance

- **Laufzeit:** Ca. 5-15 Sekunden (abhängig von Anzahl Beta-User)
- **API-Calls:** 1-3 pro Service pro User
- **E-Mails:** Max. 3 pro User pro Tag
- **Server-Last:** Minimal (läuft im Hintergrund)

## Sicherheitshinweise

1. ✅ Script läuft mit Web-Server User (www-data, apache, nginx)
2. ✅ Logs sind nur vom Server-Admin lesbar
3. ✅ Kein User-Input wird verarbeitet
4. ✅ API-Token wird sicher über Environment Variable geladen
5. ✅ Nur Beta-User werden benachrichtigt
