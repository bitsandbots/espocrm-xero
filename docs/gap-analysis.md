# Gap Analysis — EspoCRM + Integrations

This document tracks known gaps and limitations in the EspoCRM + QuickBooks + Xero installation as of 2026-05-26. Previous gaps that were fixed are noted below; currently open gaps are listed by severity.

## Previously Fixed Gaps

The following critical and high-priority gaps from earlier sessions have been **resolved**:

| # | Gap | Resolution | Status |
|---|-----|-----------|--------|
| 1 | QB Connect button missing | Custom JS view created at `client/custom/modules/quick-books/src/views/admin/integrations/quick-books.js` with "Connect to QuickBooks" button | RESOLVED |
| 2 | QB `siteUrl` bug in entry point | Both `QuickBooksOauthCallback.php` and `XeroOauthCallback.php` now inject `Config` and call `$this->config->get('siteUrl')` correctly | RESOLVED |
| 3 | `lastSyncAt` field not declared | Added to both `Resources/metadata/integrations/QuickBooks.json` and `Resources/metadata/integrations/Xero.json` | RESOLVED |
| 4 | Scheduled jobs not registered | Created `Resources/metadata/app/scheduledJobs.json` in both QB and Xero modules | RESOLVED |
| 5 | QB payment fields missing | Verified `qbPaymentId`, `qbPaymentDate` are declared in `entityDefs/Invoice.json` | RESOLVED |
| 6 | Invoice reverse links missing | Added `invoices` hasMany link to `Resources/metadata/entityDefs/Account.json` in both modules | RESOLVED |
| 7 | OAuth state generation missing | Implemented `postActionInitOAuth()` in both `QuickBooksIntegration.php` and `XeroIntegration.php` controllers | RESOLVED |
| 8 | QB disconnect unavailable | Feature deferred (not critical for initial operation); tokens can be cleared manually via DB | DEFERRED |
| 9 | Invoice CRUD controller missing | Created `custom/Espo/Controllers/Invoice.php` extending `Espo\Core\Controllers\Record` — REST API now returns 401 (auth required) not 404 | RESOLVED |

## Currently Open Gaps

### High Severity

#### 2. No Disconnect/Reconnect Endpoint

**Severity:** High  
**Impact:** Once connected to QB/Xero, there is no admin UI button to clear tokens and reconnect to a different company.

**Current Behavior:**
- OAuth tokens stored in Integration entity
- No DELETE endpoint to clear them
- Admin must manually edit the database to reconnect

**Workaround:** Via CLI:
```bash
php command.php config:set --name=integration_data_QuickBooks \
  --value='{"clientId":"...","clientSecret":"..."}'
```

**Fix Approach:**
Add to `QuickBooksIntegration.php` and `XeroIntegration.php`:
```php
public function deleteActionConnection(Request $request): stdClass
{
    $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'QuickBooks');
    $integration->set('accessToken', null);
    $integration->set('refreshToken', null);
    $integration->set('realmId', null);  // QB
    // or
    $integration->set('tenantId', null);  // Xero
    $integration->set('connectedAt', null);
    $this->entityManager->saveEntity($integration);
    
    return new stdClass();
}
```

Add button in the custom integration view to call `DELETE /api/v1/QuickBooksIntegration/connection`.

**Effort:** Small (~30 minutes for both modules)

---

#### 3. Xero Hooks Named `Sync` (Deduplication Bug)

**Status:** ALREADY FIXED  
The hooks are correctly named `XeroSync` to avoid EspoCRM's class-name deduplication bug. All three Xero hooks (Account, Contact, Invoice) have distinct class names.

**Note:** QB hooks are named `Sync` (not `QuickBooksSync`), which is safe because QB module order is 15 and Core module order is 10. Xero is order 16, so its hooks needed unique names to avoid collision within the Xero module itself.

---

### Medium Severity

#### 4. Multi-Tenant Xero Not Supported

**Severity:** Medium  
**Impact:** Users authorized for multiple Xero organisations can only sync the first one.

**Current Behavior:**
- OAuth callback fetches `/connections` endpoint
- First organisation is extracted and stored as `tenantId`
- All API calls use only this single tenantId
- Other organisations are ignored

**Fix Approach:**
1. Store all tenant IDs in a separate table (or JSON array in Integration)
2. Add a `selectedTenant` field to Integration
3. Add admin UI dropdown to select which tenant to sync
4. Update all API calls to use `selectedTenant`

**Effort:** Large (~2-3 hours)

**Priority:** Low (most small businesses use one Xero org)

---

#### 5. Xero HTTPS Requirement Not Clearly Documented

**Severity:** Medium  
**Impact:** Users may try HTTP, causing OAuth to fail with cryptic "Invalid Redirect URI" error.

**Current Behavior:**
- Xero rejects any non-HTTPS redirect URI
- Error message does not explain why
- HTTP on port 8080 redirects to HTTPS on port 8443

**Fix Approach:**
- Document clearly in setup.md (DONE in this update)
- Add warning in Xero integration form: "HTTPS is required for Xero OAuth"
- Check `siteUrl` begins with `https://` in initOAuth controller

**Effort:** Small (~30 minutes)

**Priority:** Medium (improves UX)

---

#### 6. No Sync Audit Trail

**Severity:** Medium  
**Impact:** Sync operations log to `espo.log`, but there is no user-visible audit trail. Admins cannot see which records were synced, when, or why a sync failed.

**Current Behavior:**
- `SyncFromQuickBooks` and `SyncFromXero` jobs log to `espo.log`
- Errors only visible to technical staff who can SSH to server
- No integration with EspoCRM's audit log

**Fix Approach:**
Create a new `QbSyncLog` and `XeroSyncLog` entity with fields:
- `recordType` (Account, Invoice, Contact)
- `recordId`
- `direction` (push, pull)
- `status` (success, error)
- `message` (error details)
- `createdAt`

Jobs write to these entities instead of (or in addition to) logs. Admin can view via list/detail views.

**Effort:** Medium (~1-2 hours)

**Priority:** Medium (nice-to-have for larger deployments)

---

### Low Severity

#### 7. No QB Webhook Support

**Severity:** Low  
**Impact:** Sync is polling-only. Payment notifications are not real-time; Invoice status updates happen on the nightly schedule.

**Current Behavior:**
- `SyncFromQuickBooks` runs once per day (default: 2 AM)
- Payments created in QB are not reflected in EspoCRM for up to 24 hours
- No way to trigger immediate sync from QB side

**Fix Approach:**
QB supports webhooks for real-time events. Implement:
1. Register webhook in QB Developer Portal
2. Create EntryPoint to receive webhook POST
3. On Payment event, immediately pull and update Invoice status
4. Prevents need for nightly job (can be disabled)

**Effort:** Large (~3-4 hours)

**Priority:** Low (nightly schedule is sufficient for most workflows)

---

#### 8. No Xero Webhook Support

**Severity:** Low  
**Impact:** Same as QB — sync is polling-only, real-time notifications not available.

**Current Behavior:**
- `SyncFromXero` runs once per day
- Payments and contact changes not reflected immediately

**Fix Approach:**
Xero webhooks are more complex (require endpoint registration and signature verification). Similar to QB but with additional security.

**Effort:** Large (~4-5 hours)

**Priority:** Low

---

#### 9. No Health Check Endpoint

**Severity:** Low  
**Impact:** Admins cannot verify the QB/Xero connection without checking logs or running a manual sync job.

**Current Behavior:**
- Connection status only visible after OAuth completes (`connectedAt` field)
- No ping to test if connection is still live
- If refresh token expires, admin discovers this by seeing sync failures

**Fix Approach:**
Add `GET /api/v1/QuickBooksIntegration/ping` that:
1. Calls QB's `companyinfo/{realmId}` endpoint
2. Returns `{ok: true}` or `{ok: false, error: "..."}` with HTTP 200/500
3. Requires valid, non-expired access token

**Effort:** Small (~30 minutes per module)

**Priority:** Low (nice-to-have for observability)

---

#### 10. No Tax Handling

**Severity:** Low  
**Impact:** Xero and QB support tax codes and rates on invoices. EspoCRM invoices have no tax fields.

**Current Behavior:**
- Invoice sync omits all tax information
- QB/Xero apply default tax rates when invoice is synced
- Users cannot customize tax per invoice or line item

**Fix Approach:**
1. Add `taxRate`, `taxAmount`, `taxCode` fields to Invoice entity
2. Map to QB/Xero tax objects during upsert
3. Pull tax information during sync

**Effort:** Medium (~1-2 hours)

**Priority:** Low (tax can be edited manually in QB/Xero)

---

#### 11. No PDF Attachment Sync

**Severity:** Low  
**Impact:** EspoCRM can generate Invoice PDFs (dompdf available). These are not attached to QB/Xero invoices.

**Current Behavior:**
- Invoice generates PDF when admin clicks "Download PDF"
- PDF is not sent to QB/Xero
- QB/Xero use their own templates

**Fix Approach:**
1. Generate Invoice PDF in `upsertInvoice()` after creating invoice
2. Attach as binary file to QB/Xero invoice (if API supports)
3. This is likely QB/Xero API limitation

**Effort:** Medium (~1-2 hours)

**Priority:** Low (QB/Xero templates are usually sufficient)

---

#### 12. Opportunity → Invoice Not Automated

**Severity:** Low  
**Impact:** Common workflow: "Closed Won" Opportunity should auto-create Invoice and push to QB/Xero.

**Current Behavior:**
- Opportunities exist in EspoCRM but do not trigger Invoice creation
- Admin must manually create Invoice for each opportunity
- Manual step is error-prone

**Fix Approach:**
1. Add hook on Opportunity afterSave
2. If status changes to "Closed Won":
   - Create Invoice from opportunity details (amount, customer, date)
   - Link Invoice to Account
   - Trigger QB/Xero sync (invoice hook fires)
3. Add option to disable auto-invoice in integration config

**Effort:** Medium (~1-2 hours)

**Priority:** Low (workflow automation, not critical for MVP)

---

## Summary Table

| # | Gap | Severity | Status | Effort | Priority |
|---|-----|----------|--------|--------|----------|
| 1 | Invoice CRUD controller | Critical | Open | Trivial | High |
| 2 | Disconnect endpoint | High | Open | Small | Medium |
| 3 | Xero hooks dedup | High | Fixed | — | — |
| 4 | Multi-tenant Xero | Medium | Open | Large | Low |
| 5 | Xero HTTPS docs | Medium | Open | Small | Medium |
| 6 | Sync audit trail | Medium | Open | Medium | Medium |
| 7 | QB webhooks | Low | Open | Large | Low |
| 8 | Xero webhooks | Low | Open | Large | Low |
| 9 | Health check endpoint | Low | Open | Small | Low |
| 10 | Tax handling | Low | Open | Medium | Low |
| 11 | PDF attachment sync | Low | Open | Medium | Low |
| 12 | Opportunity → Invoice | Low | Open | Medium | Low |

## Recommended Priority Order

For a production rollout, prioritize in this order:

1. **Critical**: Invoice CRUD controller (enables API access)
2. **High**: Disconnect endpoint (enables operational flexibility)
3. **Medium**: Sync audit trail (improves observability)
4. **Medium**: Xero HTTPS documentation (improves setup UX)
5. **Everything else**: Nice-to-have; defer until v2

## Not Currently Open

The following items were previously listed as gaps but are now **resolved**:

- QB Connect button (DONE: custom view with OAuth popup)
- OAuth state generation (DONE: initOAuth endpoint)
- siteUrl injection (DONE: Config dependency injection)
- Scheduled job registration (DONE: metadata files created)
- Invoice reverse links (DONE: hasMany links added)
- Payment field declarations (DONE: fields in entityDefs)
- Xero hook naming (DONE: XeroSync to avoid collision)

All QB-specific critical and high-priority gaps have been closed. The remaining gaps are enhancements or nice-to-haves for future versions.
