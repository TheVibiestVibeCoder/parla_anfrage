# Mailing List Setup Documentation

## Overview

The mailing list feature allows users to subscribe to daily email updates about new NGO-related parliamentary inquiries (Anfragen). The system consists of:

1. **MailingListDB.php** - SQLite database manager with rate limiting
2. **mailingliste.php** - Public signup page (GDPR-compliant)
3. **send-daily-emails.php** - Daily email sender script

## Features

- ✅ GDPR-compliant subscription with explicit consent
- ✅ DDoS protection with rate limiting (3 attempts per hour)
- ✅ SQLite database (no external database server needed)
- ✅ HTML email templates matching site design
- ✅ Funny messages when no new entries are found
- ✅ Email sending logs and statistics

## Installation

### 1. File Permissions

Ensure the web server can write to the directory:

```bash
chmod 755 /path/to/parla_anfrage
chmod 644 /path/to/parla_anfrage/*.php
```

The SQLite database file will be created automatically with proper permissions.

### 2. Test the Signup Page

Visit: `https://your-domain.com/mailingliste.php`

Try signing up with a test email to verify the database is created correctly.

### 3. Set Up Cron Job

The `send-daily-emails.php` script should run daily at 20:00 (8 PM).

#### Option A: User Crontab

```bash
crontab -e
```

Add this line:

```
0 20 * * * /usr/bin/php /path/to/parla_anfrage/send-daily-emails.php >> /path/to/parla_anfrage/email-sender.log 2>&1
```

#### Option B: System Crontab

Create a file `/etc/cron.d/ngo-business-mailer`:

```
0 20 * * * www-data /usr/bin/php /path/to/parla_anfrage/send-daily-emails.php >> /var/log/ngo-business-mailer.log 2>&1
```

#### Option C: Test Run Manually

Test the script manually first:

```bash
php /path/to/parla_anfrage/send-daily-emails.php
```

Expected output:

```
=== NGO Business Tracker - Daily Email Sender ===
Starting at: 2026-01-22 20:00:00

Active subscribers: X
Fetching new entries from Parliament API...
Found Y new entries in the last 24 hours.
Sending emails to X subscribers...
Emails sent: X successful, 0 failed

=== Completed successfully at: 2026-01-22 20:00:15 ===
```

## Database Structure

The SQLite database (`mailinglist.db`) contains:

### Tables

1. **subscribers**
   - `id` - Primary key
   - `email` - Subscriber email (unique)
   - `subscribed_at` - Subscription timestamp
   - `confirmed` - Email confirmation status (currently auto-confirmed)
   - `confirmation_token` - Token for future double opt-in
   - `ip_address` - IP address at signup (for abuse prevention)
   - `user_agent` - Browser user agent
   - `last_email_sent` - Timestamp of last email
   - `active` - Subscription status (1 = active, 0 = unsubscribed)

2. **rate_limits**
   - `id` - Primary key
   - `ip_address` - IP address
   - `action` - Action type (e.g., 'signup')
   - `attempt_time` - Timestamp of attempt

3. **email_log**
   - `id` - Primary key
   - `sent_at` - Email send timestamp
   - `recipient_count` - Number of recipients
   - `had_new_entries` - Whether new entries were found
   - `entry_count` - Number of new entries
   - `success` - Send success status

## Security Features

### Rate Limiting

- Maximum 3 signup attempts per IP per hour
- Automatic cleanup of old rate limit entries (>24 hours)
- Database-level unique constraint on email addresses

### Input Validation

- Email format validation (PHP `filter_var`)
- GDPR consent checkbox required
- IP address validation for proxied requests
- XSS prevention via `htmlspecialchars()`

### DDoS Protection

- Request throttling per IP address
- Minimal resource usage (file-based SQLite)
- Fast response times with proper indexing

## Email Content

### When New Entries Exist

- Subject: "📋 X neue NGO-Anfrage(n) | NGO Business Tracker"
- HTML email with:
  - List of new entries
  - Party badges with colors
  - Date and title
  - Link to parliament.gv.at

### When No New Entries Exist

- Subject: "😴 Heute war die FPÖ wohl faul | NGO Business Tracker"
- Funny random message, examples:
  - "Aber keine Sorge, morgen kommt bestimmt was!"
  - "Vielleicht haben sie heute ausnahmsweise Urlaub? 🏖️"
  - "Die Anfrage-Maschinerie macht wohl Pause... für einen Tag."
  - etc.

## Monitoring

### Check Subscriber Count

```bash
sqlite3 /path/to/parla_anfrage/mailinglist.db "SELECT COUNT(*) FROM subscribers WHERE active=1;"
```

### View Recent Signups

```bash
sqlite3 /path/to/parla_anfrage/mailinglist.db "SELECT email, datetime(subscribed_at, 'localtime') FROM subscribers ORDER BY subscribed_at DESC LIMIT 10;"
```

### Check Email Logs

```bash
sqlite3 /path/to/parla_anfrage/mailinglist.db "SELECT datetime(sent_at, 'localtime'), recipient_count, had_new_entries, entry_count, success FROM email_log ORDER BY sent_at DESC LIMIT 10;"
```

### View Cron Logs

```bash
tail -f /path/to/parla_anfrage/email-sender.log
```

## Troubleshooting

### Emails Not Sending

1. Check PHP `mail()` function is configured:
   ```bash
   php -r "var_dump(mail('test@example.com', 'Test', 'Test'));"
   ```

2. Check cron is running:
   ```bash
   grep CRON /var/log/syslog
   ```

3. Check error logs:
   ```bash
   tail -f /path/to/parla_anfrage/email-sender-errors.log
   ```

### Database Locked Errors

SQLite uses file-level locking. If you get "database is locked" errors:

1. Ensure only one cron job runs at a time
2. Check for long-running processes
3. Consider WAL mode (already enabled in code)

### Rate Limiting Too Strict

Edit `mailingliste.php` line ~30:

```php
if ($db->checkRateLimit($clientIP, 'signup', 3, 60)) {
                                            // ^^ attempts  ^^ minutes
```

Increase the numbers as needed.

## Future Enhancements

Potential improvements (not currently implemented):

1. **Double Opt-In**
   - Send confirmation email before activating subscription
   - Use the `confirmation_token` field

2. **Unsubscribe Links**
   - Add unique unsubscribe token to each email
   - Create `unsubscribe.php` page

3. **Email Preferences**
   - Allow users to choose delivery time
   - Filter by specific parties
   - Weekly digest option

4. **Admin Panel**
   - View subscriber statistics
   - Manually trigger email sends
   - Export subscriber list

5. **SMTP Integration**
   - Use PHPMailer or similar
   - Better deliverability vs. PHP mail()

## GDPR Compliance

The system implements basic GDPR requirements:

- ✅ Explicit consent checkbox
- ✅ Link to privacy policy (Impressum)
- ✅ Purpose limitation (only newsletter sending)
- ✅ Data minimization (only email + metadata)
- ⚠️ Missing: Easy unsubscribe mechanism (should be added)
- ⚠️ Missing: Data export capability (should be added)
- ⚠️ Missing: Double opt-in confirmation (should be added)

## Support

For issues or questions:

- Email: kontakt@ngo-business.com
- Check logs: `email-sender-errors.log`
- Database queries: Use SQLite command line

## License

Same as parent project (NGO Business Tracker).
