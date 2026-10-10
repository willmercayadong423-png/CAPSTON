# HEHMHS — SMTP / Email Delivery Setup Guide

**Status:** PREPARED — nothing in `database/config.php` has been changed yet.
When you're ready, pick ONE option below and paste its block into
`database/config.php`, replacing the whole `── SMTP (mail sending) ──` section.

---

## Why change anything?

The system currently sends through a **free Gmail account** (`hehmhsadmin@gmail.com`).
Gmail free accounts are capped at roughly **500 recipients per day** (sometimes
lower for new accounts), and the cap resets at **midnight Pacific time
(~3:00 PM Philippine time)**. On 2026-10-08 the cap was hit and every mail
(releases, welcome mails, notifications) was refused with
`550 5.4.5 Daily user sending limit exceeded` until the reset.

For a school issuing certificates to hundreds of students, use a proper
sending setup. Options, easiest first.

---

## Option A — Brevo (recommended · free 300 emails/day)

1. Sign up at **https://www.brevo.com** (free "Starter" plan, no card needed).
2. Confirm your account email, then in the Brevo dashboard:
   **SMTP & API → SMTP → Create a new SMTP key** — copy the generated key
   (it looks like `xkeysib-…`).
3. Verify the **sender**: **Senders & IP → Senders → Add a sender**
   (e.g. `registrar@hehmhs.edu.ph` or your Gmail — must be confirmed once).
4. Paste this into `database/config.php` (replace the old SMTP block):

```php
// ── SMTP (mail sending) — Brevo transactional ──
define('SMTP_HOST',   'smtp-relay.brevo.com');
define('SMTP_USER',   'YOUR_BREVO_LOGIN_EMAIL');      // the email you signed up with
define('SMTP_PASS',   'xkeysib-YOUR_GENERATED_SMTP_KEY');
define('SMTP_PORT',   587);
define('SMTP_SECURE', 'tls'); // STARTTLS
define('SMTP_FROM',   'YOUR_VERIFIED_SENDER@ADDRESS');
define('SMTP_FROM_NAME', 'HEHMHS Registrar');
```

> 300 mails/day free, dedicated transactional relay, no "daily user limit"
> games. After 50 student imports + normal release mails, huge headroom.

---

## Option B — Google Workspace (keeps Gmail · 2,000/day)

If the school has (or gets) a Google Workspace subscription
(`@hehmhs.edu.ph` domain):

1. Enable **2-Step Verification** on the sending account.
2. Google Account → Security → **App passwords** → generate one for "Mail".
3. Paste this into `database/config.php`:

```php
// ── SMTP (mail sending) — Google Workspace ──
define('SMTP_HOST',   'smtp.gmail.com');
define('SMTP_USER',   'registrar@hehmhs.edu.ph');      // Workspace account
define('SMTP_PASS',   'YOUR_16_CHAR_APP_PASSWORD');   // no spaces needed
define('SMTP_PORT',   587);
define('SMTP_SECURE', 'tls'); // STARTTLS
define('SMTP_FROM',   'registrar@hehmhs.edu.ph');
define('SMTP_FROM_NAME', 'HEHMHS Registrar');
```

> 2,000 recipients/day. Same code, only credentials change.

---

## Option C — SendGrid (free 100/day)

1. Sign up at **https://sendgrid.com** (free plan).
2. **Settings → API Keys → Create API Key** (Full access), copy it.
3. **Settings → Sender Authentication → Verify a Single Sender.**
4. Paste into `database/config.php`:

```php
// ── SMTP (mail sending) — SendGrid ──
define('SMTP_HOST',   'smtp.sendgrid.net');
define('SMTP_USER',   'apikey');                       // literally the string "apikey"
define('SMTP_PASS',   'SG.YOUR_SENDGRID_API_KEY');
define('SMTP_PORT',   587);
define('SMTP_SECURE', 'tls'); // STARTTLS
define('SMTP_FROM',   'YOUR_VERIFIED_SENDER@ADDRESS');
define('SMTP_FROM_NAME', 'HEHMHS Registrar');
```

> 100/day free — fine for small volume; tighter than Brevo.

---

## After switching — test it (30 seconds)

From the project root (Laragon PHP):

```
C:\laragon\bin\php\php-<version>\php.exe -r "require 'vendor/autoload.php'; require 'database/config.php'; $m = new PHPMailer\PHPMailer\PHPMailer(true); $m->isSMTP(); $m->Host=SMTP_HOST; $m->SMTPAuth=true; $m->Username=SMTP_USER; $m->Password=SMTP_PASS; $m->SMTPSecure='tls'; $m->Port=SMTP_PORT; $m->setFrom(SMTP_FROM, SMTP_FROM_NAME); $m->addAddress('YOUR_OWN_EMAIL'); $m->Subject='HEHMHS SMTP test'; $m->Body='It works.'; $m->send(); echo 'SENT OK';"
```

(`php composer.phar`-installed PHPMailer is used — no other dependency.)

Then release any request (or use **Resend Email** on a released one) and
confirm the delivery + the audit entry in Admin → Audit Logs.

## Reminders

- `MAIL_ENABLED` (bottom of config.php) must stay **`true`** — it is only a
  temporary kill-switch used by the e2e test suites (they flip it off for the
  run and restore it automatically).
- Changing `VERIFICATION_SECRET` invalidates all printed QR codes — unrelated
  to SMTP, just don't touch it while switching mail.
- Keep `database/config.php` out of git (it already is — `.gitignore`).
