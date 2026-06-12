<?php

namespace Espo\Modules\Xero\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;

use DateTime;
use Exception;
use Throwable;

class XeroService
{
    private const API_BASE = 'https://api.xero.com/api.xro/2.0';
    private const TOKEN_ENDPOINT = 'https://identity.xero.com/connect/token';
    private const CONNECTIONS_URL = 'https://api.xero.com/connections';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    // -------------------------------------------------------------------------
    // Integration entity access
    // -------------------------------------------------------------------------

    private function getIntegration(): Integration
    {
        /** @var ?Integration $integration */
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'Xero');

        if (!$integration || !$integration->isEnabled()) {
            throw new Error("Xero integration is not enabled.");
        }

        return $integration;
    }

    private function saveIntegration(Integration $integration): void
    {
        $this->entityManager->saveEntity($integration);
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    private function getAccessToken(Integration $integration): string
    {
        $expiresAt = $integration->get('accessTokenExpiresAt');

        if ($expiresAt) {
            try {
                $dt = new DateTime($expiresAt);
                $dt->modify('-30 seconds');

                if ($dt->format('U') < (new DateTime())->format('U')) {
                    $this->refreshAccessToken($integration);
                }
            } catch (Exception) {
                $this->refreshAccessToken($integration);
            }
        }

        $token = $integration->get('accessToken');

        if (!$token) {
            throw new Error("Xero: No access token. Please connect via Admin → Integrations → Xero.");
        }

        return $token;
    }

    private function refreshAccessToken(Integration $integration): void
    {
        $clientId = $integration->get('clientId');
        $clientSecret = $integration->get('clientSecret');
        $refreshToken = $integration->get('refreshToken');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            throw new Error("Xero: Missing credentials for token refresh.");
        }

        $ch = curl_init(self::TOKEN_ENDPOINT);

        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode("$clientId:$clientSecret"),
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($raw)) {
            throw new Error("Xero: Token refresh failed (HTTP $httpCode).");
        }

        $result = Json::decode($raw, true);

        $expiresAt = (new DateTime())
            ->modify('+' . ($result['expires_in'] ?? 1800) . ' seconds')
            ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $integration->set('accessToken', $result['access_token'] ?? null);
        $integration->set('accessTokenExpiresAt', $expiresAt);

        if (!empty($result['refresh_token'])) {
            $integration->set('refreshToken', $result['refresh_token']);
        }

        $this->saveIntegration($integration);
    }

    // -------------------------------------------------------------------------
    // Raw HTTP
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     * @throws Error
     */
    protected function request(
        string $method,
        string $url,
        ?array $body = null,
        array $extraHeaders = []
    ): array {
        $integration = $this->getIntegration();
        $accessToken = $this->getAccessToken($integration);
        $tenantId = $integration->get('tenantId');

        if (!$tenantId) {
            throw new Error("Xero: tenantId not set. Please reconnect the integration.");
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $accessToken,
            'Xero-Tenant-Id: ' . $tenantId,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, Json::encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw)) {
            throw new Error("Xero: cURL request failed for $method $url.");
        }

        try {
            $result = Json::decode($raw, true);
        } catch (\JsonException $e) {
            $preview = substr($raw, 0, 200);
            throw new Error("Xero API error ($method $url): non-JSON response (HTTP $httpCode): $preview");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $detail = $result['Detail'] ?? ($result['Message'] ?? "HTTP $httpCode");
            throw new Error("Xero API error ($method $url): $detail");
        }

        return $result;
    }

    private function apiUrl(string $path): string
    {
        return self::API_BASE . '/' . ltrim($path, '/');
    }

    // -------------------------------------------------------------------------
    // Sync audit log
    // -------------------------------------------------------------------------

    /**
     * Writes one XeroSyncLog row. Never throws — a logging failure must not
     * interrupt a sync operation.
     */
    private function writeLog(
        string $direction,
        string $recordType,
        string $recordId,
        string $recordName,
        string $status,
        string $message = ''
    ): void {
        try {
            $log = $this->entityManager->getNewEntity('XeroSyncLog');
            $log->set('direction', $direction);
            $log->set('recordType', $recordType);
            $log->set('recordId', $recordId);
            $log->set('recordName', $recordName);
            $log->set('status', $status);
            $log->set('message', $message);
            $this->entityManager->saveEntity($log);
        } catch (Throwable $e) {
            $this->log->warning('XeroSyncLog write failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Date parsing — Xero uses /Date(ms+offset)/ format in some fields
    // -------------------------------------------------------------------------

    private function parseXeroDate(?string $xeroDate): ?int
    {
        if ($xeroDate === null) {
            return null;
        }

        if (preg_match('/\/Date\((-?\d+)/', $xeroDate, $m)) {
            return (int) round((int) $m[1] / 1000);
        }

        $ts = strtotime($xeroDate);

        return $ts === false ? null : $ts;
    }

    // -------------------------------------------------------------------------
    // Contact (Account / Contact → Xero Contact)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildContactPayload(string $entityType, Entity $entity): array
    {
        $payload = ['IsCustomer' => true];

        if ($entityType === 'Account') {
            $payload['Name'] = $entity->get('name');
        } else {
            $first = $entity->get('firstName') ?? '';
            $last = $entity->get('lastName') ?? '';
            $payload['Name'] = trim("$first $last") ?: ($entity->get('name') ?? '');
            $payload['FirstName'] = $first;
            $payload['LastName'] = $last;
        }

        $email = $entity->get('emailAddress');

        if ($email) {
            $payload['EmailAddress'] = $email;
        }

        $phone = $entity->get('phoneNumber');

        if ($phone) {
            $payload['Phones'] = [[
                'PhoneType' => 'DEFAULT',
                'PhoneNumber' => $phone,
            ]];
        }

        $website = $entity->get('website');

        if ($website) {
            $payload['Website'] = $website;
        }

        $billStreet = $entity->get('billingAddressStreet');

        if ($billStreet) {
            $payload['Addresses'] = [[
                'AddressType' => 'STREET',
                'AddressLine1' => $billStreet,
                'City' => $entity->get('billingAddressCity') ?? '',
                'Region' => $entity->get('billingAddressState') ?? '',
                'PostalCode' => $entity->get('billingAddressPostalCode') ?? '',
                'Country' => $entity->get('billingAddressCountry') ?? '',
            ]];
        }

        return $payload;
    }

    /**
     * Upsert an Account or Contact as a Xero Contact.
     *
     * @throws Error
     */
    public function upsertContact(string $entityType, Entity $entity): void
    {
        $entityId   = (string) ($entity->getId() ?? '');
        $entityName = (string) ($entity->get('name') ?? '');

        try {
            $payload = $this->buildContactPayload($entityType, $entity);

            $xeroId = $entity->get('xeroContactId');

            if ($xeroId) {
                $payload['ContactID'] = $xeroId;
            }

            $url = $this->apiUrl('Contacts');
            $result = $this->request('POST', $url, ['Contacts' => [$payload]]);

            $contact = $result['Contacts'][0] ?? null;

            if (!$contact) {
                throw new Error("Xero: Unexpected response from contact upsert.");
            }

            $now = (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

            $entity->set('xeroContactId', $contact['ContactID'] ?? null);
            $entity->set('xeroSyncedAt', $now);

            $this->entityManager->saveEntity($entity, ['skipXeroSync' => true, 'silent' => true]);

            $this->writeLog('push', $entityType, $entityId, $entityName, 'success');
        } catch (Throwable $e) {
            $this->writeLog('push', $entityType, $entityId, $entityName, 'error', $e->getMessage());
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Invoice (EspoCRM Invoice → Xero Invoice)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     * @throws Error
     */
    private function buildInvoicePayload(Entity $invoice): array
    {
        $accountId = $invoice->get('accountId');
        $account = $accountId
            ? $this->entityManager->getEntityById('Account', $accountId)
            : null;

        $xeroContactId = $account?->get('xeroContactId');

        if (!$xeroContactId) {
            throw new Error(
                "Xero: Cannot sync invoice — linked Account has no Xero Contact ID. Sync the Account first."
            );
        }

        $defaultAccountCode = $this->getIntegration()->get('defaultAccountCode') ?: null;

        $payload = [
            'Type' => 'ACCREC',
            'Contact' => ['ContactID' => $xeroContactId],
            'DueDate' => $invoice->get('dueDate'),
            'Status' => 'DRAFT',
            'LineItems' => $this->buildLineItems($invoice, $defaultAccountCode),
        ];

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(Entity $invoice, ?string $defaultAccountCode = null): array
    {
        $lineItems = $invoice->get('lineItems');

        if (empty($lineItems) || !is_array($lineItems)) {
            $amount = (float) ($invoice->get('amount') ?? 0);

            $line = [
                'Description' => $invoice->get('name') ?? 'Invoice',
                'Quantity' => 1.0,
                'UnitAmount' => $amount,
            ];

            if ($defaultAccountCode !== null) {
                $line['AccountCode'] = $defaultAccountCode;
            }

            return [$line];
        }

        $lines = [];

        foreach ($lineItems as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['unitPrice'] ?? 0);

            $line = [
                'Description' => $item['description'] ?? '',
                'Quantity' => $qty,
                'UnitAmount' => $price,
            ];

            $code = !empty($item['xeroAccountCode']) ? $item['xeroAccountCode'] : $defaultAccountCode;

            if ($code !== null) {
                $line['AccountCode'] = $code;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @throws Error
     */
    public function upsertInvoice(Entity $invoice): void
    {
        $invoiceId   = (string) ($invoice->getId() ?? '');
        $invoiceName = (string) ($invoice->get('name') ?? '');

        try {
            $payload = $this->buildInvoicePayload($invoice);

            $xeroId = $invoice->get('xeroInvoiceId');

            if ($xeroId) {
                $payload['InvoiceID'] = $xeroId;
            }

            $url = $this->apiUrl('Invoices');
            $result = $this->request('POST', $url, ['Invoices' => [$payload]]);

            $xeroInvoice = $result['Invoices'][0] ?? null;

            if (!$xeroInvoice) {
                throw new Error("Xero: Unexpected response from invoice upsert.");
            }

            $now = (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

            $invoice->set('xeroInvoiceId', $xeroInvoice['InvoiceID'] ?? null);
            $invoice->set('xeroSyncedAt', $now);

            $this->entityManager->saveEntity($invoice, ['skipXeroSync' => true, 'silent' => true]);

            $this->writeLog('push', 'Invoice', $invoiceId, $invoiceName, 'success');
        } catch (Throwable $e) {
            $this->writeLog('push', 'Invoice', $invoiceId, $invoiceName, 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Voids an Invoice in Xero. No-ops if the invoice was never synced.
     *
     * @throws Error
     */
    public function voidInvoice(Entity $invoice): void
    {
        $xeroId = $invoice->get('xeroInvoiceId');

        if (!$xeroId) {
            return;
        }

        $invoiceId   = (string) ($invoice->getId() ?? '');
        $invoiceName = (string) ($invoice->get('name') ?? '');

        try {
            $url = $this->apiUrl('Invoices');

            $this->request('POST', $url, [
                'Invoices' => [[
                    'InvoiceID' => $xeroId,
                    'Status' => 'VOIDED',
                ]],
            ]);

            $this->writeLog('push', 'Invoice', $invoiceId, $invoiceName, 'success', 'Voided');
        } catch (Throwable $e) {
            $this->writeLog('push', 'Invoice', $invoiceId, $invoiceName, 'error', $e->getMessage());
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Pull from Xero
    // -------------------------------------------------------------------------

    /**
     * Pull Xero Payments created on or after $sinceDate and update EspoCRM Invoice statuses.
     *
     * @throws Error
     */
    public function pullPaymentsSince(string $sinceDate): void
    {
        [$y, $m, $d] = explode('-', $sinceDate);
        // Xero where syntax: && for AND, == for string equality, no leading zeros in DateTime args.
        $y = (int) $y;
        $m = (int) $m;
        $d = (int) $d;
        $filter = urlencode("Date>=DateTime($y,$m,$d,0,0,0)&&Status==\"AUTHORISED\"");
        $url = $this->apiUrl("Payments?where=$filter");

        $result = $this->request('GET', $url);

        $payments = $result['Payments'] ?? [];

        foreach ($payments as $payment) {
            $this->applyPaymentToInvoice($payment);
        }
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function applyPaymentToInvoice(array $payment): void
    {
        $xeroInvoiceId = $payment['Invoice']['InvoiceID'] ?? null;

        if (!$xeroInvoiceId) {
            return;
        }

        $collection = $this->entityManager
            ->getRDBRepository('Invoice')
            ->where(['xeroInvoiceId' => $xeroInvoiceId])
            ->limit(0, 1)
            ->find();

        foreach ($collection as $invoice) {
            $invoice->set('status', 'Paid');
            $invoice->set('xeroPaymentId', $payment['PaymentID'] ?? null);
            $invoice->set('xeroPaymentDate', $payment['Date'] ?? null);

            $this->entityManager->saveEntity($invoice, ['skipXeroSync' => true, 'silent' => true]);

            $this->writeLog(
                'pull',
                'Invoice',
                (string) ($invoice->getId() ?? ''),
                (string) ($invoice->get('name') ?? ''),
                'success',
                'Marked Paid via Xero Payment ' . ($payment['PaymentID'] ?? '')
            );
        }
    }

    // -------------------------------------------------------------------------
    // Health check
    // -------------------------------------------------------------------------

    /**
     * Calls GET /Organisation to verify the stored credentials are still valid.
     * Triggers a token refresh if the access token is expiring soon.
     *
     * @return array{ok: true, organisation: string}
     * @throws Error
     */
    public function ping(): array
    {
        $result = $this->request('GET', $this->apiUrl('Organisation'));
        $name = $result['Organisations'][0]['Name'] ?? 'Unknown';

        return ['ok' => true, 'organisation' => $name];
    }

    /**
     * Pull Xero Contacts updated since $sinceDate and sync to EspoCRM Accounts.
     *
     * @throws Error
     */
    public function pullContactsSince(string $sinceDate): void
    {
        $dt = new DateTime($sinceDate);
        $ifModifiedSince = $dt->format('D, d M Y H:i:s') . ' GMT';

        $url = $this->apiUrl('Contacts');
        $result = $this->request('GET', $url, null, [
            'If-Modified-Since: ' . $ifModifiedSince,
        ]);

        $contacts = $result['Contacts'] ?? [];

        foreach ($contacts as $contact) {
            $this->applyContactToAccount($contact);
        }
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function applyContactToAccount(array $contact): void
    {
        $xeroId = $contact['ContactID'] ?? null;

        if (!$xeroId) {
            return;
        }

        $collection = $this->entityManager
            ->getRDBRepository('Account')
            ->where(['xeroContactId' => $xeroId])
            ->limit(0, 1)
            ->find();

        foreach ($collection as $account) {
            $xeroUpdatedTs = $this->parseXeroDate($contact['UpdatedDateUTC'] ?? null);
            $espoSyncedAt = $account->get('xeroSyncedAt');

            if ($xeroUpdatedTs !== null && $espoSyncedAt !== null) {
                if ($xeroUpdatedTs <= strtotime($espoSyncedAt)) {
                    continue;
                }
            }

            $account->set('name', $contact['Name'] ?? $account->get('name'));

            $email = $contact['EmailAddress'] ?? null;

            if ($email) {
                $account->set('emailAddress', $email);
            }

            foreach ($contact['Phones'] ?? [] as $phone) {
                if (($phone['PhoneType'] ?? '') === 'DEFAULT' && !empty($phone['PhoneNumber'])) {
                    $account->set('phoneNumber', $phone['PhoneNumber']);
                    break;
                }
            }

            $account->set('xeroSyncedAt', (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));

            $this->entityManager->saveEntity($account, ['skipXeroSync' => true, 'silent' => true]);

            $this->writeLog(
                'pull',
                'Account',
                (string) ($account->getId() ?? ''),
                (string) ($account->get('name') ?? ''),
                'success'
            );
        }
    }
}
