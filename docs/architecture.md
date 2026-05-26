# Architecture

## Overview

This is a self-hosted EspoCRM 9.x instance extended with the **Xero** custom accounting module,
providing bidirectional sync between EspoCRM CRM data and Xero Accounting.

The module is fully isolated in `custom/Espo/Modules/Xero/`. It does not modify any core EspoCRM files.

## Request Lifecycle

```
Browser Request → nginx (HTTPS on 8443)
                  ↓
              index.php → Slim Router
                  ↓
          EspoCRM / Custom Controller (REST API)
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
Xero Background Job (SyncFromXero or ReconcileXero)
        ↓
XeroService + Database
```

## Module Structure

```
custom/Espo/Modules/Xero/
├── Controllers/
│   └── XeroIntegration.php      POST /api/v1/XeroIntegration/initOAuth
│                                POST /api/v1/XeroIntegration/runSync
├── EntryPoints/
│   └── XeroOauthCallback.php    ?entryPoint=XeroOauthCallback (no auth)
├── Hooks/
│   ├── Account/XeroSync.php     afterSave → upsertContact
│   ├── Contact/XeroSync.php     afterSave → upsertContact
│   └── Invoice/XeroSync.php     afterSave → upsertInvoice or voidInvoice
├── Jobs/
│   ├── SyncFromXero.php         Nightly pull (contacts + payments)
│   └── ReconcileXero.php        Nightly push (batch 25 records)
├── Services/
│   └── XeroService.php          All Xero API calls + token refresh
├── Tools/
│   └── ConflictResolver.php     Last-modified-wins; pure function, no I/O
└── Resources/
    ├── module.json               order: 16; jsTranspiled: true
    ├── routes.json               API route declarations
    ├── metadata/
    │   ├── integrations/Xero.json      Admin UI fields + view binding
    │   ├── app/scheduledJobs.json      Job registration
    │   ├── entityDefs/{Account,Contact,Invoice}.json
    │   ├── clientDefs/{Account,Contact,Invoice}.json
    │   └── scopes/Invoice.json
    └── i18n/en_US/{Integration,Invoice}.json
```

Frontend (AMD modules, transpiled from JS source):

```
client/custom/modules/xero/
├── src/views/admin/integrations/xero.js      Source: Connect + Sync buttons
├── src/views/panels/xero-status.js           Source: side panel view
├── lib/transpiled/src/                        Compiled AMD output
└── res/templates/panels/xero-status.tpl      Handlebars template
```

### Hook Naming

Hooks are named `XeroSync` (not `Sync`) to avoid EspoCRM's hook class-name deduplication bug.
EspoCRM caches hooks by short class name; if two hooks share the same name, only one is registered.
The `Xero` prefix ensures all three hooks (Account, Contact, Invoice) are distinct.

## Sync Data Flow

### Push (EspoCRM → Xero) — Real-Time via Hooks

When a user saves an Account, Contact, or Invoice in EspoCRM:

```
User clicks Save
        ↓
EspoCRM ORM.saveEntity()
        ↓
Fire afterSave hooks (order 20)
        ↓
Hook checks if this save has skipXeroSync option set → if yes, return
        ↓
Inject XeroService
Call upsertContact() or upsertInvoice()
        ↓
getAccessToken() → check expiry with 30-second margin → refresh if needed
        ↓
HTTP POST to Xero API
        ↓
On success:
  - Write back xeroContactId / xeroInvoiceId / xeroSyncedAt
  - Save with skipXeroSync=true (loop guard prevents re-entry)
        ↓
On failure:
  - Log warning (warning level)
  - CRM save still completes; Xero failure is non-blocking
```

### Pull (Xero → EspoCRM) — Nightly via Job

`SyncFromXero` runs daily at 2:00 AM:

```
Read lastSyncAt from Integration entity
(default: now − 7 days on first run)
        ↓
GET /Contacts (If-Modified-Since header)
        ↓
For each contact:
  - Find matching Account/Contact by xeroContactId
  - ConflictResolver: is Xero newer than xeroSyncedAt?
  - If yes: update fields → save(skipXeroSync=true)
        ↓
GET /Payments (where=Date>=DateTime(lastSyncAt))
        ↓
For each payment:
  - Find EspoCRM Invoice by xeroInvoiceId
  - Set status=Paid, store xeroPaymentId + xeroPaymentDate
  - save(skipXeroSync=true)
        ↓
Write lastSyncAt = now() to Integration entity
```

### Reconciliation (Nightly via Job, After Pull)

`ReconcileXero` runs at 2:15 AM (15 minutes after SyncFromXero). Batch size: **25 records** per run.

```
Query Accounts WHERE xeroContactId IS NULL → upsertContact() for each (batch 25)
        ↓
Query Accounts WHERE xeroContactId IS NOT NULL
  → For each: if modifiedAt > xeroSyncedAt → upsertContact()
        ↓
Query Invoices WHERE status NOT IN (Paid, Voided)
  → For each: if modifiedAt > xeroSyncedAt → upsertInvoice()
        ↓
Write lastSyncError to Integration entity
```

## Conflict Resolution

**Strategy**: Last-modified-wins with EspoCRM as the tie-break winner.

`ConflictResolver::resolve(?string $xeroLastUpdated, ?string $espoSyncedAt): string`

| Scenario | Result |
|---|---|
| Both null | `WINNER_NONE` — skip |
| Only Xero timestamp | `WINNER_XERO` |
| Only EspoCRM timestamp | `WINNER_ESPO` |
| Xero newer | `WINNER_XERO` |
| EspoCRM newer | `WINNER_ESPO` |
| Tied | `WINNER_ESPO` |

Implementation: `Tools/ConflictResolver.php` — pure function, fully tested, no I/O.

## Hook Loop Guard

All internal saves triggered by sync use:

```php
$this->entityManager->saveEntity($entity, [
    'skipXeroSync' => true,
    'silent' => true,
]);
```

Hooks check this flag before firing:

```php
public function afterSave(Entity $entity, SaveOptions $options): void
{
    if ($options->get('skipXeroSync')) {
        return;
    }
    // ... perform sync
}
```

## OAuth Token Storage

Tokens are stored in the `Integration` entity (`id = 'Xero'`) via the flexible `data` JSON column.

| Field | Type | Purpose |
|---|---|---|
| `clientId` | varchar | Xero app Client ID |
| `clientSecret` | password | Encrypted at rest |
| `accessToken` | text | Bearer token; ~30 minutes; auto-refreshed |
| `refreshToken` | text | Long-lived; ~60 days |
| `accessTokenExpiresAt` | datetime | Checked with 30-second margin |
| `tenantId` | varchar(64) | Xero organisation UUID |
| `connectedAt` | datetime | Last successful OAuth timestamp |
| `lastSyncAt` | datetime | Pull job watermark |
| `defaultAccountCode` | varchar(32) | Account code for invoice line items |
| `oauthState` | varchar(64) | CSRF token; cleared after OAuth completes |
| `lastSyncError` | text | Last reconcile error; shown in admin UI |

## OAuth Flow

```
1. Admin clicks "Connect to Xero"
2. POST /api/v1/XeroIntegration/initOAuth
   → state = bin2hex(random_bytes(16))
   → stored in Integration.oauthState
   → returned to frontend
3. Frontend builds Xero authorization URL with state param
4. Frontend opens popup to authorization URL
5. User approves scopes
6. Xero redirects to ?entryPoint=XeroOauthCallback
7. EntryPoint validates state → exchanges code for tokens
   → fetches tenantId from /connections
   → clears oauthState
8. Popup posts success message; admin UI refreshes
```

## Token Refresh

Refresh happens inside `getAccessToken()`:

```php
private function getAccessToken(Integration $integration): string
{
    $expiresAt = $integration->get('accessTokenExpiresAt');

    if ($expiresAt && isExpiringSoon($expiresAt, 30)) {
        $this->refreshAccessToken($integration);
    }

    return $integration->get('accessToken');
}
```

Refresh flow:

```
POST https://identity.xero.com/connect/token
  grant_type=refresh_token
  refresh_token={REFRESH_TOKEN}
  client_id + client_secret
        ↓
Receive: accessToken, refreshToken (rotated), expiresIn (30 min)
        ↓
Update Integration entity
        ↓
Return new token
```

## Frontend

EspoCRM uses AMD (RequireJS-compatible) for its client-side code. The Xero module provides:

- `src/views/admin/integrations/xero.js` — extends `IntegrationsEditView`, adds Connect and Sync buttons
- `src/views/panels/xero-status.js` — side panel on Account/Contact detail views

Source files are transpiled to AMD modules at build time:

```
src/views/.../xero.js
        ↓
Babel (TypeScript plugin + AMD plugin)
        ↓
lib/transpiled/src/views/.../xero.js
        ↓
Browser AMD loader (require())
```

Transpile command (from EspoCRM root): `node js/transpile.js`

## Background Job System

| Job | Schedule | Purpose |
|---|---|---|
| `SyncFromXero` | 2:00 AM daily | Pull contacts + payments from Xero |
| `ReconcileXero` | 2:15 AM daily | Push modified Accounts + Invoices to Xero |

Both implement `Espo\Core\Job\JobDataLess`. Jobs are registered in
`Resources/metadata/app/scheduledJobs.json` and appear in Admin → Scheduled Jobs.

Job lifecycle:
```
System cron calls php cron.php every minute
        ↓
Cron handler finds all active ScheduledJob records
        ↓
For each: check (lastRun + interval) <= now
  → If yes: instantiate job class via InjectableFactory
  → Call run()
  → Log success/failure
  → Update lastRun
```

Job errors do **not** abort the cron cycle. Each job fails independently.

## Error Handling

### Hook Errors (Real-Time)

```php
try {
    $service->upsertContact('Account', $entity);
} catch (Throwable $e) {
    $this->log->warning("Xero Account sync failed: " . $e->getMessage());
    // Does NOT rethrow — CRM save still completes
}
```

### Job Errors (Background)

```php
try {
    $service->pullContactsSince($sinceDate);
} catch (Throwable $e) {
    $this->log->error("Xero SyncFromXero (contacts): " . $e->getMessage());
    $errors[] = "Contacts: " . $e->getMessage();
}
```

### API HTTP Errors

| Code | Meaning | Handling |
|---|---|---|
| 401 | Token expired or invalid | Triggers proactive refresh |
| 403 | Missing scope / integration disabled | Logged as error |
| 429 | Rate limit exceeded | Logged; job retries next scheduled run |
| 5xx | Xero service issue | Logged; job retries |

## Data Integrity

### Idempotency

Xero supports idempotent contact and invoice operations via POST. Sending the same payload twice
is safe — Xero uses the `ContactID` / `InvoiceID` for matching.

### Foreign Key Requirement

Invoices require an Account with `xeroContactId` already populated. If the customer is not yet
in Xero, the invoice sync is skipped and logged as a warning. The nightly Reconcile job retries
both the contact and the invoice.

### Schema Sync

EspoCRM auto-discovers entity schema from metadata JSON files. Running `php command.php rebuild`:
1. Loads all metadata files
2. Compares declared schema to actual database
3. Creates missing tables/columns and indexes
4. Rebuilds the metadata cache

## Core Patterns Summary

| Aspect | Pattern |
|---|---|
| Module isolation | `custom/Espo/Modules/Xero/` — no core file modifications |
| Real-time sync | afterSave hooks; order=20; failures non-blocking |
| Scheduled sync | Nightly jobs (SyncFromXero at 2 AM, ReconcileXero at 2:15 AM) |
| Conflict resolution | Last-modified-wins; EspoCRM wins on tie |
| Error handling | Failures logged; never abort user saves |
| Token management | Integration entity; auto-refresh with 30s margin |
| Frontend code | Source JS → Babel → AMD → browser |
| Batch size | 25 records per Reconcile run (Xero rate limit headroom) |
