# 🚀 Last-Minute Notifications - Quick Start

## ✅ 5-Schritte Setup (10 Minuten)

### 1. Datenbank-Tabellen erstellen (2 Min)
```
Browser öffnen:
https://www.einfachstarten.jetzt/einfachlernen/beta/setup_last_minute.php
```
✅ Als Admin einloggen → Tabellen werden automatisch erstellt

---

### 2. Calendly Token setzen (3 Min)
```bash
# .htaccess auf dem Server bearbeiten:
SetEnv CALENDLY_TOKEN "dein_calendly_token"
```
📝 Token holen: https://calendly.com/integrations/api_webhooks

✅ Prüfen: https://www.einfachstarten.jetzt/einfachlernen/beta/check_calendly_token.php

---

### 3. Secret-Key einrichten (2 Min)
```bash
# In .htaccess hinzufügen:
SetEnv CRON_SECRET "b8f3c9e2d7a4f1e6c9d3b7f2a5e8c1d4"
```
💡 Nutze einen zufälligen String oder: `openssl rand -hex 32`

---

### 4. Cron-Jobs bei cron-job.org einrichten (3 Min)

**Registrierung:** https://console.cron-job.org/signup

**3 Jobs erstellen:**

**URL (für alle 3):**
```
http://www.einfachstarten.jetzt/einfachlernen/admin/last_minute_checker_cron.php?secret=b8f3c9e2d7a4f1e6c9d3b7f2a5e8c1d4
```

**Zeitpläne:**
- Job 1: Every day at `07:00` (Morgen)
- Job 2: Every day at `12:00` (Mittag)
- Job 3: Every day at `20:00` (Abend)

**Timezone:** `Europe/Vienna`

---

### 5. Testen & Beta-User aktivieren

**Testen:**
```
https://www.einfachstarten.jetzt/einfachlernen/beta/test_last_minute.php
→ Klick "▶️ Checker jetzt ausführen"
```

**Beta-User aktivieren:**
- Admin-Dashboard → User auswählen → "Beta-Zugang aktivieren"
- User geht zu `/beta/last_minute_settings.php`
- Aktiviert Feature & wählt Services

---

## ✅ Feature ist LIVE wenn:

- [x] DB-Tabellen existieren
- [x] CALENDLY_TOKEN gesetzt
- [x] CRON_SECRET gesetzt
- [x] 3 Cron-Jobs bei cron-job.org
- [x] Test erfolgreich
- [x] Beta-User aktiviert

---

## 📊 Monitoring

**Logs prüfen:**
```bash
cat admin/logs/last_minute_checker.log
```

**Dashboard:**
```
https://www.einfachstarten.jetzt/einfachlernen/beta/test_last_minute.php
```

**cron-job.org:**
- Execution History
- HTTP Status Codes
- E-Mail Alerts bei Fehlern

---

## 🔗 Weitere Dokumentation

- **Setup-Details:** `DEPLOYMENT_GUIDE.md`
- **Cron-Setup:** `CRON_SETUP_HOSTER.md`
- **Feature-Übersicht:** `README.md`

---

## 🧪 Beta-Sicherheit

✅ Nur `beta_access = 1` User
✅ Max 3 E-Mails pro User/Tag
✅ Isolierte DB-Tabellen
✅ Keine Auswirkung auf normale User
✅ Secret-Key geschützt

---

**🎉 Deployment abgeschlossen! Feature läuft automatisch 3x täglich.**
