# Gap Analysis — EspoCRM Xero Integration

This document tracks known gaps and limitations in the EspoCRM Xero Integration as of 2026-05-27.
Gap #2 (Disconnect endpoint) was resolved in v1.1.
Previously resolved gaps are listed at the bottom; currently open gaps are organized by severity.

## Previously Fixed Gaps

The following gaps have been **resolved** in v1.0 and v1.0.1:

| # | Gap | Resolution | Version |
|---|-----|-----------|---------|
| 1 | Connect button missing in admin UI | Custom JS view with OAuth popup | v1.0 |
| 2 | `siteUrl` bug in OAuth callback | `XeroOauthCallback.php` injects `Config` | v1.0 |
| 3 | `lastSyncAt` field not declared | Added to `integrations/Xero.json` | v1.0 |
| 4 | Scheduled jobs not registered | `scheduledJobs.json` created | v1.0 |
| 5 | `xeroPaymentId` / `xeroPaymentDate` fields missing | Declared in `entityDefs/Invoice.json` | v1.0 |
| 6 | Invoice reverse links missing | `invoices` hasMany link added to `entityDefs/Account.json` | v1.0 |
| 7 | OAuth state generation missing | `postActionInitOAuth()` generates random hex state | v1.0 |
| 8 | Invoice CRUD controller missing | `Invoice` controller extending `Record` created | v1.0 |
| 9 | Wrong Xero OAuth scopes | Replaced `accounting.invoices`/`accounting.payments` (invalid) with `accounting.transactions`; added `openid profile email` | v1.0.1 |
| 10 | `oauthCodeVerifier` not persisted | Field declared in `integrations/Xero.json`; without it PKCE token exchange always failed (null verifier) | v1.0.1 |
| 11 | `state=undefined` in authUrl guard | Added type guard in JS: if `data.authUrl` is not a string, show explicit error instead of opening a broken popup | v1.0.1 |

## Currently Open Gaps

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
- `SyncFromXero` and `ReconcileXero` jobs log to `espo.log`
- Errors only visible to technical staff who can SSH to the server
- No integration with EspoCRM's audit log

**Fix Approach:**
Create a new `XeroSyncLog` entity with fields:
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

#### 7. No Xero Webhook Support

**Severity:** Low  
**Impact:** Sync is polling-only. Payment and contact changes are not reflected in real time;
updates happen on the nightly schedule only.

**Current Behavior:**
- `SyncFromXero` runs once per day (default: 2 AM)
- Payments and contact changes not reflected in EspoCRM for up to 24 hours
- No way to trigger immediate sync from Xero side

**Fix Approach:**
Xero webhooks require endpoint registration and HMAC-SHA256 signature verification. Implement:
1. Register webhook endpoint in Xero Developer Portal
2. Create EntryPoint to receive and verify webhook POST
3. On Payment event, immediately pull and update Invoice status
4. On Contact event, immediately pull and update Account/Contact

**Effort:** Large (~4-5 hours)

**Priority:** Low (nightly schedule is sufficient for most workflows)

---

#### 8. No Health Check Endpoint

**Severity:** Low  
**Impact:** Admins cannot verify the Xero connection without checking logs or running a manual sync job.

**Current Behavior:**
- Connection status only visible after OAuth completes (`connectedAt` field)
- No ping to test if the connection is still live
- If refresh token expires, admin discovers this by seeing sync failures

**Fix Approach:**
Add `GET /api/v1/XeroIntegration/ping` that:
1. Calls Xero's `/Organisation` endpoint with the stored access token
2. Returns `{ok: true}` or `{ok: false, error: "..."}` with HTTP 200/502
3. Triggers token refresh if needed before calling

**Effort:** Small (~30 minutes)

**Priority:** Low (nice-to-have for observability)

---

#### 10. No Tax Handling

**Severity:** Low  
**Impact:** Xero supports tax codes and rates on invoices. EspoCRM invoices have no tax fields.

**Current Behavior:**
- Invoice sync omits all tax information
- Xero apply default tax rates when invoice is synced
- Users cannot customize tax per invoice or line item

**Fix Approach:**
1. Add `taxRate`, `taxAmount`, `taxCode` fields to Invoice entity
2. Map to Xero tax objects during upsert
3. Pull tax information during sync

**Effort:** Medium (~1-2 hours)

**Priority:** Low (tax can be edited manually in Xero)

---

#### 11. No PDF Attachment Sync

**Severity:** Low  
**Impact:** EspoCRM can generate Invoice PDFs (dompdf available). These are not attached to Xero invoices.

**Current Behavior:**
- Invoice generates PDF when admin clicks "Download PDF"
- PDF is not sent to Xero
- Xero use their own templates

**Fix Approach:**
1. Generate Invoice PDF in `upsertInvoice()` after creating invoice
2. Attach as binary file to Xero invoice (if API supports)
3. This is likely Xero API limitation

**Effort:** Medium (~1-2 hours)

**Priority:** Low (Xero templates are usually sufficient)

---

#### 12. Opportunity → Invoice Not Automated

**Severity:** Low  
**Impact:** Common workflow: "Closed Won" Opportunity should auto-create Invoice and push to Xero.

**Current Behavior:**
- Opportunities exist in EspoCRM but do not trigger Invoice creation
- Admin must manually create Invoice for each opportunity
- Manual step is error-prone

**Fix Approach:**
1. Add hook on Opportunity afterSave
2. If status changes to "Closed Won":
   - Create Invoice from opportunity details (amount, customer, date)
   - Link Invoice to Account
   - Trigger Xero sync (invoice hook fires)
3. Add option to disable auto-invoice in integration config

**Effort:** Medium (~1-2 hours)

**Priority:** Low (workflow automation, not critical for MVP)

---

## Summary Table

| # | Gap | Severity | Status | Effort | Priority |
|---|-----|----------|--------|--------|----------|
| 4 | Multi-tenant Xero | Medium | Open | Large | Low |
| 5 | HTTPS warning in UI | Medium | Open | Small | Medium |
| 6 | Sync audit trail | Medium | Open | Medium | Medium |
| 7 | Xero webhook support | Low | Open | Large | Low |
| 8 | Health check endpoint | Low | Open | Small | Low |
| 10 | Tax handling | Low | Open | Medium | Low |
| 11 | PDF attachment sync | Low | Open | Medium | Low |
| 12 | Opportunity → Invoice | Low | Open | Medium | Low |
| 1–8 | v1.0 gaps (connect btn, siteUrl, fields, jobs, etc.) | — | **Resolved v1.0** | — | — |
| 9–11 | Wrong scopes, PKCE field, authUrl guard | — | **Resolved v1.0.1** | — | — |
| 2 | Disconnect (token-clear) endpoint | — | **Resolved v1.1** | — | — |

## Recommended Priority Order

For a production v1.1 rollout, address in this order:

1. ~~**High**: Disconnect endpoint~~ — **Done in v1.1**
2. **Medium**: Sync audit trail — improves observability for larger deployments
3. **Medium**: HTTPS UI warning — reduces user confusion during setup
4. **Low**: Health check ping — nice-to-have for monitoring
5. **Everything else**: Defer to v2

## Resolved Gaps

### v1.0

- Invoice CRUD controller — `Invoice` controller extending `Record` created
- Connect button — custom admin view with OAuth popup
- OAuth state generation — `initOAuth` endpoint generates cryptographic random state
- `siteUrl` injection — `Config` dependency injected in callback
- Scheduled job registration — `scheduledJobs.json` created
- Invoice reverse links — `invoices` hasMany link on Account
- `xeroPaymentId` / `xeroPaymentDate` fields — declared in entityDefs
- Xero hook naming collision — hooks named `XeroSync` (not `Sync`)

### v1.0.1

- **Wrong Xero OAuth scopes** — `accounting.invoices` and `accounting.payments` are not valid
  Xero scope names; replaced with `accounting.transactions`; added `openid profile email`
- **`oauthCodeVerifier` not persisted** — field was being set in PHP but not declared in
  `integrations/Xero.json`, so EspoCRM's ORM silently dropped it on save; PKCE token exchange
  always returned a 400 (null verifier sent to Xero)
- **`state=undefined` guard** — added type check in `actionConnectXero()` so a missing or
  non-string `authUrl` from the server shows an explicit error instead of opening a popup
  pointed at the literal string `"undefined"`

### v1.1

- **Disconnect endpoint** — `DELETE /api/v1/XeroIntegration/connection` clears `accessToken`,
  `refreshToken`, `tenantId`, and `connectedAt`; a **Disconnect** button (danger-styled, visible
  only when connected) appears in the admin integration view alongside a confirmation dialog
