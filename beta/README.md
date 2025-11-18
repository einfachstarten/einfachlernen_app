# 🧪 Beta Features

Dieses Verzeichnis enthält alle Beta-Features für einfachlernen_app.

## ✅ Aktuelle Beta-Features

### 1. Last-Minute Slot Notifications

**Status:** ✅ Implementiert, ready for deployment
**User-Interface:** `last_minute_settings.php`
**Backend:** `../admin/last_minute_checker.php`
**Docs:** `DEPLOYMENT_GUIDE.md`

**Was es tut:**
- Automatische E-Mail-Benachrichtigungen wenn kurzfristige Termine frei werden
- Prüft 3x täglich (7:00, 12:00, 20:00 Uhr) die Calendly API
- Beta-User können Services auswählen (Lerntraining, Neurofeedback)
- Max. 3 E-Mails pro User pro Tag

**Deployment:**
1. `php setup_cli.php` - Erstellt DB-Tabellen
2. CALENDLY_TOKEN setzen - siehe `check_calendly_token.php`
3. Cron-Jobs einrichten - siehe `CRON_SETUP.md`
4. Testen mit `test_last_minute.php`

### 2. Beta Messaging System

**Status:** ✅ Live in Production
**Location:** `index.php` (Smart Panel)

- Admin kann Nachrichten an Beta-User senden
- User sehen Benachrichtigungen in Echtzeit
- Unterstützt verschiedene Nachrichtentypen (info, success, warning, question)

### 3. Avatar Selection

**Status:** ✅ Live in Production
**Location:** `index.php` (Profile Tab)

- Beta-User können eigene Avatare auswählen
- 6 verschiedene Styles verfügbar
- Initials-Placeholder als Fallback

## 📁 Dateien-Übersicht

### Setup & Deployment
- `setup_last_minute.php` - Web-basiertes Setup für DB-Tabellen
- `setup_cli.php` - CLI-Version des Setups
- `DEPLOYMENT_GUIDE.md` - Vollständiger Deployment-Guide (20+ Seiten)
- `QUICKSTART.md` - 30-Sekunden Quick Start
- `CRON_SETUP.md` - Detaillierte Cron-Job Anleitung

### Configuration
- `.env.example` - Template für Environment Variables
- `check_calendly_token.php` - Token-Status prüfen

### Testing
- `test_last_minute.php` - Test-Helper für manuelles Testing
- `run_last_minute_checker.sh` - Bash-Wrapper für Cron

### User-Facing
- `index.php` - Beta-Dashboard
- `last_minute_settings.php` - Last-Minute Settings UI

## 🔒 Beta-Sicherheit

Alle Beta-Features folgen diesen Prinzipien:

1. **Isolation:** Keine Auswirkung auf normale User
   ```php
   WHERE customers.beta_access = 1  // Immer!
   ```

2. **Sichere DB-Operationen:**
   ```sql
   CREATE TABLE IF NOT EXISTS ...
   FOREIGN KEY ... ON DELETE CASCADE
   ```

3. **Error Handling:**
   ```php
   try {
       // Beta feature code
   } catch (Exception $e) {
       error_log($e->getMessage());
       // Graceful degradation
   }
   ```

4. **Access Control:**
   ```php
   if (empty($customer['beta_access'])) {
       header('Location: ../customer/index.php');
       exit;
   }
   ```

## 🚀 Neues Beta-Feature hinzufügen

1. **Feature-Check erstellen:**
   ```php
   if (empty($customer['beta_access'])) {
       header('HTTP/1.1 404 Not Found');
       exit;
   }
   ```

2. **Separate DB-Tabellen:**
   ```sql
   CREATE TABLE IF NOT EXISTS beta_my_feature (
       id INT AUTO_INCREMENT PRIMARY KEY,
       customer_id INT NOT NULL,
       FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
   ) ENGINE=InnoDB;
   ```

3. **UI im Beta-Ordner:**
   - Erstelle `beta/my_feature.php`
   - Verlinke in `beta/index.php`

4. **Testing:**
   - Manuell testen mit Beta-User
   - Error-Handling prüfen
   - Normal User testen (sollte 404 bekommen)

## 📊 Beta-User Management

**Aktivieren:**
- Admin-Dashboard: `../admin/toggle_beta.php`
- Direkt in DB: `UPDATE customers SET beta_access = 1 WHERE id = ?`

**Statistik:**
```sql
-- Anzahl Beta-User
SELECT COUNT(*) FROM customers WHERE beta_access = 1;

-- Beta-Feature Nutzung
SELECT
    (SELECT COUNT(*) FROM last_minute_subscriptions WHERE is_active = 1) as lm_active,
    (SELECT COUNT(*) FROM beta_messages WHERE is_read = 0) as unread_messages;
```

## 🐛 Debugging

**Logs:**
```bash
# Last-Minute Checker
tail -f ../admin/logs/last_minute_checker.log

# PHP Errors
tail -f /var/log/php/error.log

# Apache/Nginx
tail -f /var/log/apache2/error.log
```

**Häufige Probleme:**

| Problem | Lösung |
|---------|--------|
| 404 bei Beta-Features | Beta-Access prüfen |
| Keine E-Mails | SMTP-Config & Calendly Token prüfen |
| DB-Fehler | Tabellen via setup_cli.php erstellen |
| Cron läuft nicht | `systemctl status cron` |

## 📞 Support

- **Docs:** Siehe `DEPLOYMENT_GUIDE.md`
- **Test:** Siehe `test_last_minute.php`
- **Logs:** `../admin/logs/`
- **DB Schema:** Siehe `setup_cli.php`

## 🎯 Roadmap

Mögliche zukünftige Beta-Features:
- [ ] Push Notifications (Web Push API)
- [ ] Dark Mode
- [ ] Advanced Analytics
- [ ] Custom Reminder Settings
- [ ] Multi-Language Support

## 📝 Changelog

### 2024-11-18
- ✅ Last-Minute Notifications implementiert
- ✅ Vollständiger Deployment-Guide
- ✅ Test-Helper und Monitoring-Tools
- ✅ Cron-Job Setup automatisiert
- ✅ Beta-Sicherheit dokumentiert

### 2024-11 (Earlier)
- ✅ Beta Messaging System
- ✅ Avatar Selection
- ✅ Beta Dashboard
