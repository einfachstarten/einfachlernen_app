# Calendly Email Scanner - Setup & Testing Guide

## 🎯 Purpose

The Calendly Email Scanner automatically extracts customer emails from Calendly bookings and syncs them to your database. This solves the problem where customers who only book via Calendly are not registered in your system.

## 🔧 Setup Instructions

### Step 1: Get Calendly API Credentials

1. **Get Personal Access Token:**
   - Go to https://calendly.com/integrations/api_webhooks
   - Click "Get a token" or "Create Token"
   - Copy the token (starts with `eyJ...`)

2. **Get Organization URI:**
   - Visit: https://api.calendly.com/users/me (while logged into Calendly)
   - Or use curl:
     ```bash
     curl -H "Authorization: Bearer YOUR_TOKEN" https://api.calendly.com/users/me
     ```
   - Copy the `current_organization` URI (e.g., `https://api.calendly.com/organizations/XXXXXX`)

### Step 2: Set Environment Variables

You need to set two environment variables on your server:

```bash
CALENDLY_TOKEN=your_calendly_personal_access_token
CALENDLY_ORG_URI=https://api.calendly.com/organizations/YOUR_ORG_ID
```

#### **Method A: Apache .htaccess (Recommended for shared hosting)**

Create or edit `/admin/.htaccess`:

```apache
SetEnv CALENDLY_TOKEN "eyJhbGci..."
SetEnv CALENDLY_ORG_URI "https://api.calendly.com/organizations/XXXXXX"
```

#### **Method B: PHP-FPM (VPS/Dedicated)**

Edit your PHP-FPM pool config (e.g., `/etc/php/8.x/fpm/pool.d/www.conf`):

```ini
env[CALENDLY_TOKEN] = eyJhbGci...
env[CALENDLY_ORG_URI] = https://api.calendly.com/organizations/XXXXXX
```

Then restart PHP-FPM:
```bash
sudo systemctl restart php8.2-fpm
```

#### **Method C: Server Config (cPanel/Plesk)**

- **cPanel:** Go to "Select PHP Version" → "Options" → Add environment variables
- **Plesk:** Go to "Domains" → "PHP Settings" → "Environment Variables"

### Step 3: Verify Environment Variables

Create a temporary test file `test_env.php`:

```php
<?php
session_start();
if(empty($_SESSION['admin'])){die('Login required');}

echo "CALENDLY_TOKEN: " . (getenv('CALENDLY_TOKEN') ? '✅ Set' : '❌ Not set') . "\n";
echo "CALENDLY_ORG_URI: " . (getenv('CALENDLY_ORG_URI') ? '✅ Set' : '❌ Not set') . "\n";
```

Access: `https://your-domain.com/admin/test_env.php`

**Delete this file after testing!**

## 🧪 Testing the Scanner

### **Option 1: Admin Test Page (Easiest)**

1. Log into your admin panel
2. Visit: `https://your-domain.com/admin/test_scanner.php`
3. Click "🔄 Scan Calendly Events & Save Emails"
4. View results and recent customers added

**Features:**
- ✅ Environment variable check
- ✅ One-click scanning
- ✅ Real-time results display
- ✅ View last 10 customers added

### **Option 2: Direct API Call**

```bash
curl https://your-domain.com/admin/calendly_email_scanner.php
```

**Expected Response:**
```json
{
    "success": true,
    "events_scanned": 42,
    "emails_found": 35,
    "new_count": 12,
    "existing_count": 23,
    "duration_seconds": 8.45
}
```

### **Option 3: Command Line (SSH Access)**

```bash
cd /path/to/einfachlernen_app
php admin/calendly_email_scanner.php
```

## 📊 Verifying Results

### Check Database Directly

```sql
-- See newest customers
SELECT email, first_name, last_name, created_at
FROM customers
ORDER BY created_at DESC
LIMIT 20;

-- Count total customers
SELECT COUNT(*) as total_customers FROM customers;
```

### Check Server Logs

The scanner writes detailed logs. View them via:

```bash
# Apache error log
tail -f /var/log/apache2/error.log

# PHP-FPM log
tail -f /var/log/php8.2-fpm.log
```

**Expected log entries:**
```
=== Calendly Email Scanner Started ===
Fetching events page 1: https://api.calendly.com/scheduled_events?...
Page 1: Retrieved 100 events
Retrieved 100 scheduled events
Extracted 85 unique email addresses
Inserted new customer: john@example.com
Skipped existing: jane@example.com
Database save complete: 12 new, 73 existing
=== Scanner Completed in 8.45s ===
```

## ⚙️ Configuration Options

You can modify the scanner's behavior by editing `admin/calendly_email_scanner.php`:

```php
class CalendlyEmailScanner {
    private $rate_limit_delay = 200000;  // 200ms delay (in microseconds)
    private $rate_limit_batch = 5;        // Apply delay every 5 requests
    private $max_pages = 10;              // Max event pages to fetch
    private $max_invitee_calls = 100;     // Max invitee API calls
}
```

### Date Range Configuration

Default range: **-12 months to +6 months**

To change, edit in `getAllScheduledEvents()`:

```php
$min_start = date('c', strtotime('-12 months'));  // Change to -6 months, -3 months, etc.
$max_start = date('c', strtotime('+6 months'));   // Change to +3 months, +12 months, etc.
```

## 🔄 Scheduling (Automated Scanning)

### Cron Job Setup

Add to crontab to run daily at 3 AM:

```bash
crontab -e
```

Add line:
```cron
0 3 * * * /usr/bin/php /path/to/einfachlernen_app/admin/calendly_email_scanner.php >> /var/log/calendly_scanner.log 2>&1
```

### Using Existing Cron Pattern

Follow the same pattern as `last_minute_checker_cron.php`:

1. Create `calendly_scanner_cron.php` with secret authentication
2. Call via external cron service (e.g., cron-job.org)
3. Protect with `CRON_SECRET` environment variable

## 🐛 Troubleshooting

### "Missing environment variables" Error

**Cause:** `CALENDLY_TOKEN` or `CALENDLY_ORG_URI` not set

**Fix:**
1. Verify `.htaccess` or PHP-FPM config
2. Restart web server: `sudo systemctl restart apache2` or `sudo systemctl restart php-fpm`
3. Check `phpinfo()` to see if variables are visible

### "API error: Unauthenticated (HTTP 401)"

**Cause:** Invalid or expired Calendly token

**Fix:**
1. Generate new token at https://calendly.com/integrations/api_webhooks
2. Update `CALENDLY_TOKEN` environment variable
3. Restart web server

### "No events found" / 0 Events Scanned

**Causes:**
- Wrong organization URI
- No events in date range (-12 to +6 months)
- API rate limiting

**Fix:**
1. Verify `CALENDLY_ORG_URI` is correct
2. Check Calendly dashboard for actual bookings
3. Adjust date range in code if needed

### Duplicate Key Errors

**This is normal!** The scanner handles duplicates gracefully:
- Checks for existing emails before inserting
- Catches duplicate key exceptions
- Counts as "existing_count" in results

### Rate Limiting / HTTP 429

**Cause:** Too many API requests

**Fix:**
1. Increase `rate_limit_delay` (e.g., 500000 = 500ms)
2. Decrease `rate_limit_batch` (e.g., 3 = delay every 3 requests)
3. Wait and retry later

## 📈 Performance

**Typical Performance:**
- 100 events + 100 invitee calls = ~30-45 seconds
- Rate limiting: 200ms delay every 5 requests
- Network latency: Depends on server location

**Optimization Tips:**
- Run during off-peak hours (3-5 AM)
- Adjust date range to scan only recent events
- Use cron job instead of manual runs

## 🔒 Security Notes

- ✅ Uses prepared statements (SQL injection safe)
- ✅ Email validation with `FILTER_VALIDATE_EMAIL`
- ✅ API credentials via environment variables (not in code)
- ✅ Admin authentication required
- ✅ Error messages logged, not exposed to users

**Recommendations:**
- Keep Calendly token secret (never commit to git)
- Use HTTPS for all API calls
- Regularly rotate API tokens (quarterly)
- Monitor logs for suspicious activity

## 📝 Success Criteria Checklist

After first run, verify:

- ✅ No PHP errors in logs
- ✅ No duplicate key errors (or gracefully handled)
- ✅ New customers appear in database
- ✅ `first_name` and `last_name` populated
- ✅ Rate limiting logs show delays applied
- ✅ Scan completes in reasonable time (<2 minutes for 100 events)

## 📞 Support

If you encounter issues:

1. Check server error logs
2. Verify environment variables are set
3. Test Calendly API manually with curl
4. Review `CALENDLY_SCANNER_SETUP.md` (this file)

For debugging, enable detailed logging:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

---

**Last Updated:** 2026-01-05
**Version:** 1.0
**Author:** Claude (AI Assistant)
