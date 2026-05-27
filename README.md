# EspoCRM Xero Integration

Bidirectional sync between EspoCRM and Xero.

- Accounts/Contacts ↔ Xero Contacts (bidirectional, conflict resolution via last-modified-wins)
- EspoCRM Invoices → Xero Invoices (push on save)
- Xero Payments → EspoCRM Invoice status (nightly pull, marks Invoice as Paid)
- Invoice voided in EspoCRM → Xero void (hook-dispatched)

## Requirements

- EspoCRM 9.x
- PHP 8.3+
- HTTPS on your EspoCRM instance (required for Xero OAuth)
- A Xero developer app ([developer.xero.com](https://developer.xero.com))

## Installation

```bash
git clone https://github.com/bitsandbots/espocrm-xero.git
ESPO=/path/to/espocrm

cp -r espocrm-xero/custom/Espo/Modules/Xero  "$ESPO/custom/Espo/Modules/"
cp -r espocrm-xero/client/custom/modules/xero "$ESPO/client/custom/modules/"
sudo chown -R www-data:www-data \
    "$ESPO/custom/Espo/Modules/Xero" \
    "$ESPO/client/custom/modules/xero"
php "$ESPO/command.php" rebuild
```

## Configuration

1. Register a Xero developer app at [developer.xero.com](https://developer.xero.com).
2. Add a redirect URI: `https://your-espocrm-domain.com?entryPoint=XeroOauthCallback`
3. In EspoCRM: **Admin → Integrations → Xero**
   - Enter **Client ID** and **Client Secret**
   - Click **Save**, then **Connect** — you will be redirected to Xero to authorize
4. Enable scheduled jobs: **Admin → Scheduled Jobs**
   - `SyncFromXero` — nightly pull of Xero Contacts and Payments
   - `ReconcileXero` — nightly conflict resolution (run 15 min after sync)
5. Configure cron (once per minute, as the web server user):
   ```
   * * * * * www-data php /path/to/espocrm/cron.php > /dev/null 2>&1
   ```

## Data Model

| EspoCRM Field | Xero Field |
|---|---|
| Account.name | Contact.Name |
| Account.xeroContactId | Contact.ContactID |
| Contact.name | Contact.Name |
| Contact.xeroContactId | Contact.ContactID |
| Invoice.amount | Invoice total |
| Invoice.status = Paid | Payment received |
| Invoice.status = Voided | Invoice voided |

New fields added to Account and Contact: `xeroContactId`, `xeroSyncedAt`.

## Development & Testing

Tests require a local EspoCRM installation for the `Espo\Core\*` namespace:

```bash
ESPO_PATH=/path/to/espocrm \
  /path/to/espocrm/vendor/bin/phpunit \
  --configuration phpunit.xml \
  --no-coverage
```

Expected: 87 tests, 0 failures.

To build a release ZIP:

```bash
scripts/release.sh --version 1.0.0 --espo-path /path/to/espocrm
# Output: releases/espocrm-xero-v1.0.0.zip
```

## Documentation

- [Integration architecture & sync mechanics](docs/xero-integration.md)
- [Setup & deployment guide](docs/setup.md)
- [System architecture](docs/architecture.md)
- [Gap analysis & known limitations](docs/gap-analysis.md)
- [Module internals](custom/Espo/Modules/Xero/README.md)

## License

MIT — see [LICENSE](LICENSE).
