# 🚀 Last-Minute Notifications - Quick Start

## 30 Sekunden Setup

```bash
# 1. Setup-Script ausführen
php beta/setup_cli.php

# 2. Token setzen (eine der Optionen)
echo 'SetEnv CALENDLY_TOKEN "dein_token"' >> .htaccess

# 3. Manuell testen
php admin/last_minute_checker.php

# 4. Logs prüfen
cat admin/logs/last_minute_checker.log

# 5. Cron einrichten
crontab -e
# Hinzufügen:
# 0 7,12,20 * * * /pfad/zu/beta/run_last_minute_checker.sh
```

## ✅ Feature ist live wenn:

- [ ] DB-Tabellen existieren
- [ ] CALENDLY_TOKEN gesetzt
- [ ] Manueller Test erfolgreich
- [ ] Cron-Jobs laufen
- [ ] Beta-User aktiviert

## 🔗 Links

- Setup: `/beta/setup_last_minute.php`
- Token Check: `/beta/check_calendly_token.php`
- User-Settings: `/beta/last_minute_settings.php`
- Full Guide: `DEPLOYMENT_GUIDE.md`

## 🧪 Beta-Sicherheit

✅ Nur `beta_access = 1` User
✅ Max 3 E-Mails pro User/Tag
✅ Isolierte DB-Tabellen
✅ Fehler-Logging ohne Crash
