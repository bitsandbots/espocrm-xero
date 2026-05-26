# Architecture

## Overview

The deployment is a self-hosted EspoCRM 9.3.7 instance extended with two custom accounting integration modules:

1. **QuickBooks Online (QB)** — full bidirectional sync for Customers, Invoices, and Payments
2. **Xero** — full bidirectional sync for Contacts, Invoices, and Payments

Both modules are isolated in `custom/Espo/Modules/` and do not modify core EspoCRM files. They follow the same architecture pattern for consistency and maintainability.

## Request Lifecycle

```
Browser Request → nginx (HTTPS on 8443)
                  ↓
              index.php → Slim Router
                  ↓
          EspoCRM/Custom Controller (REST API)
                  ↓
          Service + ORM Layer
                  ↓
          MySQL / MariaDB
```

Background sync jobs run on the cron schedule:

```
System cron (every minute)
        ↓
cron.php
        ↓
EspoCRM Job Dispatcher
        ↓
QuickBooks/Xero Background Job
        ↓
Service + Database
```

## Module Structure

Both QB and Xero follow identical patterns. Here's the QuickBooks layout; Xero is identical with different class namespaces:

```
custom/Espo/Modules/QuickBooks/
├── Clients/                            (reserved; not used)
├── Controllers/
│   └── QuickBooksIntegration.php      API: initOAuth, runSync
├── EntryPoints/
│   └── QuickBooksOauthCallback.php    OAuth2 redirect handler
├── Entities/
│   └── Invoice.php                     Invoice entity class
├── Hooks/
│   ├── Account/Sync.php               afterSave hook
│   ├── Contact/Sync.php               afterSave hook
│   └── Invoice/Sync.php               afterSave hook
├── Jobs/
│   ├── SyncFromQuickBooks.php         Pull job
│   └── ReconcileQuickBooks.php        Push job
├── Services/
│   └── QuickBooksService.php          QB HTTP API client
├── Tools/
│   └── ConflictResolver.php           Timestamp-based conflict resolution
└── Resources/
    ├── module.json                     order: 15; jsTranspiled: true
    ├── metadata/
    │   ├── integrations/QuickBooks.json   Admin UI fields
    │   ├── app/scheduledJobs.json        Job registration
    │   ├── entityDefs/
    │   │   ├── Account.json             QB fields on Account
    │   │   ├── Contact.json             QB fields on Contact
    │   │   └── Invoice.json             QB Invoice entity schema
    │   ├── clientDefs/
    │   │   └── Invoice.json             Admin UI config for Invoice
    │   ├── scopes/
    │   │   └── Invoice.json             Invoice entity scopes
    │   └── routes.json                  OAuth callback routing
    └── i18n/en_US/
        ├── Integration.json            Integration field labels
        └── Invoice.json                Invoice field labels
```

Xero is identical:
- `custom/Espo/Modules/Xero/` (order: 16)
- Classes named `Xero*` instead of `QuickBooks*`
- Hooks named `XeroSync` instead of `Sync` (to avoid EspoCRM's deduplication bug)

## Frontend Structure

Custom module JavaScript/TypeScript views are stored in source form and transpiled to AMD modules:

```
client/custom/modules/quick-books/
├── src/
│   └── views/admin/integrations/
│       └── quick-books.js              TypeScript view with "Connect" button
└── lib/transpiled/src/
    └── views/admin/integrations/
        └── quick-books.js              Compiled AMD module (auto-generated)
```

Same for Xero. The transpiler (`js/transpile.js`) converts:
- TypeScript → JavaScript
- Module system → AMD
- Applies Babel plugins for browser compatibility

The loader fetches transpiled modules from `lib/transpiled/src/` at runtime.

## Sync Data Flow

### Push (EspoCRM → QB/Xero) — Real-Time via Hooks

When a user saves an Account, Contact, or Invoice in EspoCRM:

```
User clicks Save
        ↓
EspoCRM ORM.saveEntity()
        ↓
Fire afterSave hooks (order 20)
        ↓
Hook checks if this save has skipQuickBooksSync or skipXeroSync option
        ↓
If not set:
  - Inject QuickBooksService or XeroService
  - Call upsertCustomer() or upsertInvoice()
  - Service checks and refreshes access token (30-second margin)
  - HTTP POST to QB/Xero API
  - Save returned Id + SyncToken back to entity
  - Save with skipQuickBooksSync=true to prevent re-entry
        ↓
User gets immediate feedback in admin UI
(sync warning is logged to espo.log if API fails)
```

If the QB/Xero API call fails (network error, rate limit, etc.):
- Failure is caught and logged at `warning` level
- CRM save still succeeds (the Account/Invoice is saved locally)
- Admin can retry via **Administration → Integrations → [QB/Xero] → Run Sync**

### Pull (QB/Xero → EspoCRM) — Nightly via Job

The `SyncFromQuickBooks` and `SyncFromXero` jobs run daily (default: 2 AM) and fetch all updated records since the last sync:

```
Scheduled Job fires
        ↓
Read lastSyncAt from Integration entity
(default: 7 days ago on first run)
        ↓
QB/Xero API: Get all Customers/Contacts updated since lastSyncAt
        ↓
For each customer/contact:
  - Find matching Account by qbCustomerId or xeroContactId
  - Check ConflictResolver: is QB/Xero newer than qbSyncedAt / xeroSyncedAt?
  - If yes: update Account fields (name, email, phone, etc.)
  - Save with skipQuickBooksSync=true
        ↓
QB/Xero API: Get all Payments since lastSyncAt
        ↓
For each payment:
  - Find linked QB/Xero Invoice
  - Find matching EspoCRM Invoice by qbInvoiceId or xeroInvoiceId
  - Set status=Paid, store payment ID and date
  - Save with skipQuickBooksSync=true / skipXeroSync=true
        ↓
Write lastSyncAt = now() to Integration entity
        ↓
Log "sync complete" or error to espo.log
```

### Reconciliation — Nightly via Job (After Pull)

After the pull job completes, the `ReconcileQuickBooks` or `ReconcileXero` job pushes any local changes not yet synced:

```
Reconciliation Job fires
        ↓
Find Accounts where modifiedAt > qbSyncedAt / xeroSyncedAt
(these were modified after the last pull, so EspoCRM version is newer)
        ↓
Call upsertCustomer() for each (push to QB/Xero)
        ↓
Find Invoices where status ≠ Paid/Voided and modifiedAt > qbSyncedAt
        ↓
Call upsertInvoice() for each (push to QB/Xero)
        ↓
Update sync timestamps on success
        ↓
Log completion or error
```

Batch size: 50 records per run (configurable).

## Conflict Resolution

**Strategy**: Last-modified-wins, with EspoCRM tie-breaking.

```
ConflictResolver::resolve(?string $qbLastUpdated, ?string $espoSyncedAt): string
```

Returns one of:
- `WINNER_QB`: QB API value is newer → pull QB data into EspoCRM
- `WINNER_ESPO`: EspoCRM value is newer → push EspoCRM data to QB
- `WINNER_NONE`: No sync metadata available → skip

Logic:
- Both null → NONE (nothing to compare)
- Only QB timestamp → QB wins
- Only EspoCRM timestamp → EspoCRM wins
- Both present → newer timestamp wins
- Timestamps equal → EspoCRM wins (arbitrary but consistent)

Example:
```
QB Account last updated: 2026-05-26 10:00:00 UTC
EspoCRM Account qbSyncedAt: 2026-05-26 09:00:00 UTC
Result: QB wins → pull QB data
```

Implementation: `custom/Espo/Modules/QuickBooks/Tools/ConflictResolver.php` (pure function, fully tested, no I/O).

## Hook Loop Guard

All internal saves after sync use:

```php
$this->entityManager->saveEntity($entity, [
    'skipQuickBooksSync' => true,
    'silent' => true
]);
```

Hooks check this flag before firing:

```php
public function afterSave(Entity $entity, SaveOptions $options): void
{
    if ($options->get('skipQuickBooksSync')) {
        return;  // Skip QB sync for this save
    }
    // ... perform QB sync
}
```

This prevents infinite loops: QB sync writes the QB ID back to the entity → would normally trigger afterSave again → but the flag blocks it.

## OAuth Token Storage

Tokens are stored in the `Integration` entity (`id=QuickBooks` or `id=Xero`) in the flexible `data` JSON column. All values are persisted via the ORM.

### QuickBooks Integration Fields

| Key | Type | Purpose |
|-----|------|---------|
| `clientId` | varchar | QB app Client ID (from developer.intuit.com) |
| `clientSecret` | password | QB app Client Secret (encrypted at rest) |
| `accessToken` | — | Bearer token; expires ~1 hour |
| `refreshToken` | — | Long-lived refresh token; expires ~101 days |
| `accessTokenExpiresAt` | datetime | Checked with 30-second margin for proactive refresh |
| `realmId` | varchar(64) | QB company ID; required in all API URLs |
| `connectedAt` | datetime | When OAuth was last completed |
| `lastSyncAt` | datetime | Last successful pull job timestamp |
| `oauthState` | varchar(64) | CSRF token generated during initOAuth; validated in callback |
| `lastSyncError` | text | Last error message (for debugging) |

### Xero Integration Fields

| Key | Type | Purpose |
|-----|------|---------|
| `clientId` | varchar | Xero app Client ID (from developer.xero.com) |
| `clientSecret` | password | Xero app Client Secret (encrypted at rest) |
| `accessToken` | — | Bearer token; expires ~30 minutes |
| `refreshToken` | — | Long-lived refresh token; expires ~60 days |
| `accessTokenExpiresAt` | datetime | Checked with 30-second margin for proactive refresh |
| `tenantId` | varchar(64) | Xero organisation ID (from /connections endpoint) |
| `connectedAt` | datetime | When OAuth was last completed |
| `lastSyncAt` | datetime | Last successful pull job timestamp |
| `defaultAccountCode` | varchar(32) | Account code for unspecified invoices (e.g., "200") |
| `oauthState` | varchar(64) | CSRF token generated during initOAuth; validated in callback |
| `lastSyncError` | text | Last error message (for debugging) |

## OAuth Flow

Both QB and Xero use OAuth 2.0 with authorization code grant. The flow is initiated by the "Connect" button in the admin UI:

```
1. Admin clicks "Connect to QuickBooks" / "Connect to Xero"
   ↓
2. Frontend calls POST /api/v1/QuickBooksIntegration/action/initOAuth
   (or XeroIntegration for Xero)
   ↓
3. Controller generates random state = bin2hex(random_bytes(16))
   Saves state to Integration.oauthState
   Returns state to frontend
   ↓
4. Frontend builds authorization URL:
   https://appcenter.intuit.com/connect/oauth2?
     client_id={CLIENT_ID}
     &response_type=code
     &scope={SCOPES}
     &redirect_uri=https://cake.local:8443?entryPoint=QuickBooksOauthCallback
     &state={STATE}
   ↓
5. Frontend opens popup to this URL
   ↓
6. User sees QB/Xero login + scope approval screen
   ↓
7. User approves → QB/Xero redirects to:
   https://cake.local:8443?entryPoint=QuickBooksOauthCallback
     &code={AUTHORIZATION_CODE}
     &realmId={QB_COMPANY_ID}  (QB only)
     &state={STATE}
   ↓
8. EntryPoint receives callback:
   - Validates state against Integration.oauthState
   - Exchanges code for tokens via HTTP POST to token endpoint
   - For QB: stores realmId, accessToken, refreshToken, accessTokenExpiresAt
   - For Xero: calls /connections endpoint to get tenantId, stores tokens
   - Saves Integration entity
   - Renders result page with success message
   ↓
9. Frontend popup posts message to opener window:
   window.opener.postMessage({success: true})
   ↓
10. Popup closes; admin UI refreshes Integration form
    realmId / tenantId now visible
```

## Token Refresh

Both QB and Xero tokens expire. Refresh happens automatically inside `getAccessToken()`:

```php
private function getAccessToken(Integration $integration): string
{
    $expiresAt = $integration->get('accessTokenExpiresAt');
    
    // Refresh if within 30 seconds of expiry
    if ($expiresAt && isExpiringSoon($expiresAt, 30)) {
        $this->refreshAccessToken($integration);
    }
    
    return $integration->get('accessToken');
}
```

The refresh flow:
```
Check if token expires within 30 seconds
        ↓
If yes:
  HTTP POST to token endpoint with grant_type=refresh_token
  + clientId and clientSecret (or Basic auth for QB)
  ↓
  Receive new accessToken + new accessTokenExpiresAt
  ↓
  Update Integration entity
  ↓
  Return new token
```

If the refresh fails (credentials invalid, network error, etc.):
- QB sync fails with an Error logged to espo.log
- Admin must reconnect via the UI

## Frontend

EspoCRM uses an AMD module loader (RequireJS-compatible) for its client-side code. Custom modules (QB and Xero) are loaded via the same mechanism.

### Frontend View Structure

Each module provides a custom Integration admin view:

```
client/custom/modules/quick-books/src/views/admin/integrations/quick-books.js

Extends Espo.Ui.View
Provides:
  - "Connect to QuickBooks" button
  - OAuth redirect logic
  - Form validation
  - Run Sync button
```

When the "Connect" button is clicked:
1. JavaScript calls API endpoint `QuickBooksIntegration/action/initOAuth`
2. Receives `state` token
3. Builds full QB OAuth URL with state parameter
4. Opens popup
5. Listens for postMessage from callback
6. Closes popup and refreshes the integration form

### Transpilation Pipeline

TypeScript/JavaScript source files must be transpiled to AMD modules before the browser can load them.

**Pipeline:**
```
client/custom/modules/quick-books/src/views/.../*.ts
        ↓
Babel transform (TypeScript plugin, AMD module plugin)
        ↓
client/custom/modules/quick-books/lib/transpiled/src/views/.../_.js
        ↓
Browser loader fetches transpiled file
        ↓
AMD define() function registers module with require()
        ↓
Integration view can now require() the module
```

**Execution:**
```bash
npm run transpile              # Transpile all modules
npm run transpile -- -f file   # Transpile single file
```

Transpiler runs via `js/transpile.js` at build time and is invoked by npm scripts.

## Background Job System

Both QB and Xero provide two background jobs each:

| Job | Module | Purpose | Schedule |
|-----|--------|---------|----------|
| `SyncFromQuickBooks` | QB | Pull customers + payments | 2 AM daily |
| `ReconcileQuickBooks` | QB | Push modified records | 3 AM daily |
| `SyncFromXero` | Xero | Pull contacts + payments | 2 AM daily |
| `ReconcileXero` | Xero | Push modified records | 3 AM daily |

Jobs implement `Espo\Core\Job\JobDataLess` and are dispatched by EspoCRM's scheduler. The scheduler reads `ScheduledJob` records from the database and checks if they're due. If due, it invokes the job class.

Job registration happens in `Resources/metadata/app/scheduledJobs.json`:

```json
{
    "SyncFromQuickBooks": {
        "name": "QuickBooks: Sync from QuickBooks",
        "jobClassName": "Espo\\Modules\\QuickBooks\\Jobs\\SyncFromQuickBooks"
    }
}
```

This metadata is merged into the admin UI's scheduled job dropdown, allowing easy creation of new job schedules.

### Job Lifecycle

```
1. System cron calls php cron.php every minute
2. Cron handler finds all ScheduledJob records with is_active=1
3. For each job:
   - Check if (lastRun + interval) <= now
   - If yes: instantiate job class via InjectableFactory
   - Call run() method
   - Log success/failure to espo.log
   - Update lastRun timestamp
4. Exit
```

Errors in a job do NOT abort the entire cron cycle. Each job's failure is logged separately.

## Error Handling

### Hook Errors (Real-Time Sync)

All hook sync failures are caught and logged at `warning` level:

```php
try {
    $service->upsertCustomer('Account', $entity);
} catch (Throwable $e) {
    $this->log->warning("QB sync failed: " . $e->getMessage());
    // Does NOT rethrow; CRM save still completes
}
```

Result: User can still save Account/Invoice locally even if QB/Xero is unreachable.

### Job Errors (Background Sync)

Job failures are logged at `error` level and block that job's execution until manually retried or the scheduled time passes again:

```php
try {
    $this->syncFromQuickBooks();
} catch (Throwable $e) {
    $this->log->error("SyncFromQuickBooks failed: " . $e->getMessage());
    throw $e;  // EspoCRM logs the stack trace
}
```

### API Errors

QB and Xero API calls use curl with error checking:

```php
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode >= 400) {
    $response = json_decode($body);
    throw new Error("QB API error: " . $response->faultString);
}
```

Common errors:
- `401 Unauthorized` → Token expired or invalid (triggers refresh)
- `403 Forbidden` → Missing scope or disabled integration
- `429 Too Many Requests` → Rate limit (job retries next cycle)
- `500 Internal Server Error` → QB/Xero service issue (job retries)

## Data Integrity

### Idempotency

Both QB and Xero APIs support idempotent updates via sync tokens:

```php
// QuickBooks example
$payload = [
    'Id' => $qbCustomerId,          // Set for updates
    'SyncToken' => $qbSyncToken,    // Prevents stale updates
    'CompanyName' => 'New Name'
];
```

If you save the same record twice, QB/Xero will reject the second update with a sync token mismatch error. The service catches this and logs a warning.

### Foreign Keys

- Invoices require an Account with `qbCustomerId` or `xeroContactId` already set
- If the customer is not in QB/Xero yet, the invoice sync is skipped and logged as a warning
- The next nightly reconciliation will retry the customer, then retry the invoice

## Metadata & Schema

EspoCRM auto-discovers entity schema from metadata JSON files. Custom fields on Account, Contact, and Invoice are declared in:

```
custom/Espo/Modules/QuickBooks/Resources/metadata/entityDefs/Account.json
custom/Espo/Modules/QuickBooks/Resources/metadata/entityDefs/Contact.json
custom/Espo/Modules/QuickBooks/Resources/metadata/entityDefs/Invoice.json
```

When you run `php rebuild.php`:
1. All metadata files are loaded
2. Schema is compared to actual database
3. Missing tables/columns are created
4. Indexes are added
5. Data model is cached in `data/cache/`

Modifying metadata requires rebuilding.

## Summary: Core Patterns

| Aspect | Pattern |
|--------|---------|
| **Module isolation** | `custom/Espo/Modules/{QB\|Xero}/` — no core file modifications |
| **Real-time sync** | afterSave hooks with loop guard (`skipQuickBooksSync` option) |
| **Scheduled sync** | Nightly jobs (cron-driven) for pull and reconciliation |
| **Conflict resolution** | Last-modified-wins with EspoCRM tie-breaking |
| **Error handling** | Failures logged, never abort user saves; jobs can be retried |
| **Token management** | Stored in Integration entity JSON; auto-refresh with 30s margin |
| **Frontend code** | TypeScript → transpiled to AMD at build time → loaded by browser |
| **Idempotency** | Sync tokens prevent duplicate updates on QB/Xero side |
