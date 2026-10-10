# HEHMHS Portal — Deployment Runbook

Step-by-step guide for putting the system live on shared hosting
(written for **Hostinger**, but the order works for any cPanel-style host).

## What you need before starting

- Hosting account with: **PHP 8.1+** (pick 8.3), **MySQL**, cron jobs, free SSL
- PHP extensions enabled (hPanel → PHP Configuration):
  `gd`, `mbstring`, `mysqli`, `openssl`, `dom`, `simplexml`
- The values from your LOCAL `database/config.php`:
  - SMTP block (account + app password)
  - `VERIFICATION_SECRET` (copy character-for-character — never change it)

---

## 1. Files

1. hPanel → File Manager → `public_html`
2. Upload the project ZIP (from GitHub: Code → Download ZIP), **or** clone via
   the hPanel Git tool / SSH: `git clone <repo-url> .`
3. If you uploaded the ZIP **without** a `vendor/` folder: hPanel →
   Advanced → SSH/Composer → `composer install --no-dev` (or upload your
   local `vendor/` folder via File Manager — slower but works)
4. Make sure `uploads/`, `uploads/e_certificates/`, `uploads/profile_photos/`,
   `uploads/request_requirements/` exist and are writable
   (they are in the repo via the `.htaccess` files; create any missing folder)

## 2. Database

1. hPanel → Databases → **Create New Database**
   - note the generated name / user / your password
2. Open **phpMyAdmin** (from hPanel) → select the new database →
   **Import** → `database/schema.sql` → Go
   - 9 tables + 3 seed accounts are created
3. **Immediately change the seed passwords**: log in as each and change,
   or delete the demo student:
   - `registrar@hehmhs.edu.ph` / `Registrar@123`
   - `hehmhsadmin@gmail.com` / `Admin@123`
   - `juan@hehmhs.edu.ph` / `Student@123` (demo — delete on live)

## 3. Config

Copy `database/config.example.php` → `database/config.php` and fill:

| Constant | Value |
|---|---|
| `DB_HOST` | `localhost` (or what hPanel shows) |
| `DB_NAME`, `DB_USER`, `DB_PASS` | the three values from step 2.1 |
| `SMTP_*` | **COPY from local** (hehmhsadmin@gmail.com + app password) |
| `VERIFICATION_SECRET` | **COPY EXACTLY from local config.php** |
| `MAIL_ENABLED` | `true` |

## 4. Domain & SSL

1. Point the domain / use the free subdomain
2. hPanel → Security → **SSL** → install free Let's Encrypt → force HTTPS
   (the app's `.htaccess` already 301-redirects HTTP→HTTPS)
3. If the site is NOT at the domain root, update the three
   `ErrorDocument` lines in `.htaccess` to include the folder path

## 5. Cron (monthly report e-mail)

hPanel → Advanced → Cron Jobs → daily 00:30:

```
/usr/bin/php /home/uXXXXXXX/domains/<domain>/public_html/phpLogics/cron_monthly_report.php
```

(adjust the path — File Manager shows the absolute path at the top).
Self-throttled: runs daily, sends once per month.

## 6. Post-deploy verification checklist

- [ ] Login page loads with padlock (https)
- [ ] Log in as each role once
- [ ] Student submits a request → registrar processes → releases
- [ ] Student receives e-mail with PDF; PDF opens
- [ ] **Scan the QR on the PDF with a phone** → verify.php confirms
      (QR must show the live domain — if it says `localhost`, the
      certificate was generated before deploy; release a fresh one)
- [ ] Visit a bogus URL → branded HEHMHS 404 page
- [ ] Direct URL to an e-certificate PDF → **403 Forbidden**
- [ ] Send a test e-mail (resend credentials flow) → arrives

## 7. Aftercare

- Nightly backup: hPanel → Files → Backups (or cron a `mysqldump` via SSH)
- Watch PHP error log the first week (hPanel → Advanced → PHP errors)
- Gmail free tier caps ~500 recipients/day — plenty for one school
- Never regenerate `VERIFICATION_SECRET`; keep it out of git
