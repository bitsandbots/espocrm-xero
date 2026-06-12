<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests that XeroService writes XeroSyncLog entries on push/pull operations.
 */
class XeroSyncLogTest extends TestCase
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
            ['tenantId',             'tenant-abc'],
            ['accessToken',          'fake-token'],
            ['accessTokenExpiresAt', null],
            ['defaultAccountCode',   null],
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     */
    private function makeEntity(array $fields, string $id = 'entity-123'): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $map = array_map(fn ($k, $v) => [$k, $v], array_keys($fields), array_values($fields));
        $entity->method('get')->willReturnMap($map);
        $entity->method('set')->willReturnSelf();
        return $entity;
    }

    private function makeServiceWithRequest(array $response): XeroService
    {
        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();
        $service->method('request')->willReturn($response);
        return $service;
    }

    /**
     * Wire up the EntityManager mock to hand back $logEntity for getNewEntity('XeroSyncLog').
     * Returns a reference to the array that captures set() calls on $logEntity.
     *
     * @param array<string, mixed> $logFields (by reference — filled by log entity's set() calls)
     */
    private function wireLogEntity(array &$logFields): Entity
    {
        $logEntity = $this->createMock(Entity::class);
        $logEntity->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$logFields, $logEntity) {
                $logFields[$k] = $v;
                return $logEntity;
            }
        );
        $this->em->method('getNewEntity')->willReturnMap([['XeroSyncLog', $logEntity]]);
        return $logEntity;
    }

    // -------------------------------------------------------------------------
    // upsertContact — success log
    // -------------------------------------------------------------------------

    public function testUpsertContactWritesSuccessLog(): void
    {
        $logFields = [];
        $this->wireLogEntity($logFields);

        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name'                 => 'Acme Corp',
            'emailAddress'         => null,
            'phoneNumber'          => null,
            'website'              => null,
            'billingAddressStreet' => null,
            'xeroContactId'        => null,
        ]);

        $service = $this->makeServiceWithRequest([
            'Contacts' => [['ContactID' => 'xero-c-1']],
        ]);

        $service->upsertContact('Account', $entity);

        $this->assertSame('push',       $logFields['direction']);
        $this->assertSame('Account',    $logFields['recordType']);
        $this->assertSame('entity-123', $logFields['recordId']);
        $this->assertSame('Acme Corp',  $logFields['recordName']);
        $this->assertSame('success',    $logFields['status']);
    }

    // -------------------------------------------------------------------------
    // upsertContact — error log + rethrow
    // -------------------------------------------------------------------------

    public function testUpsertContactWritesErrorLogAndRethrows(): void
    {
        $logFields = [];
        $this->wireLogEntity($logFields);

        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name'                 => 'Fail Corp',
            'emailAddress'         => null,
            'phoneNumber'          => null,
            'website'              => null,
            'billingAddressStreet' => null,
            'xeroContactId'        => null,
        ]);

        // Empty array triggers the "Unexpected response" Error.
        $service = $this->makeServiceWithRequest(['Contacts' => []]);

        try {
            $service->upsertContact('Account', $entity);
            $this->fail('Expected Error was not thrown.');
        } catch (Error) {
            // Exception must propagate
        }

        $this->assertSame('error',    $logFields['status']);
        $this->assertNotEmpty($logFields['message']);
    }

    // -------------------------------------------------------------------------
    // upsertInvoice — success log
    // -------------------------------------------------------------------------

    public function testUpsertInvoiceWritesSuccessLog(): void
    {
        $logFields = [];
        $this->wireLogEntity($logFields);

        $account = $this->makeEntity(['xeroContactId' => 'contact-7'], 'acc-1');
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
            ['Account', 'acc-1', $account],
        ]);

        $invoice = $this->makeEntity([
            'accountId'    => 'acc-1',
            'dueDate'      => '2026-06-01',
            'lineItems'    => null,
            'amount'       => 500.0,
            'name'         => 'INV-001',
            'xeroInvoiceId' => null,
        ]);

        $service = $this->makeServiceWithRequest([
            'Invoices' => [['InvoiceID' => 'xero-inv-1']],
        ]);

        $service->upsertInvoice($invoice);

        $this->assertSame('push',    $logFields['direction']);
        $this->assertSame('Invoice', $logFields['recordType']);
        $this->assertSame('success', $logFields['status']);
        $this->assertSame('INV-001', $logFields['recordName']);
    }

    // -------------------------------------------------------------------------
    // voidInvoice — success log
    // -------------------------------------------------------------------------

    public function testVoidInvoiceWritesSuccessLog(): void
    {
        $logFields = [];
        $this->wireLogEntity($logFields);

        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $invoice = $this->makeEntity([
            'xeroInvoiceId' => 'xero-inv-77',
            'name'          => 'INV-007',
        ]);

        $service = $this->makeServiceWithRequest(['Invoices' => []]);

        $service->voidInvoice($invoice);

        $this->assertSame('push',    $logFields['direction']);
        $this->assertSame('Invoice', $logFields['recordType']);
        $this->assertSame('success', $logFields['status']);
        $this->assertStringContainsString('Voided', $logFields['message']);
    }

    // -------------------------------------------------------------------------
    // writeLog failure is silent — log entity's set() throws, sync still succeeds
    // -------------------------------------------------------------------------

    public function testWriteLogFailureDoesNotPropagateOrBreakSync(): void
    {
        // set() throws on the log entity → writeLog catches it → sync succeeds
        $brokenLog = $this->createMock(Entity::class);
        $brokenLog->method('set')->willThrowException(new RuntimeException('DB write failed'));
        $this->em->method('getNewEntity')->willReturnMap([['XeroSyncLog', $brokenLog]]);

        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name'                 => 'Acme Corp',
            'emailAddress'         => null,
            'phoneNumber'          => null,
            'website'              => null,
            'billingAddressStreet' => null,
            'xeroContactId'        => null,
        ]);

        $service = $this->makeServiceWithRequest([
            'Contacts' => [['ContactID' => 'xero-c-2']],
        ]);

        // Must not throw despite writeLog failing
        $service->upsertContact('Account', $entity);

        $this->assertTrue(true); // reached without exception
    }
}
