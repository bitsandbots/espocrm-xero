# Setup & Installation

## Prerequisites

| Requirement | Minimum | Notes |
|-------------|---------|-------|
| PHP | 8.3 | Extensions: pdo_mysql, curl, json, mbstring, openssl, zip |
| MySQL / MariaDB | 8.0 / 10.3+ | Or PostgreSQL 15+ |
| Web server | nginx / Apache | URL rewriting required |
| Composer | 2.x | For dependency management |
| Node.js / npm | 22 | Only needed for frontend build (transpile custom modules) |
| Cron | — | Must call `cron.php` every minute |
| HTTPS (for Xero) | — | Xero OAuth requires HTTPS; Intuit QB accepts both HTTP and HTTPS |

## Installation

### Step 1 — Clone EspoCRM

```bash
cd /home/coreconduit
git clone https://github.com/espocrm/espocrm.git espocrm
cd espocrm
```

### Step 2 — Install PHP Dependencies

```bash
composer install
```

This installs core EspoCRM, PHPUnit, PHPStan, and all custom module dependencies.

### Step 3 — Set File Permissions

```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 data/ custom/ client/ application/
```

### Step 4 — Rebuild Metadata & Schema

```bash
php rebuild.php
```

This registers all module metadata, creates the database schema, and caches the config. Run this after:
- Pulling code changes
- Adding/modifying any custom module (QuickBooks, Xero, etc.)
- Updating integration metadata

### Step 5 — Configure Cron

Add to `/etc/crontab` or `crontab -e`:

```
* * * * * www-data php /home/coreconduit/espocrm/cron.php > /dev/null 2>&1
```

This executes scheduled jobs every minute (SyncFromQuickBooks, SyncFromXero, ReconcileQuickBooks, ReconcileXero, etc.).

## HTTPS Setup (Required for Xero)

Xero OAuth requires HTTPS with a valid certificate. This instance uses mkcert for local development and nginx reverse proxy on port 8443.

### Step 1 — Generate HTTPS Certificate with mkcert

```bash
# Install mkcert (if not already installed)
sudo apt-get install mkcert

# Generate certificate for cake.local
sudo mkdir -p /etc/ssl/
sudo mkcert -key-file /etc/ssl/cake.local-key.pem -cert-file /etc/ssl/cake.local.pem cake.local
```

### Step 2 — Configure nginx for HTTPS

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
    root /home/coreconduit/espocrm;
    index index.php;

    # SSL certificates
    ssl_certificate /etc/ssl/cake.local.pem;
    ssl_certificate_key /etc/ssl/cake.local-key.pem;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';" always;

    # URL rewriting
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny direct access to sensitive directories
    location ~* ^/(data|custom|application|vendor)/ {
        deny all;
    }

    # Block .htaccess and config files
    location ~ /\. {
        deny all;
    }
}
```

### Step 3 — Enable the Site and Reload nginx

```bash
sudo ln -sf /etc/nginx/sites-available/espocrm /etc/nginx/sites-enabled/espocrm
sudo nginx -t
sudo systemctl reload nginx
```

### Step 4 — Update EspoCRM Site URL

After installing EspoCRM, set the `siteUrl` in **Administration → Settings**:

```
https://cake.local:8443
```

This is critical for both QuickBooks and Xero OAuth callbacks to work correctly.

## QuickBooks Integration Setup

### Step 1 — Create a QB Developer App

1. Visit [developer.intuit.com](https://developer.intuit.com)
2. Sign in with your Intuit account (create one if needed)
3. Click **Create an app**
4. Select **QuickBooks Online and Payments**
5. Enter an app name (e.g., "EspoCRM-QB")
6. In **Keys & credentials**, note:
   - `Client ID`
   - `Client Secret`
7. Under **Redirect URIs**, add:
   ```
   https://cake.local:8443?entryPoint=QuickBooksOauthCallback
   ```
8. In **Scopes**, ensure `com.intuit.quickbooks.accounting` is selected
9. Save the app

### Step 2 — Configure in EspoCRM

1. Navigate to **Administration → Integrations → QuickBooks**
2. Toggle **Enabled** to on
3. Paste the `Client ID` and `Client Secret`
4. Click **Save**

### Step 3 — Authorize with QuickBooks

1. On the QuickBooks integration page, click **Connect to QuickBooks**
2. A popup opens the QB authorization URL
3. Sign in with your QB account and approve the scope request
4. The popup closes; the integration page refreshes and populates:
   - `realmId` (QB company ID)
   - `connectedAt` (timestamp)

### Step 4 — Create Scheduled Jobs

In **Administration → Scheduled Jobs**, add these two jobs:

| Job Class | Schedule | Purpose |
|-----------|----------|---------|
| `Espo\Modules\QuickBooks\Jobs\SyncFromQuickBooks` | `0 2 * * *` (2 AM daily) | Pull customers and payments from QB |
| `Espo\Modules\QuickBooks\Jobs\ReconcileQuickBooks` | `0 3 * * *` (3 AM daily) | Push modified Accounts/Invoices to QB |

You can also run these manually via **Administration → Integrations → QuickBooks** and click **Run Sync**.

### Step 5 — Verify Integration

1. Create or edit an Account in EspoCRM
2. Fill in `name`, `emailAddress`, `phoneNumber`, and save
3. Within 10 seconds, the `qbCustomerId` field should auto-populate
4. In QuickBooks Online, go to **Customers** and verify the customer appears
5. Modify an Account field and wait ~10 seconds — QB should update
6. Create an Invoice linked to the Account and save — it should appear in QB

## Xero Integration Setup

### Step 1 — Create a Xero App

1. Visit [developer.xero.com](https://developer.xero.com)
2. Sign in with your Xero account (create one if needed)
3. Go to **My Applications** → **Create app**
4. Enter an app name (e.g., "EspoCRM-Xero")
5. Select **OAuth 2.0**
6. In **Redirect URIs**, add:
   ```
   https://cake.local:8443?entryPoint=XeroOauthCallback
   ```
   (Note: HTTP is not supported by Xero — HTTPS is mandatory)
7. In **Scopes**, select:
   - `accounting.contacts`
   - `accounting.invoices`
   - `accounting.payments`
8. Note the:
   - `Client ID`
   - `Client Secret`
9. Save the app

### Step 2 — Configure in EspoCRM

1. Navigate to **Administration → Integrations → Xero**
2. Toggle **Enabled** to on
3. Paste the `Client ID` and `Client Secret`
4. Optionally set `defaultAccountCode` (e.g., "200" for sales revenue) — used when creating invoices
5. Click **Save**

### Step 3 — Authorize with Xero

1. On the Xero integration page, click **Connect to Xero**
2. A popup opens the Xero authorization URL
3. Sign in with your Xero account and select the organisation to connect
4. Approve the requested scopes
5. The popup closes; the integration page refreshes and populates:
   - `tenantId` (Xero organization ID)
   - `connectedAt` (timestamp)

Note: If you have multiple Xero organisations, only the first authorized organisation is stored. Multiple organisations are not yet supported.

### Step 4 — Create Scheduled Jobs

In **Administration → Scheduled Jobs**, add these two jobs:

| Job Class | Schedule | Purpose |
|-----------|----------|---------|
| `Espo\Modules\Xero\Jobs\SyncFromXero` | `0 2 * * *` (2 AM daily) | Pull contacts and payments from Xero |
| `Espo\Modules\Xero\Jobs\ReconcileXero` | `0 3 * * *` (3 AM daily) | Push modified Accounts/Invoices to Xero |

You can also run these manually via **Administration → Integrations → Xero** and click **Run Sync**.

### Step 5 — Verify Integration

1. Create or edit an Account in EspoCRM
2. Fill in `name`, `emailAddress`, and save
3. Within 10 seconds, the `xeroContactId` field should auto-populate
4. In Xero, go to **Contacts** and verify the contact appears
5. Modify an Account field and wait ~10 seconds — Xero should update
6. Create an Invoice linked to the Account, set `status=Draft`, and save — it should appear in Xero

## Frontend Transpilation

Custom module JavaScript/TypeScript (QuickBooks and Xero views) is transpiled to AMD modules before deployment. This is automatic during the development workflow:

```bash
# Transpile all custom modules (auto-run on npm install)
npm run transpile

# Transpile a single file (after editing it)
npm run transpile -- -f client/custom/modules/quick-books/src/views/admin/integrations/quick-books.js
```

The transpiler outputs to:
- QB: `client/custom/modules/quick-books/lib/transpiled/src/`
- Xero: `client/custom/modules/xero/lib/transpiled/src/`

These are served by the browser loader without further processing.

## Useful CLI Commands

```bash
# Rebuild metadata, cache, and schema
php rebuild.php

# Clear cache only (faster than rebuild)
php command.php clear-cache

# List all CLI commands
php command.php --help

# Run a specific job manually
php command.php run-job --job-class="Espo\Modules\QuickBooks\Jobs\SyncFromQuickBooks"
php command.php run-job --job-class="Espo\Modules\Xero\Jobs\SyncFromXero"

# Check database connection
php command.php db:check

# Set admin password
php command.php set-password --user-name=admin

# Get/set config values
php command.php config:get --name=siteUrl
php command.php config:set --name=someKey --value=someValue
```

## Running Tests

### PHP Unit Tests

```bash
# Install dev dependencies (if not already done)
composer install

# Run QuickBooks module tests only
vendor/bin/phpunit tests/unit/Espo/Modules/QuickBooks/

# Run Xero module tests only
vendor/bin/phpunit tests/unit/Espo/Modules/Xero/

# Run both in one go
vendor/bin/phpunit --filter "QuickBooks|Xero"

# Full unit suite
vendor/bin/phpunit --testsuite unit
```

### Frontend Tests (Jasmine Browser)

Browser-based tests require the dev server running:

```bash
# Start dev server on port 8080
npm run serve

# In another terminal, run tests
npm test
```

Tests run in a browser context and include views, hooks, and AMD loader behavior.

## Project Structure

```
espocrm/
├── custom/Espo/Modules/
│   ├── QuickBooks/          (order: 15)
│   │   ├── Services/        (QB API client)
│   │   ├── Hooks/           (afterSave hooks for Account, Contact, Invoice)
│   │   ├── Jobs/            (SyncFromQuickBooks, ReconcileQuickBooks)
│   │   ├── EntryPoints/     (OAuth callback)
│   │   ├── Controllers/     (initOAuth, runSync actions)
│   │   └── Resources/       (metadata, i18n)
│   └── Xero/                (order: 16)
│       ├── Services/        (Xero API client)
│       ├── Hooks/           (afterSave hooks for Account, Contact, Invoice)
│       ├── Jobs/            (SyncFromXero, ReconcileXero)
│       ├── EntryPoints/     (OAuth callback)
│       ├── Controllers/     (initOAuth, runSync actions)
│       └── Resources/       (metadata, i18n)
├── client/custom/modules/
│   ├── quick-books/
│   │   ├── src/             (TypeScript views)
│   │   └── lib/transpiled/  (compiled AMD modules)
│   └── xero/
│       ├── src/             (TypeScript views)
│       └── lib/transpiled/  (compiled AMD modules)
├── data/
│   ├── config.php           (auto-generated; do not edit)
│   ├── logs/                (espo.log contains all sync errors)
│   ├── cache/               (cleared on rebuild)
│   └── upload/              (user-uploaded files)
├── js/transpile.js          (transpiler for custom modules)
└── rebuild.php              (runs rebuild process)
```

## Environment Variables & Config

Critical config values (set in **Administration → Settings**):

| Key | Example | Purpose |
|-----|---------|---------|
| `siteUrl` | `https://cake.local:8443` | Used by QB/Xero OAuth callbacks and API endpoints |
| `dateFormat` | `YYYY-MM-DD` | Date display format |
| `timeFormat` | `HH:mm` | Time display format |
| `timezone` | `UTC` | Affects scheduled job timing |

## Logs & Debugging

- **All errors**: `/home/coreconduit/espocrm/data/logs/espo.log`
- **Sync warnings**: Look for "QB sync failed" or "Xero sync failed" in espo.log
- **DB queries**: Enable in config for verbose output (slows performance; debug only)
- **Browser console**: Check for JavaScript errors when testing views

## Troubleshooting

### QB/Xero OAuth Fails with "Invalid Redirect URI"

**Cause**: `siteUrl` is not set correctly, or HTTPS cert is self-signed and browser rejects it.

**Fix**:
1. Verify `siteUrl` in **Administration → Settings** is exactly `https://cake.local:8443`
2. For self-signed certs (mkcert), visit `https://cake.local:8443` in your browser and accept the warning
3. Clear browser cache and try OAuth again

### Sync Jobs Do Not Run

**Cause**: Cron is not calling `cron.php` every minute.

**Fix**:
1. Verify cron entry: `sudo crontab -l`
2. Test manually: `php /home/coreconduit/espocrm/cron.php`
3. Check logs: `tail -f /home/coreconduit/espocrm/data/logs/espo.log`

### "Controller 'Invoice' does not exist"

**Cause**: The Invoice entity has no REST API controller.

**Status**: This is a known gap. Invoices are only accessible through sync jobs and admin UI, not via REST API (`GET /api/v1/Invoice`).

**Workaround**: Create invoices via the admin UI; sync to QB/Xero via scheduled jobs.

### Hooks Not Firing on Save

**Cause**: Account/Contact/Invoice hooks are skipped if the save option `skipXeroSync` or `skipQuickBooksSync` is set.

**Fix**: Ensure you're not manually saving with these options unless you intend to skip QB/Xero sync.

### Token Expiration Errors in Logs

**Cause**: `refreshToken` has expired (QB ~101 days, Xero ~60 days).

**Fix**: Reconnect via **Administration → Integrations** → click **Connect to [QB/Xero]** again.
