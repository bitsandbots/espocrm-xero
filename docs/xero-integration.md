# Xero Integration — Module Reference

## Overview

The Xero module (`custom/Espo/Modules/Xero/`) provides bidirectional sync between EspoCRM and Xero via the Xero REST API v2.0.

**What syncs:**

| EspoCRM | Xero | Direction | Trigger |
|---------|------|-----------|---------|
| Account | Contact | → (push) | afterSave hook |
| Contact | Contact | → (push) | afterSave hook |
| Account | Contact | ← (pull) | Nightly job |
| Invoice (custom) | Invoice | → (push) | afterSave hook |
| — | Payment | ← (pull) | Nightly job → sets Invoice.status=Paid |

## File Reference

```
Services/XeroService.php                Core: all Xero API calls + token refresh
EntryPoints/XeroOauthCallback.php       OAuth2 redirect handler
Controllers/XeroIntegration.php         initOAuth and runSync actions
Hooks/Account/XeroSync.php              Account afterSave hook
Hooks/Contact/XeroSync.php              Contact afterSave hook
Hooks/Invoice/XeroSync.php              Invoice afterSave hook
Jobs/SyncFromXero.php                   Nightly pull (contacts + payments)
Jobs/ReconcileXero.php                  Nightly reconciliation push
Tools/ConflictResolver.php              Conflict resolution logic
Entities/Invoice.php                    Invoice entity class
Resources/metadata/integrations/Xero.json   Admin UI config
Resources/metadata/app/scheduledJobs.json   Job registration
Resources/metadata/entityDefs/Account.json        Xero fields on Account
Resources/metadata/entityDefs/Contact.json       Xero fields on Contact
Resources/metadata/entityDefs/Invoice.json       Invoice entity schema
Resources/metadata/clientDefs/Invoice.json       Admin UI config for Invoice
Resources/metadata/scopes/Invoice.json           Invoice entity scopes
```

## XeroService API

### `upsertContact(string $entityType, Entity $entity): void`

Maps an Account or Contact to a Xero Contact and creates or updates it.

- If `$entity->get('xeroContactId')` is null → POST (create new contact)
- If set → POST with `ContactID` (Xero uses POST for all contact operations)
- Writes `xeroContactId`, `xeroContactStatus`, `xeroSyncedAt` back to entity
- Saves with `skipXeroSync=true` to prevent hook re-entry

**Field mapping:**

| EspoCRM | Xero Contact |
|---------|--------------|
| `name` (Account) | `Name` + `EmailAddress` (if email available) |
| `firstName` + `lastName` (Contact) | `FirstName`, `LastName` |
| `emailAddress` | `EmailAddress` |
| `phoneNumber` | `Phones` (PhoneType: "DEFAULT") |
| `website` | Stored in `ContactName` for reference |
| `billingAddress*` | `Addresses` (AddressType: "POBOX") |

**Example:**
```php
$contact = [
    'Name' => 'Acme Corporation',
    'EmailAddress' => 'contact@acme.com',
    'FirstName' => 'John',
    'LastName' => 'Doe',
    'Phones' => [
        [
            'PhoneNumber' => '+1234567890',
            'PhoneType' => 'DEFAULT'
        ]
    ]
];
```

### `upsertInvoice(Entity $invoice): void`

Maps an EspoCRM Invoice to a Xero Invoice. Requires the linked Account to have a `xeroContactId`.

- Resolves `ContactID` from Account's `xeroContactId`
- Maps EspoCRM `lineItems` JSON array → Xero `LineItems[]`
- Each line item includes `Description`, `Quantity`, `UnitAmount`, and `AccountCode` (from integration's `defaultAccountCode` or `200`)
- Writes `xeroInvoiceId`, `xeroInvoiceStatus`, `xeroSyncedAt` back to entity

**Field mapping:**

| EspoCRM | Xero Invoice |
|---------|-------------|
| `number` | `InvoiceNumber` |
| `amount` | Sum of `LineItems[].LineAmount` |
| `dueDate` | `DueDate` |
| `status` | `Status` (one of: DRAFT, SUBMITTED, AUTHORISED, PAID) |
| `lineItems[].description` | `Description` |
| `lineItems[].quantity` | `Quantity` |
| `lineItems[].unitPrice` | `UnitAmount` |

**Example:**
```php
$invoice = [
    'InvoiceNumber' => 'INV-001',
    'ContactID' => 'b9f3e3e2-fe64-4c8f-9e4f-3c12345678ab',
    'Status' => 'DRAFT',
    'DueDate' => '2026-06-30',
    'LineItems' => [
        [
            'Description' => 'Consulting services',
            'Quantity' => 10,
            'UnitAmount' => 150.00,
            'AccountCode' => '200'
        ]
    ]
];
```

### `voidInvoice(Entity $invoice): void`

Voids a Xero Invoice (only valid if status is AUTHORISED, not yet PAID).

- Checks if Invoice.xeroInvoiceId exists and status allows voiding
- Sends Xero API call to mark invoice as VOIDED
- Updates Invoice.status = Voided locally
- Logs success or error

Used when an Invoice is marked as Voided in EspoCRM and the sync hook detects this.

### `pullPaymentsSince(string $sinceDate): void`

Queries Xero for all Payments with `UpdatedUTC >= sinceDate`. For each payment:

1. Parses the payment's `Invoice` reference to get Xero invoice ID
2. Finds EspoCRM Invoice by `xeroInvoiceId`
3. Sets `status = Paid`, stores `xeroPaymentId`, `xeroPaymentDate`, `xeroPaymentReference`
4. Saves with `skipXeroSync=true`

**Note:** Xero Payments are immutable once created. EspoCRM cannot edit them; only pull and reflect in Invoice status.

### `pullContactsSince(string $sinceDate): void`

Queries Xero Contacts updated since `sinceDate`. For each contact:

1. Finds EspoCRM Account or Contact by `xeroContactId`
2. Checks `ConflictResolver` — only updates if Xero is newer than `xeroSyncedAt`
3. Updates `name`, `emailAddress`, `phoneNumber`, `website`
4. Saves with `skipXeroSync=true`

### Token Refresh

Tokens refresh automatically inside `getAccessToken()`:

```php
private function getAccessToken(Integration $integration): string
{
    $expiresAt = $integration->get('accessTokenExpiresAt');
    
    // Refresh if expires within 30 seconds
    if ($expiresAt && isExpiring($expiresAt, 30)) {
        $this->refreshAccessToken($integration);
    }
    
    return $integration->get('accessToken');
}
```

The refresh flow:
```
HTTP POST to https://identity.xero.com/connect/token
  grant_type=refresh_token
  refresh_token={REFRESH_TOKEN}
  client_id={CLIENT_ID}
  client_secret={CLIENT_SECRET}
        ↓
Receive: accessToken, refreshToken (new), expiresIn (30 minutes)
        ↓
Update Integration.accessToken and Integration.accessTokenExpiresAt
        ↓
Return new token
```

**Token Lifetimes:**
- Access token: ~30 minutes
- Refresh token: ~60 days (shorter than QB's ~101 days)

## Hooks

All three hooks follow the same pattern:

```php
public function afterSave(Entity $entity, SaveOptions $options): void
{
    if ($options->get('skipXeroSync')) {
        return;  // Loop guard
    }
    
    try {
        $service = $this->injectableFactory->create(XeroService::class);
        $service->upsertContact('Account', $entity);
    } catch (Throwable $e) {
        $this->log->warning("Xero sync failed: " . $e->getMessage());
        // Does NOT abort the CRM save
    }
}
```

**Key properties:**
- `static $order = 20` — runs after EspoCRM's own hooks
- Failures are swallowed at `warning` level so CRM saves always succeed
- Hook is skipped if integration is disabled (throws inside `getIntegration()`)

### Hook Class Naming

Xero hooks are named `XeroSync` (e.g., `Hooks/Account/XeroSync.php`) instead of just `Sync` to avoid EspoCRM's short-class-name deduplication bug. EspoCRM caches hooks by class name and would suppress all three `Sync` classes if they shared the same base name. The `Xero` prefix ensures uniqueness.

## Background Jobs

### SyncFromXero

Reads `lastSyncAt` from Integration entity. Defaults to 7 days ago on first run. After a successful run, writes the current timestamp back to `lastSyncAt`.

**Execution:**
```bash
php command.php run-job --job-class="Espo\Modules\Xero\Jobs\SyncFromXero"
```

**Cron schedule (recommended):**
```
0 2 * * *  (2 AM daily)
```

### ReconcileXero

Finds records where `modifiedAt > xeroSyncedAt` (EspoCRM was modified more recently than last sync). Pushes those to Xero.

**Batch size:** 50 records per run (configurable in `Resources/metadata/integrations/Xero.json` via `reconcileBatchSize`).

**Execution:**
```bash
php command.php run-job --job-class="Espo\Modules\Xero\Jobs\ReconcileXero"
```

**Cron schedule (recommended):**
```
0 3 * * *  (3 AM daily, after SyncFromXero)
```

## Conflict Resolution

`Tools/ConflictResolver::resolve(?string $xeroLastUpdated, ?string $espoSyncedAt): string`

Returns one of: `WINNER_XERO`, `WINNER_ESPO`, `WINNER_NONE`.

Logic:
- Both null → NONE (no data to compare)
- Only Xero time known → XERO wins
- Only EspoCRM time known → ESPO wins
- Both known → newer timestamp wins; tie goes to ESPO

**Example:**
```
Xero Contact UpdatedUTC: 2026-05-26 10:00:00 UTC
EspoCRM Account xeroSyncedAt: 2026-05-26 09:00:00 UTC
Result: WINNER_XERO → pull Xero data into EspoCRM
```

## Xero API Endpoints Used

All calls go to: `https://api.xero.com/api.xro/2.0/`

| Operation | Method | Path | Notes |
|-----------|--------|------|-------|
| Create Contact | POST | `Contacts` | Returns ContactID |
| Update Contact | POST | `Contacts/{id}` | Requires ContactID in body |
| Query Contacts | GET | `Contacts` | Supports `where` filter with UpdatedUTC |
| Create Invoice | POST | `Invoices` | Returns InvoiceID |
| Update Invoice | POST | `Invoices/{id}` | Requires InvoiceID in body |
| Void Invoice | POST | `Invoices/{id}` | Status field set to VOIDED |
| Query Invoices | GET | `Invoices` | Supports `where` filter with UpdatedUTC |
| Query Payments | GET | `Payments` | Supports `where` filter with UpdatedUTC |
| Get Tenant | GET | `/connections` | Returns list of authorized tenants (called post-OAuth) |

All requests require: `Authorization: Bearer {accessToken}`, `Accept: application/json`, `Xero-tenant-id: {tenantId}`.

## Integration Entity Fields

The `Integration` entity (id=`Xero`) stores all credentials and state in its `data` JSON column. Access via:

```php
$integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'Xero');
$tenantId = $integration->get('tenantId');
```

**Fields:**

| Field | Type | Purpose |
|-------|------|---------|
| `clientId` | varchar(255) | Xero app Client ID (from developer.xero.com) |
| `clientSecret` | password(255) | Xero app Client Secret (encrypted) |
| `accessToken` | text | Bearer token; ~30 minutes; auto-refresh |
| `refreshToken` | text | Long-lived; ~60 days; allows getting new accessToken |
| `accessTokenExpiresAt` | datetime | Checked with 30-second margin before API calls |
| `tenantId` | varchar(64) | Xero organisation ID (UUID format) |
| `connectedAt` | datetime | When OAuth was last completed |
| `lastSyncAt` | datetime | Timestamp of last successful pull job |
| `defaultAccountCode` | varchar(32) | Account code for invoices (e.g., "200"); required by Xero |
| `oauthState` | varchar(64) | CSRF token generated during initOAuth; validated in callback |
| `lastSyncError` | text | Last error message (for debugging in UI) |

## Adding a New Entity Sync

To add sync for a new EspoCRM entity (e.g., Lead → Xero Contact):

1. **Add metadata fields** in `Resources/metadata/entityDefs/Lead.json`:
   ```json
   {
     "fields": {
       "xeroContactId": {"type": "varchar", "maxLength": 64},
       "xeroContactStatus": {"type": "varchar", "maxLength": 32},
       "xeroSyncedAt": {"type": "datetime"}
     }
   }
   ```

2. **Create hook** at `Hooks/Lead/XeroSync.php`:
   ```php
   class XeroSync implements AfterSave
   {
       public static int $order = 20;
       
       public function afterSave(Entity $entity, SaveOptions $options): void
       {
           if ($options->get('skipXeroSync')) return;
           try {
               $service = $this->injectableFactory->create(XeroService::class);
               $service->upsertContact('Lead', $entity);
           } catch (Throwable $e) {
               $this->log->warning("Xero Lead sync failed: " . $e->getMessage());
           }
       }
   }
   ```

3. **Extend XeroService** to handle new entity type:
   ```php
   public function upsertContact(string $entityType, Entity $entity): void
   {
       // Add case for 'Lead'
       if ($entityType === 'Lead') {
           $xeroContactId = $entity->get('xeroContactId');
           $payload = $this->buildContactPayload('Lead', $entity);
           // ... rest of logic
       }
   }
   ```

4. **Update pull job** in `Jobs/SyncFromXero.php`:
   ```php
   // Add Lead-specific pull logic
   ```

5. **Rebuild and test:**
   ```bash
   php rebuild.php
   vendor/bin/phpunit tests/unit/Espo/Modules/Xero/
   ```

## Xero-Specific Considerations

### HTTPS Required

Xero OAuth only accepts HTTPS redirect URIs. Plain HTTP will be rejected. The instance must be set up with an SSL certificate (mkcert in development, Let's Encrypt in production).

### Single Tenant Per Integration

The current implementation stores only one `tenantId`. If a user is authorized for multiple Xero organisations, only the first one from the `/connections` endpoint is stored. Multi-tenant support would require:
- Storing multiple tenantIds in a separate table
- Allowing admin to select which tenant to sync
- Updating all API calls to use the selected tenantId

This is a known gap (see `gap-analysis.md`).

### Account Code Requirement

Xero invoices require an `AccountCode` on each line item. The service uses `defaultAccountCode` from the integration config. Common codes:
- `200` — Sales revenue
- `410` — Cost of goods sold
- `500` — Consulting services

If not set, the sync will fail with an error. Update via **Administration → Integrations → Xero** and set `defaultAccountCode`.

### Contact vs. Account

Xero has a single "Contact" entity. EspoCRM's Account and Contact are both mapped to Xero Contact. This works because:
- Both can have name, email, phone, address
- The `xeroContactId` field is replicated on both entities
- When syncing, the service checks which entity type it is and maps accordingly

However, Xero cannot distinguish between them. If you create an Account and Contact with the same name in EspoCRM, they will both map to the same Xero Contact (via matching by name + email). Use unique emails to avoid collisions.

### Invoice Status Mapping

EspoCRM Invoice statuses map to Xero as follows:

| EspoCRM | Xero | Notes |
|---------|------|-------|
| Draft | DRAFT | Not yet submitted to customer |
| Submitted | SUBMITTED | Sent to customer, awaiting payment |
| Paid | PAID | Payment received (read-only in pull) |
| Voided | VOIDED | Cancelled; cannot be un-voided |

When pulling from Xero:
- If Xero Invoice.Status == PAID → Set EspoCRM Invoice.status = Paid

When pushing to Xero:
- EspoCRM Invoice.status = Draft → Xero Status = DRAFT
- EspoCRM Invoice.status = Submitted → Xero Status = SUBMITTED
- Xero does not allow directly setting to PAID; only Payments can mark as paid

## Testing

### Unit Tests

```bash
# Run Xero module tests only
vendor/bin/phpunit tests/unit/Espo/Modules/Xero/

# Run specific test file
vendor/bin/phpunit tests/unit/Espo/Modules/Xero/Services/XeroServiceTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage tests/unit/Espo/Modules/Xero/
```

Current test count: 87 passing tests covering:
- OAuth flow and token refresh
- Contact upsert (create and update)
- Invoice upsert and void
- Payment sync and status updates
- Conflict resolution logic
- Hook execution and loop guard
- Error handling and logging

### Integration Tests

Integration tests against a live Xero sandbox are not included in this release.
All shipped tests are unit tests using mocks.

### Manual Testing

1. Create an Account with `name`, `emailAddress`
2. Save → `xeroContactId` should auto-populate within 10 seconds
3. Verify in Xero → **Contacts** → contact appears
4. Edit Account name → save → Xero contact updates
5. Create Invoice linked to Account → `xeroInvoiceId` auto-populates
6. Verify in Xero → **Invoices** → invoice appears (status: DRAFT)
7. Run SyncFromXero job → pulls Xero contacts/payments
8. Check logs: `data/logs/espo.log` for any "Xero sync failed" warnings

## Troubleshooting

### OAuth Fails with "Invalid Redirect URI"

**Cause:** `siteUrl` is not HTTPS, or doesn't match the registered URI in developer.xero.com.

**Fix:**
1. Verify `siteUrl` in **Administration → Settings** is exactly `https://cake.local:8443`
2. Verify the registered redirect URI is `https://cake.local:8443?entryPoint=XeroOauthCallback`
3. Clear browser cache and try again

### "Xero: No access token"

**Cause:** Integration is not connected, or refresh failed.

**Fix:**
1. Reconnect via **Administration → Integrations → Xero** → **Connect to Xero**
2. Check `espo.log` for token refresh errors
3. Verify `clientSecret` is correct in the integration form

### Invoices Not Syncing

**Cause:** Account does not have `xeroContactId` set, or `defaultAccountCode` is missing.

**Fix:**
1. Ensure the linked Account has been saved at least once (triggers hook)
2. Verify `xeroContactId` is populated on the Account
3. In **Administration → Integrations → Xero**, verify `defaultAccountCode` is set (e.g., "200")
4. Check `espo.log` for specific error messages

### Duplicate Contacts in Xero

**Cause:** Two Accounts with same name but different emails were synced to Xero.

**Fix:**
1. Use unique email addresses for each Account
2. Manually delete the duplicate in Xero
3. Delete the EspoCRM Account with the wrong email
4. Re-sync the correct Account

### Token Expiration Loop

**Cause:** Refresh token has expired (~60 days). Must re-authorize.

**Fix:**
Reconnect via **Administration → Integrations → Xero** → **Connect to Xero**.
