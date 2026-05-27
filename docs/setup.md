# Setup & Installation

## Prerequisites

| Requirement | Minimum | Notes |
|---|---|---|
| PHP | 8.3 | Extensions: pdo_mysql, curl, json, mbstring, openssl, zip |
| MySQL / MariaDB | 8.0 / 10.3+ | Or PostgreSQL 15+ |
| Web server | nginx / Apache | URL rewriting required |
| HTTPS certificate | — | Xero OAuth requires HTTPS; HTTP is rejected |
| Cron | — | Must call `cron.php` every minute |
| Node.js / npm | 22 | Only needed for frontend JS transpilation |
| Composer | 2.x | Only needed for dev/test dependencies |

## Installation

### Step 1 — Install EspoCRM

```bash
cd /path/to/webroot
git clone https://github.com/espocrm/espocrm.git espocrm
cd espocrm
composer install
```

### Step 2 — Set File Permissions

```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 data/ custom/ client/ application/
```

### Step 3 — Install the Xero Module

**From source:**

```bash
git clone https://github.com/bitsandbots/espocrm-xero.git
```

Then copy the module files into your EspoCRM installation and rebuild:

```bash
ESPO=/path/to/espocrm

cp -r espocrm-xero/custom/Espo/Modules/Xero   "$ESPO/custom/Espo/Modules/"
cp -r espocrm-xero/client/custom/modules/xero  "$ESPO/client/custom/modules/"
sudo chown -R www-data:www-data \
    "$ESPO/custom/Espo/Modules/Xero" \
    "$ESPO/client/custom/modules/xero"
php "$ESPO/command.php" rebuild
```

`rebuild` registers metadata, updates the database schema, and clears the server-side cache.

### Step 4 — Deploying Updates

After pulling changes to the `espocrm-xero` repo, re-sync and rebuild:

```bash
ESPO=/path/to/espocrm
XERO=/path/to/espocrm-xero

cp -r "$XERO/custom/Espo/Modules/Xero/."  "$ESPO/custom/Espo/Modules/Xero/"
cp -r "$XERO/client/custom/modules/xero/." "$ESPO/client/custom/modules/xero/"
php "$ESPO/command.php" rebuild
```

Then hard-refresh any open EspoCRM browser tabs (`Ctrl+Shift+R`) to pick up updated JS files.

### Step 5 — Configure Cron

Add to `/etc/crontab` or `crontab -e`:

```
* * * * * www-data php /path/to/espocrm/cron.php > /dev/null 2>&1
```

This executes EspoCRM scheduled jobs every minute, including `SyncFromXero` and `ReconcileXero`.

## HTTPS Setup (Required for Xero)

Xero OAuth requires HTTPS with a valid certificate. HTTP is rejected by Xero's authorization server.

### Development — mkcert

```bash
sudo apt-get install mkcert
sudo mkdir -p /etc/ssl/
sudo mkcert -key-file /etc/ssl/cake.local-key.pem \
            -cert-file /etc/ssl/cake.local.pem cake.local
```

### nginx Configuration

Create or update `/etc/nginx/sites-available/espocrm`:

```nginx
# HTTP redirect to HTTPS
server {
    listen 8080;
    server_name cake.local;
    return 301 https://$server_name:8443$request_uri;
}

# HTTPS server
server {
    listen 8443 ssl;
    server_name cake.local;
    root /path/to/espocrm;
    index index.php;

    ssl_certificate     /etc/ssl/cake.local.pem;
    ssl_certificate_key /etc/ssl/cake.local-key.pem;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /client {
        alias /path/to/espocrm/client;
        try_files $uri $uri/ =404;
    }

    location /api/v1/ {
        try_files $uri $uri/ /api/v1/index.php?$query_string;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.3-fpm-espocrm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_ESPO_CGI_AUTH $http_authorization;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~* \.(jpg|jpeg|gif|png|svg|js|css|ico|woff|woff2|ttf|eot)$ {
        try_files $uri =404;
        expires max;
        add_header Cache-Control "public";
    }

    location ^~ /data { deny all; }
    location ^~ /application { deny all; }
    location ^~ /custom { deny all; }
    location ^~ /vendor { deny all; }
    location ~ /\.ht { deny all; }
}
```

```bash
sudo ln -sf /etc/nginx/sites-available/espocrm /etc/nginx/sites-enabled/espocrm
sudo nginx -t
sudo systemctl reload nginx
```

Set the site URL in EspoCRM: **Admin → Settings → Site URL** → `https://cake.local:8443`

## Xero Integration Setup

### Step 1 — Create a Xero Developer App

1. Visit [developer.xero.com](https://developer.xero.com)
2. Sign in and go to **My Applications → New app**
3. Choose **OAuth 2.0**
4. Under **Redirect URIs**, add:
   ```
   https://your-espocrm-domain.com?entryPoint=XeroOauthCallback
   ```
   > Note: Xero requires HTTPS — HTTP redirect URIs are rejected.
5. Under **Scopes**, select:
   - `openid`
   - `profile`
   - `email`
   - `offline_access`
   - `accounting.transactions`
   - `accounting.contacts`

   > `accounting.transactions` covers invoices, credit notes, payments, and bank transactions.
   > `accounting.invoices` and `accounting.payments` are **not** valid Xero OAuth scopes.
6. Note your **Client ID** and **Client Secret**

### Step 2 — Find Your Default Account Code

Xero requires every invoice line item to reference a chart-of-accounts code.

In Xero: **Accounting → Chart of Accounts** → note the **Code** column for your income account
(e.g., `200` for Sales Revenue, `500` for Consulting Services).

### Step 3 — Configure in EspoCRM

1. Navigate to **Admin → Integrations → Xero**
2. Toggle **Enabled** to on
3. Enter **Client ID** and **Client Secret**
4. Enter **Default Account Code** (e.g., `200`)
5. Click **Save**

### Step 4 — Authorize with Xero

1. On the Xero integration page, click **Connect to Xero**
2. A popup opens the Xero authorization URL
3. Sign in with your Xero account and select the organisation to connect
4. Approve the requested scopes
5. The popup closes; the integration page refreshes and shows:
   - `tenantId` (Xero organisation UUID)
   - `connectedAt` (authorization timestamp)

> If you have multiple Xero organisations, only the first authorized one is stored.
> Multi-organisation support is a known future enhancement.

### Step 5 — Create Scheduled Jobs

In **Admin → Scheduled Jobs**, create these two jobs:

| Job Name | Schedule | Purpose |
|---|---|---|
| Xero: Sync from Xero | `0 2 * * *` (2:00 AM daily) | Pull Xero contact updates and payments |
| Xero: Reconcile | `15 2 * * *` (2:15 AM daily) | Push modified Accounts/Invoices to Xero |

Run Reconcile 15 minutes after Sync so conflicts pulled from Xero are applied before the outbound push.

You can also trigger either job manually from **Admin → Integrations → Xero → Run Sync**.

### Step 6 — Verify Integration

1. Create or edit an Account in EspoCRM with `name` and `emailAddress`, then save
2. Within 10 seconds, `xeroContactId` should auto-populate (check via Edit mode or the Xero panel)
3. In Xero: **Contacts** → verify the contact appears
4. Edit the Account name and save → Xero contact should update
5. Create an Invoice linked to the Account with status `Draft` and save
6. In Xero: **Invoices** → verify the invoice appears (status: DRAFT)

## Frontend Transpilation

Custom module JavaScript is transpiled to AMD modules at build time. This is required when
editing the source files under `client/custom/modules/xero/src/`.

```bash
# Transpile all custom modules (from EspoCRM root directory)
node js/transpile.js

# Transpile a specific file
node js/transpile.js -f client/custom/modules/xero/src/views/admin/integrations/xero.js
```

Output goes to `client/custom/modules/xero/lib/transpiled/src/`. The release package already
contains transpiled files; transpilation is only needed during development.

## CLI Reference

```bash
# Rebuild metadata, cache, and schema
php command.php rebuild

# Clear cache only (faster than rebuild)
php command.php clear-cache

# Run sync jobs manually
php command.php run-job --job-class="Espo\Modules\Xero\Jobs\SyncFromXero"
php command.php run-job --job-class="Espo\Modules\Xero\Jobs\ReconcileXero"

# Get/set config values
php command.php config:get --name=siteUrl
php command.php config:set --name=someKey --value=someValue

# Check database connection
php command.php db:check

# Set admin password
php command.php set-password --user-name=admin

# List all commands
php command.php --help
```

## Running Tests

Tests use EspoCRM's vendor PHPUnit. Set `ESPO_PATH` to your EspoCRM installation.

```bash
# Run the full Xero test suite (87 tests)
ESPO_PATH=/path/to/espocrm \
  /path/to/espocrm/vendor/bin/phpunit \
  --configuration phpunit.xml \
  --no-coverage

# Run a specific test file
ESPO_PATH=/path/to/espocrm \
  /path/to/espocrm/vendor/bin/phpunit \
  tests/unit/Espo/Modules/Xero/XeroServiceFieldMappingTest.php
```

## Project Structure

```
espocrm-xero/
├── custom/Espo/Modules/Xero/   Server-side PHP module
│   ├── Controllers/             initOAuth, runSync
│   ├── EntryPoints/             OAuth callback
│   ├── Hooks/                   afterSave hooks (Account, Contact, Invoice)
│   ├── Jobs/                    SyncFromXero, ReconcileXero
│   ├── Services/                XeroService (all API calls)
│   ├── Tools/                   ConflictResolver
│   └── Resources/               Metadata, layouts, i18n
├── client/custom/modules/xero/  Frontend AMD module
│   ├── src/                     JS source files
│   └── lib/transpiled/          Compiled AMD output
├── tests/unit/                  PHPUnit test suite
├── scripts/
│   └── release.sh               Release packaging script
├── docs/                        Documentation
└── releases/                    Built release ZIPs
```

## Environment & Config

Critical config values (set via **Admin → Settings**):

| Key | Example | Purpose |
|---|---|---|
| `siteUrl` | `https://cake.local:8443` | Used in OAuth redirect URI construction |
| `dateFormat` | `YYYY-MM-DD` | Date display format |
| `timezone` | `UTC` | Affects scheduled job timing |

## Logs & Debugging

- **All sync events**: `data/logs/espo.log`
- **Sync errors in UI**: Admin → Integrations → Xero → `lastSyncError` field
- **Watch sync activity**: `tail -f data/logs/espo.log | grep -i xero`

## Troubleshooting

### OAuth Fails: "Invalid Redirect URI"

**Cause:** `siteUrl` is not HTTPS, doesn't match the registered URI, or the browser hasn't
trusted the dev certificate.

**Fix:**
1. Verify `siteUrl` is exactly `https://cake.local:8443` (or your production domain)
2. For mkcert (dev): visit the URL in your browser and accept the security warning
3. Verify the exact URI registered in developer.xero.com matches your siteUrl

### Sync Jobs Do Not Run

**Cause:** Cron is not configured, or scheduled jobs are disabled.

**Fix:**
1. Verify cron: `sudo crontab -l | grep cron.php`
2. Test manually: `php /path/to/espocrm/cron.php`
3. Verify both jobs are enabled in **Admin → Scheduled Jobs**
4. Check `data/logs/espo.log` for scheduler errors

### xeroContactId Not Populating After Save

**Cause:** The hook fired but the Xero API call failed (check the log).

**Fix:**
1. Check `data/logs/espo.log` for "Xero Account sync failed"
2. Verify the integration is connected (tenantId is set)
3. Verify `clientId`/`clientSecret` are correct
4. Try reconnecting via **Admin → Integrations → Xero → Connect to Xero**

### Invoice Sync Fails: "No Xero Contact ID"

**Cause:** The linked Account has not been pushed to Xero yet.

**Fix:** Save the Account record once (triggers the hook), then save the Invoice again.
Or wait for the next nightly Reconcile run.

### Xero API Rejects Invoice: Account Code Error

**Cause:** `defaultAccountCode` is not set or references an inactive/non-existent account.

**Fix:** In **Admin → Integrations → Xero**, set a valid `defaultAccountCode`.
Use the Code column from **Xero → Accounting → Chart of Accounts**.

### Token Expiration Errors

**Cause:** Refresh token expired (~60 days of inactivity).

**Fix:** Reconnect via **Admin → Integrations → Xero → Connect to Xero**.

### OAuth Popup URL Contains `state=undefined`

**Cause:** The authorization URL was not generated by EspoCRM's PHP endpoint — it came from an
external source (Xero developer portal "Try it out" link, a bookmark, or a saved URL from a
previous failed attempt). The `state=undefined` string is JavaScript's `undefined` coerced to
a string, which EspoCRM's server-side URL builder cannot produce.

**Fix:**
1. Hard-refresh the EspoCRM integration page (`Ctrl+Shift+R` / `Cmd+Shift+R`) to clear any
   cached JavaScript
2. Navigate to **Admin → Integrations → Xero**
3. Click **Connect to Xero** — the popup URL should contain a 32-character hex state, a
   `code_challenge`, and `code_challenge_method=S256`
4. If the server returns a 500, check `data/logs/espo.log` for the root cause (common: DB
   connection error, missing clientId)

### Database Hostname Not Resolving (`espocrm-db`)

**Cause:** EspoCRM was originally configured in a Docker environment where the database was
reachable as `espocrm-db`. When running natively (nginx + php-fpm + local MariaDB), that
hostname is not in DNS.

**Fix:** Add the hostname to `/etc/hosts`:

```bash
sudo sh -c 'echo "127.0.0.1  espocrm-db" >> /etc/hosts'
```

Then ensure the MariaDB user is granted access from `localhost`:

```bash
sudo mysql -e "ALTER USER 'espocrm'@'localhost' IDENTIFIED BY 'your-password'; FLUSH PRIVILEGES;"
```

Alternatively, update `data/config-internal.php` to set `'host' => 'localhost'` directly.

### Payments Not Marking Invoices as Paid

**Cause:** The invoice was created in Xero directly, not pushed from EspoCRM, so there is
no matching `xeroInvoiceId` in EspoCRM.

**Fix:** Only invoices pushed from EspoCRM (which have a `xeroInvoiceId`) can be matched to
Xero payments. Create invoices in EspoCRM and let the sync push them to Xero.
