# 🧪 Cron-Setup für Shared-Hosting (World4You, etc.)

## Problem: CLI-PHP hat kein PDO

Viele Hoster haben zwei PHP-Installationen:
- **Web-PHP:** Vollständig mit allen Extensions (inkl. PDO) ✅
- **CLI-PHP:** Minimal, oft ohne PDO ❌

**Lösung:** Nutze HTTP-Cron statt CLI-Cron!

## ✅ Empfohlene Lösung: HTTP-Cron

### Variante 1: Via Hosting Control Panel (Einfachste Methode)

Die meisten Hoster (World4You, ALL-INKL, STRATO, etc.) haben ein Cron-Interface im Control Panel:

1. **Gehe zu deinem Hosting Control Panel**
   - Bei World4You: "Cronjobs" oder "Geplante Aufgaben"

2. **Erstelle 3 Cron-Jobs:**

   **Job 1 (Morgen-Check):**
   - **Zeit:** `0 7 * * *` (7:00 Uhr täglich)
   - **Command:**
     ```bash
     curl -s https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php
     ```
   - **ODER** (wenn kein curl):
     ```bash
     wget -q -O /dev/null https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php
     ```

   **Job 2 (Mittags-Check):**
   - **Zeit:** `0 12 * * *` (12:00 Uhr täglich)
   - **Command:** Wie oben

   **Job 3 (Abend-Check):**
   - **Zeit:** `0 20 * * *` (20:00 Uhr täglich)
   - **Command:** Wie oben

### Variante 2: Via crontab (SSH-Zugriff erforderlich)

Wenn du SSH-Zugriff hast:

```bash
crontab -e
```

Füge hinzu:
```cron
# Last-Minute Notifications (Beta)
0 7 * * * curl -s https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php >> /home/.sites/689/site7951508/web/einfachlernen/admin/logs/cron.log 2>&1
0 12 * * * curl -s https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php >> /home/.sites/689/site7951508/web/einfachlernen/admin/logs/cron.log 2>&1
0 20 * * * curl -s https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php >> /home/.sites/689/site7951508/web/einfachlernen/admin/logs/cron.log 2>&1
```

### Variante 3: Via Shell-Script (Fortgeschritten)

Das aktualisierte `run_last_minute_checker.sh` versucht automatisch:
1. PHP mit PDO zu finden
2. Falls nicht gefunden → HTTP-Fallback via curl/wget

```bash
# Cron-Eintrag:
0 7,12,20 * * * /home/.sites/689/site7951508/web/einfachlernen/beta/run_last_minute_checker.sh
```

## 🔒 Sicherheit des HTTP-Endpoints

Der Endpoint `admin/last_minute_checker_cron.php` ist geschützt durch:

1. **Localhost-Check:** Nur von Server selbst aufrufbar
2. **Secret-Key (optional):** Zusätzlicher Schutz

### Secret-Key setzen (optional):

```bash
# In .htaccess:
SetEnv CRON_SECRET "dein_geheimer_schlüssel_hier"

# Dann Cron mit Secret aufrufen:
curl "https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php?secret=dein_geheimer_schlüssel_hier"
```

**Ohne Secret:** Endpoint ist trotzdem sicher, da er nur vom Server selbst (localhost) aufrufbar ist.

## ✅ Testen ob es funktioniert

### Test 1: Manueller HTTP-Aufruf

```bash
# Via SSH:
curl https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php

# Erwartete Ausgabe:
🧪 Last-Minute Checker - HTTP Execution
========================================

[YYYY-MM-DD HH:MM:SS] === Last-Minute Checker Started ===
[YYYY-MM-DD HH:MM:SS] Found X active subscriptions
...
✅ Execution completed.
```

### Test 2: Logs prüfen

```bash
cat admin/logs/last_minute_checker.log
```

### Test 3: Cron-Test

Warte bis zur nächsten geplanten Ausführung (z.B. 12:00), dann:

```bash
tail -f admin/logs/last_minute_checker.log
```

## 🐛 Troubleshooting

### Problem: "Class 'PDO' not found"

**Ursache:** CLI-PHP hat kein PDO
**Lösung:** Nutze HTTP-Cron (siehe oben) ✅

### Problem: "curl: command not found"

**Lösung:** Nutze `wget` statt `curl`:

```bash
wget -q -O /dev/null https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php
```

### Problem: Cron läuft nicht

**Check 1:** Ist Cron-Service aktiv?
```bash
systemctl status cron  # oder: service cron status
```

**Check 2:** Sind Logs vorhanden?
```bash
ls -la admin/logs/
```

**Check 3:** Manuelle Ausführung:
```bash
curl https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php
```

## 📊 Monitoring

### Cron-Ausführungen prüfen:

```bash
# Letzte 10 Ausführungen
grep "Checker Started" admin/logs/last_minute_checker.log | tail -n 10

# Erfolgreiche E-Mails
grep "sent$" admin/logs/last_minute_checker.log

# Fehler
grep "ERROR\|FAILED" admin/logs/last_minute_checker.log
```

### Datenbank-Check:

```sql
-- Letzte Benachrichtigungen
SELECT * FROM last_minute_notifications ORDER BY sent_at DESC LIMIT 10;

-- Heute verschickte E-Mails
SELECT COUNT(*) FROM last_minute_notifications WHERE DATE(sent_at) = CURDATE();
```

## 🎯 Empfohlener Workflow für World4You:

1. ✅ **DB-Setup via Browser:**
   ```
   https://einfachstarten.jetzt/einfachlernen/beta/setup_last_minute.php
   ```

2. ✅ **Calendly Token setzen:**
   ```bash
   # In .htaccess am Server:
   SetEnv CALENDLY_TOKEN "dein_token"
   ```

3. ✅ **HTTP-Endpoint testen:**
   ```bash
   curl https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php
   ```

4. ✅ **Cron im Control Panel einrichten:**
   - 3 Jobs anlegen (7:00, 12:00, 20:00)
   - Command: `curl -s https://einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php`

5. ✅ **Beta-User aktivieren & testen**

## 🔗 Weitere Infos

- Haupt-Dokumentation: `DEPLOYMENT_GUIDE.md`
- Quick Start: `QUICKSTART.md`
- Test-Interface: `/beta/test_last_minute.php`

## ✅ Vorteile der HTTP-Lösung

- ✅ Funktioniert auf JEDEM Hoster
- ✅ Nutzt Web-PHP (hat immer alle Extensions)
- ✅ Einfach im Control Panel einzurichten
- ✅ Keine SSH-Kenntnisse erforderlich
- ✅ Logs funktionieren out-of-the-box
