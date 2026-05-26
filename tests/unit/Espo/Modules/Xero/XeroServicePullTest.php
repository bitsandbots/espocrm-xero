<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for XeroService pull-side behavior:
 * - applyPaymentToInvoice (via pullPaymentsSince)
 * - applyContactToAccount (via pullContactsSince)
 * - buildInvoicePayload (via upsertInvoice)
 *
 * request() is stubbed throughout; no real HTTP calls are made.
 */
class XeroServicePullTest extends TestCase
{
    private EntityManager $em;
    private Config $config;
    private Log $log;
    private Integration $integration;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->config = $this->createMock(Config::class);
        $this->log = $this->createMock(Log::class);

        $this->integration = $this->createMock(Integration::class);
        $this->integration->method('isEnabled')->willReturn(true);
        $this->integration->method('get')->willReturnMap([
            ['tenantId', 'tenant-abc'],
            ['accessToken', 'tok'],
            ['accessTokenExpiresAt', null],
            ['defaultAccountCode', '200'],
        ]);

        // Note: getEntityById is NOT pre-configured here — tests that need it
        // (invoice payload tests) set up both Integration + Account entries
        // per-test to avoid first-match conflicts with willReturnMap.
    }

    // -------------------------------------------------------------------------
    // applyPaymentToInvoice (via pullPaymentsSince)
    // -------------------------------------------------------------------------

    public function testPullPaymentsSetsInvoiceStatusToPaid(): void
    {
        $invoice = $this->createMock(Entity::class);

        $setCalls = [];
        $invoice->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $invoice) {
                $setCalls[$k] = $v;
                return $invoice;
            }
        );

        $this->em->method('getRDBRepository')
            ->with('Invoice')
            ->willReturn($this->makeRepo([$invoice]));

        $this->em->expects($this->once())->method('saveEntity')
            ->with($invoice, ['skipXeroSync' => true, 'silent' => true]);

        $paymentResponse = [
            'Payments' => [[
                'PaymentID' => 'pay-001',
                'Date' => '2026-05-15',
                'Status' => 'AUTHORISED',
                'Invoice' => ['InvoiceID' => 'inv-uuid-1'],
            ]],
        ];

        $service = $this->makeService($paymentResponse);
        $service->pullPaymentsSince('2026-01-01');

        $this->assertSame('Paid', $setCalls['status']);
        $this->assertSame('pay-001', $setCalls['xeroPaymentId']);
        $this->assertSame('2026-05-15', $setCalls['xeroPaymentDate']);
    }

    public function testPullPaymentsSkipsWhenNoMatchingInvoice(): void
    {
        $this->em->method('getRDBRepository')
            ->with('Invoice')
            ->willReturn($this->makeRepo([]));

        $this->em->expects($this->never())->method('saveEntity');

        $paymentResponse = [
            'Payments' => [[
                'PaymentID' => 'pay-999',
                'Date' => '2026-05-15',
                'Invoice' => ['InvoiceID' => 'inv-not-found'],
            ]],
        ];

        $service = $this->makeService($paymentResponse);
        $service->pullPaymentsSince('2026-01-01');
    }

    public function testPullPaymentsHandlesMissingInvoiceIdGracefully(): void
    {
        // Payment without an Invoice field — must not call getRDBRepository
        $this->em->expects($this->never())->method('getRDBRepository');

        $paymentResponse = [
            'Payments' => [[
                'PaymentID' => 'pay-orphan',
                'Date' => '2026-05-15',
                // No 'Invoice' key
            ]],
        ];

        $service = $this->makeService($paymentResponse);
        $service->pullPaymentsSince('2026-01-01');
    }

    // -------------------------------------------------------------------------
    // applyContactToAccount (via pullContactsSince)
    // -------------------------------------------------------------------------

    public function testPullContactsUpdatesAccountNameEmailAndPhone(): void
    {
        $account = $this->createMock(Entity::class);
        $account->method('get')->willReturnMap([
            ['name', 'Old Name'],
            ['xeroSyncedAt', null],
        ]);

        $setCalls = [];
        $account->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $account) {
                $setCalls[$k] = $v;
                return $account;
            }
        );

        $this->em->method('getRDBRepository')
            ->with('Account')
            ->willReturn($this->makeRepo([$account]));

        $this->em->expects($this->once())->method('saveEntity')
            ->with($account, ['skipXeroSync' => true, 'silent' => true]);

        $contactResponse = [
            'Contacts' => [[
                'ContactID' => 'xero-c-1',
                'Name' => 'New Corp Name',
                'EmailAddress' => 'new@corp.com',
                'Phones' => [['PhoneType' => 'DEFAULT', 'PhoneNumber' => '555-9999']],
            ]],
        ];

        $service = $this->makeService($contactResponse);
        $service->pullContactsSince('2026-01-01');

        $this->assertSame('New Corp Name', $setCalls['name']);
        $this->assertSame('new@corp.com', $setCalls['emailAddress']);
        $this->assertSame('555-9999', $setCalls['phoneNumber']);
        $this->assertArrayHasKey('xeroSyncedAt', $setCalls);
    }

    public function testPullContactsSkipsWhenXeroIsNotNewer(): void
    {
        $account = $this->createMock(Entity::class);
        // xeroSyncedAt is well after the Xero UpdatedDateUTC timestamp
        $account->method('get')->willReturnMap([
            ['xeroSyncedAt', '2026-06-01 00:00:00'],
        ]);

        $this->em->method('getRDBRepository')
            ->with('Account')
            ->willReturn($this->makeRepo([$account]));

        $this->em->expects($this->never())->method('saveEntity');

        // /Date(1748649600000+0000)/ ≈ 2025-05-31 — older than xeroSyncedAt
        $contactResponse = [
            'Contacts' => [[
                'ContactID' => 'xero-c-2',
                'Name' => 'Should Not Apply',
                'UpdatedDateUTC' => '/Date(1748649600000+0000)/',
            ]],
        ];

        $service = $this->makeService($contactResponse);
        $service->pullContactsSince('2026-01-01');
    }

    public function testPullContactsUpdatesPhoneFromDefaultPhoneTypeOnly(): void
    {
        $account = $this->createMock(Entity::class);
        $account->method('get')->willReturnMap([
            ['name', 'Acme'],
            ['xeroSyncedAt', null],
        ]);

        $setCalls = [];
        $account->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $account) {
                $setCalls[$k] = $v;
                return $account;
            }
        );

        $this->em->method('getRDBRepository')->willReturn($this->makeRepo([$account]));
        $this->em->method('saveEntity');

        $contactResponse = [
            'Contacts' => [[
                'ContactID' => 'xero-c-3',
                'Name' => 'Acme',
                'Phones' => [
                    ['PhoneType' => 'FAX', 'PhoneNumber' => '111-1111'],
                    ['PhoneType' => 'DEFAULT', 'PhoneNumber' => '555-2222'],
                    ['PhoneType' => 'MOBILE', 'PhoneNumber' => '333-3333'],
                ],
            ]],
        ];

        $service = $this->makeService($contactResponse);
        $service->pullContactsSince('2026-01-01');

        $this->assertSame('555-2222', $setCalls['phoneNumber']);
    }

    public function testPullContactsSkipsWhenContactIdMissing(): void
    {
        $this->em->expects($this->never())->method('getRDBRepository');

        $contactResponse = [
            'Contacts' => [[
                'Name' => 'No ContactID',
                // Missing ContactID key
            ]],
        ];

        $service = $this->makeService($contactResponse);
        $service->pullContactsSince('2026-01-01');
    }

    // -------------------------------------------------------------------------
    // buildInvoicePayload (via upsertInvoice with stubbed request)
    // -------------------------------------------------------------------------

    public function testUpsertInvoicePayloadHasCorrectStructure(): void
    {
        $account = $this->makeEntity(['xeroContactId' => 'contact-abc']);

        $this->em->method('getEntityById')->willReturnCallback(
            fn($type, $id) => match([$type, $id]) {
                [Integration::ENTITY_TYPE, 'Xero'] => $this->integration,
                ['Account', 'acc-1'] => $account,
                default => null,
            }
        );

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => '2026-07-01',
            'lineItems' => null,
            'amount' => 100.0,
            'name' => 'INV-007',
            'xeroInvoiceId' => null,
        ]);
        $invoice->method('set')->willReturnSelf();

        $capturedBody = null;
        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        $service->method('request')->willReturnCallback(
            function (string $method, string $url, ?array $body) use (&$capturedBody) {
                $capturedBody = $body;
                return ['Invoices' => [['InvoiceID' => 'new-inv-id']]];
            }
        );

        $this->em->method('saveEntity');

        $service->upsertInvoice($invoice);

        $payload = $capturedBody['Invoices'][0];
        $this->assertSame('ACCREC', $payload['Type']);
        $this->assertSame('contact-abc', $payload['Contact']['ContactID']);
        $this->assertSame('2026-07-01', $payload['DueDate']);
        $this->assertSame('DRAFT', $payload['Status']);
    }

    public function testUpsertInvoiceNewRecordHasNoInvoiceIdInPayload(): void
    {
        $account = $this->makeEntity(['xeroContactId' => 'contact-abc']);

        $this->em->method('getEntityById')->willReturnCallback(
            fn($type, $id) => match([$type, $id]) {
                [Integration::ENTITY_TYPE, 'Xero'] => $this->integration,
                ['Account', 'acc-1'] => $account,
                default => null,
            }
        );

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => '2026-07-01',
            'lineItems' => null,
            'amount' => 100.0,
            'name' => 'INV-008',
            'xeroInvoiceId' => null,
        ]);
        $invoice->method('set')->willReturnSelf();

        $capturedBody = null;
        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        $service->method('request')->willReturnCallback(
            function (string $method, string $url, ?array $body) use (&$capturedBody) {
                $capturedBody = $body;
                return ['Invoices' => [['InvoiceID' => 'created-id']]];
            }
        );

        $this->em->method('saveEntity');

        $service->upsertInvoice($invoice);

        $this->assertArrayNotHasKey('InvoiceID', $capturedBody['Invoices'][0]);
    }

    public function testUpsertInvoiceExistingRecordIncludesInvoiceId(): void
    {
        $account = $this->makeEntity(['xeroContactId' => 'contact-abc']);

        $this->em->method('getEntityById')->willReturnCallback(
            fn($type, $id) => match([$type, $id]) {
                [Integration::ENTITY_TYPE, 'Xero'] => $this->integration,
                ['Account', 'acc-1'] => $account,
                default => null,
            }
        );

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => '2026-07-01',
            'lineItems' => null,
            'amount' => 250.0,
            'name' => 'INV-009',
            'xeroInvoiceId' => 'existing-xero-id',
        ]);
        $invoice->method('set')->willReturnSelf();

        $capturedBody = null;
        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        $service->method('request')->willReturnCallback(
            function (string $method, string $url, ?array $body) use (&$capturedBody) {
                $capturedBody = $body;
                return ['Invoices' => [['InvoiceID' => 'existing-xero-id']]];
            }
        );

        $this->em->method('saveEntity');

        $service->upsertInvoice($invoice);

        $this->assertSame('existing-xero-id', $capturedBody['Invoices'][0]['InvoiceID']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $defaultResponse
     */
    private function makeService(array $defaultResponse): XeroService
    {
        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        $service->method('request')->willReturn($defaultResponse);

        return $service;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makeEntity(array $fields): Entity
    {
        $entity = $this->createMock(Entity::class);
        $map = array_map(fn($k, $v) => [$k, $v], array_keys($fields), array_values($fields));
        $entity->method('get')->willReturnMap($map);

        return $entity;
    }

    /**
     * @param array<Entity> $entities
     */
    private function makeRepo(array $entities): RDBRepository
    {
        $collection = new EntityCollection($entities);

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('limit')->willReturnSelf();
        $builder->method('find')->willReturn($collection);

        $repo = $this->createMock(RDBRepository::class);
        $repo->method('where')->willReturn($builder);

        return $repo;
    }
}
