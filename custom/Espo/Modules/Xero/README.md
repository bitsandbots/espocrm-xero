# Xero Integration

Bidirectional sync between EspoCRM and Xero. EspoCRM is the operational source of truth for CRM data; Xero is the accounting source of truth for payments.

## What it syncs

| EspoCRM | Xero | Direction |
|---------|------|-----------|
| Account | Contact (IsCustomer) | Push on save, pull nightly |
| Contact | Contact (IsCustomer) | Push on save, pull nightly |
| Invoice | Invoice (ACCREC) | Push on save |
| Xero Payment | Invoice status → Paid | Pull nightly |

Conflict resolution uses last-modified-wins: whichever record was modified more recently wins during the nightly reconciliation pass. Xero's `UpdatedDateUTC` field is compared against EspoCRM's `xeroSyncedAt`.

## Requirements

- EspoCRM 9.x or later
- A Xero organisation (any paid plan, or free trial)
- A developer app at [developer.xero.com](https://developer.xero.com) (free)
- PHP `curl` extension enabled

## Setup

### 1. Create a Xero developer app

1. Sign in at [developer.xero.com](https://developer.xero.com) and go to **My Apps → New app**.
2. Choose **Web app** as the integration type.
3. Under **Redirect URIs**, add:
   ```
   https://your-espocrm-site.com/?entryPoint=XeroOauthCallback
   ```
   Replace the domain with your actual EspoCRM `siteUrl`.
4. Copy your **Client ID** and **Client Secret** from the **Configuration** tab.

**Required OAuth scopes** (configure under **Scopes** in the app settings):

```
openid
profile
email
offline_access
accounting.transactions
accounting.contacts
```

> `accounting.transactions` covers invoices, payments, credit notes, and bank transactions.
> `accounting.invoices` and `accounting.payments` are **not** valid Xero OAuth scope names.

### 2. Find your default account code

Xero requires every invoice line item to reference a chart-of-accounts code. Look up the code for the income account you want to use as the default (e.g. `200` for Sales):

- In Xero: **Accounting → Chart of Accounts**, find the account and note its **Code** column.
- Codes are typically 3–4 digits (e.g. `200`, `4000`).

### 3. Activate the module

```bash
php command.php rebuild
```

This registers the Invoice entity, adds Xero fields to Account and Contact, and loads module metadata.

### 4. Configure the integration

1. In EspoCRM: **Admin → Integrations → Xero**.
2. Enter your **Client ID** and **Client Secret**.
3. Enter your **Default Account Code** (from step 2).
4. Click **Save**.
5. Click **Connect to Xero** — a popup will open to authorize with Xero.
6. Select the Xero organisation to connect and click **Allow access**.
7. After authorizing, the popup closes and the form shows "Connected" with your Tenant ID.

### 5. Schedule the sync jobs

In **Admin → Scheduled Jobs**, enable and set a schedule for both jobs:

| Job name | Recommended schedule | Purpose |
|----------|---------------------|---------|
| Xero: Sync from Xero | Daily at 2:00 AM | Pulls Xero Contact updates and Payment records |
| Xero: Reconcile | Daily at 2:15 AM | Pushes EspoCRM Accounts and Invoices modified since last sync |

Run Reconcile after Sync so that any conflicts pulled from Xero are already applied before the outbound push.

### 6. Initial data push

After connecting, trigger a manual sync from the Xero integration page or run the scheduled jobs once immediately. Existing Accounts and Contacts will be pushed to Xero on their next save; the nightly Reconcile job will catch any that were not saved since activation.

## Data model

### New entity: Invoice

| Field | Type | Notes |
|-------|------|-------|
| name | varchar | Invoice number or reference |
| status | enum | Draft · Sent · Paid · Overdue · Voided |
| amount | currency | Total; used as a fallback single line item if `lineItems` is empty |
| dueDate | date | |
| lineItems | jsonArray | Array of `{description, quantity, unitPrice, xeroAccountCode}` |
| account | link → Account | Required for Xero sync (provides the Xero Contact ID) |
| contact | link → Contact | Optional |
| xeroInvoiceId | varchar | Xero UUID; set automatically after first sync |
| xeroSyncedAt | datetime | Timestamp of last successful push |
| xeroPaymentId | varchar | Xero Payment UUID; set when payment received |
| xeroPaymentDate | date | |

### Fields added to Account and Contact

| Field | Notes |
|-------|-------|
| xeroContactId | Xero Contact UUID; set after first sync |
| xeroSyncedAt | Timestamp of last successful push |

A **Xero** panel appears on the Account and Contact detail views showing the Contact ID and last sync time.

## How sync works

### Push (on save)

An `afterSave` hook fires whenever an Account, Contact, or Invoice is saved. It calls `XeroService::upsertContact()` or `upsertInvoice()`, which posts to the Xero Accounting API. Internal saves triggered by sync (e.g. writing back the Xero Contact ID) set `skipXeroSync = true` to prevent loops.

Invoice sync requires the linked Account to already have a `xeroContactId`. If the Account has not yet been synced to Xero, save the Account record first (or wait for the nightly Reconcile job).

Invoices with status **Voided** trigger a void operation in Xero rather than an update. Voided invoices are excluded from the nightly reconciliation pass.

### Pull (nightly)

`SyncFromXero` fetches Contacts modified since `lastSyncAt` (using the `If-Modified-Since` HTTP header) and Payments created since `lastSyncAt` (using Xero's `DateTime()` query filter). Matching Accounts are updated; Invoices linked to received Payments are set to **Paid**.

On first run, the look-back window defaults to the past 7 days.

### Reconciliation (nightly)

`ReconcileXero` scans Accounts and Invoices modified since their last `xeroSyncedAt` and pushes any that are stale. Processes records in batches of 25 to stay within Xero's 60-requests-per-minute rate limit.

## Line items and account codes

Each entry in `lineItems` can include a `xeroAccountCode` to pin it to a specific Xero income account. If `xeroAccountCode` is omitted, the **Default Account Code** configured in the integration settings is used. If neither is set, the Xero API will reject the invoice.

```json
[
  { "description": "Consulting — June", "quantity": 8, "unitPrice": 150.00, "xeroAccountCode": "200" },
  { "description": "Expenses reimbursement", "quantity": 1, "unitPrice": 75.00 }
]
```

The second line above will use the Default Account Code.

If `lineItems` is empty or null, a single line item is created using the Invoice's `amount` field and the Default Account Code.

## OAuth security

The authorization flow uses OAuth 2.0 + PKCE (S256) with a server-issued state token:

1. Clicking **Connect** calls `POST /api/v1/XeroIntegration/initOAuth` (admin only), which
   generates a cryptographically random state token and a PKCE code verifier, stores both in
   the Integration record, and returns the full authorization URL.
2. The frontend opens the URL in a popup; it includes `state=…`, `code_challenge=…`
   (SHA-256 hash of the verifier, base64url-encoded), and `code_challenge_method=S256`.
3. Xero redirects back to `?entryPoint=XeroOauthCallback` with the authorization code and state.
4. The callback rejects the request if `state` is absent or does not match the stored token.
5. The code is exchanged for tokens at Xero's token endpoint, including the `code_verifier`
   to complete PKCE verification.
6. On success, the callback fetches the list of authorized organisations from `GET /connections`,
   stores the first `tenantId`, and clears the stored state token and code verifier.

Xero access tokens expire after 30 minutes and are refreshed automatically using the stored refresh token. Refresh tokens expire after 60 days of inactivity — reconnect via Admin → Integrations → Xero if refresh fails.

## Multi-organisation support

The module stores a single `tenantId` (the first organisation returned by `/connections`). If your Xero app is authorized against multiple organisations, only the first is used. To switch organisations, disconnect and reconnect — the new tenant's ID will replace the stored one.

## Rate limits

Xero enforces a limit of 60 API requests per minute per organisation. The Reconcile job processes a maximum of 25 records per run (1 request per record) to leave headroom for the push hooks firing during the same window. If you have a large backlog of unsynced records, the nightly job will process them across multiple days. You can also trigger a manual sync from the integration page.

## Troubleshooting

**"Cannot sync invoice — linked Account has no Xero Contact ID"**  
Save the Account record first to push it to Xero, then save the Invoice again. Or wait for the next nightly Reconcile run.

**Invoice rejected by Xero API**  
Check that your Default Account Code is set and references an active revenue account in your Xero chart of accounts. Using a bank account code or an archived account will cause a validation error.

**"Xero integration is not enabled"**  
The integration is disabled in EspoCRM or the connection was lost. Go to Admin → Integrations → Xero and reconnect.

**Token refresh failures**  
Xero refresh tokens expire after 60 days of inactivity. Reconnect via Admin → Integrations → Xero if you see token errors in the application log (`data/logs/espo.log`).

**Sync job not running**  
Confirm the scheduled jobs are enabled and that the EspoCRM cron is configured:
```bash
* * * * * /usr/bin/php /path/to/espocrm/cron.php
```

**Payments not marking invoices as Paid**  
The nightly pull matches Xero Payments to EspoCRM Invoices via `xeroInvoiceId`. If an invoice was created in Xero directly (not pushed from EspoCRM), there is no matching `xeroInvoiceId` and the status will not update.

## Xero vs QuickBooks — running both modules

Both modules can coexist on the same EspoCRM instance. They write to separate fields (`xeroContactId` / `qbCustomerId`, `xeroInvoiceId` / `qbInvoiceId`) and use separate skip flags (`skipXeroSync` / `skipQuickBooksSync`), so they do not interfere with each other's save hooks.

If both modules are installed, the Invoice layout is controlled by whichever module has the higher load order (`module.json` → `order`). Xero loads at order 16 (QuickBooks at 15), so Xero's layout wins and shows Xero tracking fields. QuickBooks tracking fields remain in the database and visible via the QB side panel.

## File map

```
custom/Espo/Modules/Xero/
├── Controllers/
│   └── XeroIntegration.php          POST /api/v1/XeroIntegration/initOAuth
├── EntryPoints/
│   └── XeroOauthCallback.php        ?entryPoint=XeroOauthCallback
├── Hooks/
│   ├── Account/XeroSync.php         afterSave → upsertContact
│   ├── Contact/XeroSync.php         afterSave → upsertContact
│   └── Invoice/XeroSync.php         afterSave → upsertInvoice or voidInvoice
├── Jobs/
│   ├── SyncFromXero.php             nightly pull (contacts + payments)
│   └── ReconcileXero.php            nightly push (batch 25)
├── Services/
│   └── XeroService.php              all Xero API calls and field mapping
├── Tools/
│   └── ConflictResolver.php         last-modified-wins; handles /Date(ms)/ format
└── Resources/
    ├── metadata/
    │   ├── app/scheduledJobs.json
    │   ├── clientDefs/{Account,Contact,Invoice}.json
    │   ├── entityDefs/{Account,Contact,Invoice}.json
    │   ├── integrations/Xero.json
    │   └── scopes/Invoice.json
    ├── layouts/Invoice/{detail,edit,list,filters}.json
    └── i18n/en_US/{Integration,Invoice}.json

tests/unit/Espo/Modules/Xero/
├── ConflictResolverTest.php
├── HooksSyncTest.php
├── ReconcileXeroTest.php
├── SyncFromXeroTest.php
├── XeroIntegrationControllerTest.php
├── XeroOauthCallbackTest.php
├── XeroServiceFieldMappingTest.php
├── XeroServiceHttpTest.php
└── XeroServicePullTest.php
```

## API reference

All requests go to `https://api.xero.com/api.xro/2.0/` with two required headers:

```
Authorization: Bearer {access_token}
Xero-Tenant-Id: {tenantId}
```

| Operation | Method | Endpoint |
|-----------|--------|----------|
| Upsert Contact | POST | `/Contacts` |
| Upsert Invoice | POST | `/Invoices` |
| Void Invoice | POST | `/Invoices` (Status: VOIDED) |
| Pull Contacts | GET | `/Contacts` + `If-Modified-Since` header |
| Pull Payments | GET | `/Payments?where=Date>=DateTime(...)` |
| List tenants | GET | `https://api.xero.com/connections` |
| Refresh token | POST | `https://identity.xero.com/connect/token` |
