# 🧪 Beta Features

Dieses Verzeichnis enthält alle Beta-Features für einfachlernen_app.

## ✅ Aktuelle Beta-Features

### 1. Last-Minute Slot Notifications

**Status:** ✅ LIVE IN PRODUCTION
**User-Interface:** `last_minute_settings.php`
**Backend:** `../admin/last_minute_checker.php`
**HTTP-Endpoint:** `../admin/last_minute_checker_cron.php`
**Docs:** `DEPLOYMENT_GUIDE.md`, `QUICKSTART.md`

**Was es tut:**
- Automatische E-Mail-Benachrichtigungen wenn kurzfristige Termine frei werden
- Prüft 3x täglich (7:00, 12:00, 20:00 Uhr) die Calendly API via cron-job.org
- Beta-User können Services auswählen (Lerntraining, Neurofeedback)
- Max. 3 E-Mails pro User pro Tag
- Rate-Limiting und täglicher Counter-Reset

**Deployment:**
1. Web-Setup: `setup_last_minute.php` - Erstellt DB-Tabellen
2. CALENDLY_TOKEN in .htaccess setzen
3. CRON_SECRET in .htaccess setzen
4. 3 Cron-Jobs bei cron-job.org einrichten
5. Testen mit `test_last_minute.php`

**Live seit:** 2024-11-19
**Läuft auf:** World4You Shared Hosting via cron-job.org

---

### 2. Beta Messaging System

**Status:** ✅ Live in Production
**Location:** `index.php` (Smart Panel)

- Admin kann Nachrichten an Beta-User senden
- User sehen Benachrichtigungen in Echtzeit
- Unterstützt verschiedene Nachrichtentypen (info, success, warning, question)
- Yes/No Responses möglich

---

### 3. Avatar Selection

**Status:** ✅ Live in Production
**Location:** `index.php` (Profile Tab)

- Beta-User können eigene Avatare auswählen
- 6 verschiedene Styles verfügbar (Dicebear API)
- Initials-Placeholder als Fallback
- Avatar-Würfel-Funktion

---

## 📁 Dateien-Übersicht

### Setup & Deployment
- `setup_last_minute.php` - Web-basiertes Setup für DB-Tabellen ✅
- `DEPLOYMENT_GUIDE.md` - Vollständiger Deployment-Guide
- `QUICKSTART.md` - 10-Minuten Quick Start
- `CRON_SETUP_HOSTER.md` - Cron-Job Setup für Shared Hosting

### Configuration & Security
- `.env.example` - Template für Environment Variables
- `check_calendly_token.php` - Token-Status prüfen
- Secret-Key Protection für HTTP-Endpoint

### Testing & Monitoring
- `test_last_minute.php` - Test-Helper mit Live-Dashboard
- `run_last_minute_checker.sh` - Bash-Wrapper (optional, falls Server-Cron verfügbar)

### User-Facing
- `index.php` - Beta-Dashboard
- `last_minute_settings.php` - Last-Minute Settings UI

### Backend
- `../admin/last_minute_checker.php` - Hauptlogik
- `../admin/last_minute_checker_cron.php` - HTTP-Endpoint für externe Cron-Services

---

## 🔒 Beta-Sicherheit

Alle Beta-Features folgen diesen Prinzipien:

### 1. User-Isolation
```php
WHERE customers.beta_access = 1  // Immer in allen Queries!
```

### 2. Access Control
```php
if (empty($customer['beta_access'])) {
    header('HTTP/1.1 404 Not Found');
    exit;
}
```

### 3. Sichere DB-Operationen
```sql
CREATE TABLE IF NOT EXISTS ...
FOREIGN KEY ... ON DELETE CASCADE
```

### 4. HTTP-Endpoint Security
```php
// Secret-Key + Same-Server Check
$hasValidSecret = !empty($_GET['secret']) && $_GET['secret'] === getenv('CRON_SECRET');
$isSameServer = ($remoteAddr === $serverAddr);
```

### 5. Error Handling
```php
try {
    // Beta feature code
} catch (Exception $e) {
    error_log($e->getMessage());
    // Graceful degradation - never crash
}
```

---

## 🚀 Neues Beta-Feature hinzufügen

### 1. Feature-Check erstellen
```php
if (empty($customer['beta_access'])) {
    header('HTTP/1.1 404 Not Found');
    exit;
}
```

### 2. Separate DB-Tabellen
```sql
CREATE TABLE IF NOT EXISTS beta_my_feature (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 3. UI im Beta-Ordner
- Erstelle `beta/my_feature.php`
- Verlinke in `beta/index.php`

### 4. Testing
- Manuell testen mit Beta-User
- Error-Handling prüfen
- Normal User testen (sollte 404 bekommen)

---

## 📊 Beta-User Management

**Aktivieren:**
- Admin-Dashboard: `../admin/toggle_beta.php`
- Direkt in DB: `UPDATE customers SET beta_access = 1 WHERE id = ?`

**Statistik:**
```sql
-- Anzahl Beta-User
SELECT COUNT(*) FROM customers WHERE beta_access = 1;

-- Last-Minute Feature Nutzung
SELECT
    (SELECT COUNT(*) FROM last_minute_subscriptions WHERE is_active = 1) as active_subs,
    (SELECT COUNT(*) FROM last_minute_notifications WHERE DATE(sent_at) = CURDATE()) as emails_today;
```

---

## 🐛 Debugging & Monitoring

### Logs
```bash
# Last-Minute Checker
tail -f ../admin/logs/last_minute_checker.log

# Erfolgreiche Benachrichtigungen
grep "sent$" ../admin/logs/last_minute_checker.log

# Fehler
grep "ERROR\|FAILED" ../admin/logs/last_minute_checker.log
```

### Web-Interface
```
https://www.einfachstarten.jetzt/einfachlernen/beta/test_last_minute.php
```
- Live-Statistiken
- Recent Notifications
- Manual Checker Execution
- Log-Viewer

### cron-job.org Dashboard
```
https://console.cron-job.org
```
- Execution History
- HTTP Status Codes
- Response Times
- E-Mail Alerts bei Fehlern

---

## 📋 Häufige Probleme

| Problem | Lösung |
|---------|--------|
| 404 bei Beta-Features | Beta-Access in DB prüfen |
| Keine E-Mails | SMTP-Config & Calendly Token prüfen |
| HTTP 400 von Calendly | Event-Type URIs in `services_catalog.php` aktualisieren |
| Cron läuft nicht | cron-job.org Dashboard prüfen |
| "Access denied" | CRON_SECRET in .htaccess setzen |

---

## 🎯 Deployment-Checkliste

- [x] DB-Tabellen erstellt (via `setup_last_minute.php`)
- [x] CALENDLY_TOKEN in .htaccess gesetzt
- [x] CRON_SECRET in .htaccess gesetzt
- [x] 3 Cron-Jobs bei cron-job.org eingerichtet
- [x] HTTP-Endpoint getestet (200 OK)
- [x] E-Mail-Versand validiert
- [x] Beta-User aktiviert
- [x] Feature in Produktion

---

## 🛠️ Technische Details

### Architektur
```
Beta-User aktiviert Feature in UI
    ↓
User-Settings in DB gespeichert (last_minute_subscriptions)
    ↓
cron-job.org ruft HTTP-Endpoint 3x täglich auf
    ↓
Script prüft Calendly API (nur für aktive Beta-Subscriptions)
    ↓
E-Mail wird versendet (max 3 pro User/Tag)
    ↓
Historie in DB gespeichert (last_minute_notifications)
    ↓
Logs werden geschrieben (admin/logs/last_minute_checker.log)
```

### Stack
- **Backend:** PHP 7.3+ (kompatibel)
- **DB:** MySQL mit InnoDB
- **Cron:** cron-job.org (externe Service)
- **E-Mail:** PHPMailer via SMTP
- **API:** Calendly REST API v2

### Sicherheitsfeatures
- ✅ Beta-only: `WHERE beta_access = 1`
- ✅ Rate-Limiting: Max 3 E-Mails/Tag/User
- ✅ Error Handling: Try-catch Blöcke
- ✅ Logging: Alle Fehler werden geloggt
- ✅ Isolated: Keine Auswirkung auf normale User
- ✅ Secret-Key: HTTP-Endpoint geschützt
- ✅ Foreign Keys: Automatisches Cleanup bei User-Löschung

---

## 📝 Changelog

### 2024-11-19 - PRODUCTION DEPLOYMENT ✅
- ✅ Last-Minute Notifications LIVE
- ✅ HTTP-Endpoint implementiert (shared hosting compatible)
- ✅ cron-job.org Integration
- ✅ Secret-Key Security
- ✅ Test-Interface mit Live-Dashboard
- ✅ Vollständige Dokumentation
- ✅ PHP 7.3 Kompatibilität
- ✅ Erfolgreich getestet auf World4You

### 2024-11-18
- ✅ Beta-Sicherheit dokumentiert
- ✅ Deployment-Guide erstellt
- ✅ Test-Helper und Monitoring-Tools
- ✅ Cron-Job Setup automatisiert

### 2024-11 (Earlier)
- ✅ Beta Messaging System
- ✅ Avatar Selection
- ✅ Beta Dashboard

---

## 🎓 Für Entwickler

**Best Practices:**
1. Immer `beta_access = 1` Check verwenden
2. `CREATE TABLE IF NOT EXISTS` für DB-Tabellen
3. Foreign Keys mit `ON DELETE CASCADE`
4. Try-catch für alle Beta-Features
5. Error-Logging aktivieren
6. Separate Files in `beta/` Ordner

**Code-Beispiel:**
```php
<?php
// Beta-Feature Template
session_start();
require_once __DIR__ . '/../customer/auth.php';

$customer = require_customer_login();

// CRITICAL: Beta-Access Check
if (empty($customer['beta_access'])) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$pdo = getPDO();

try {
    // Beta feature code here
} catch (Exception $e) {
    error_log("Beta feature error: " . $e->getMessage());
    // Graceful degradation
}
?>
```

---

## 📞 Support

- **Docs:** `DEPLOYMENT_GUIDE.md` (vollständig)
- **Quick Start:** `QUICKSTART.md` (10 Min)
- **Test:** `test_last_minute.php` (Web-Interface)
- **Logs:** `../admin/logs/`
- **DB Schema:** Tables erstellt via `setup_last_minute.php`

---

## 🔮 Roadmap

Mögliche zukünftige Beta-Features:
- [ ] Push Notifications (Web Push API)
- [ ] Dark Mode (Theme Switcher)
- [ ] Advanced Analytics Dashboard
- [ ] Custom Reminder Settings (User-defined times)
- [ ] Multi-Language Support
- [ ] SMS Notifications (Twilio Integration)
- [ ] Slot Alerts (Benachrichtigung wenn ein spezifischer Slot frei wird)

---

**Status:** ✅ Production-Ready | 🎉 Successfully Deployed | 🔒 Beta-Isolated | 📊 Monitored
