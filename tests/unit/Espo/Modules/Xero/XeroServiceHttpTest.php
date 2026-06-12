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

/**
 * Tests for XeroService HTTP-facing methods.
 * The protected `request()` method is stubbed out so no real HTTP calls are made.
 */
class XeroServiceHttpTest extends TestCase
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
            ['accessToken', 'fake-access-token'],
            ['accessTokenExpiresAt', null],
            ['defaultAccountCode', null],
        ]);
    }

    // -------------------------------------------------------------------------
    // upsertContact
    // -------------------------------------------------------------------------

    public function testUpsertContactSetsXeroFieldsOnNewContact(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name' => 'Acme Corp',
            'emailAddress' => 'info@acme.com',
            'phoneNumber' => null,
            'website' => null,
            'billingAddressStreet' => null,
            'xeroContactId' => null,
        ]);

        $setCalls = [];
        $this->captureSet($entity, $setCalls);

        $service = $this->makeService([
            'Contacts' => [['ContactID' => 'xero-contact-001']],
        ]);

        // saveEntity is called for the entity write-back and for the sync log;
        // $setCalls assertions below verify the entity was updated correctly.
        $this->em->method('saveEntity');

        $service->upsertContact('Account', $entity);

        $this->assertSame('xero-contact-001', $setCalls['xeroContactId']);
        $this->assertArrayHasKey('xeroSyncedAt', $setCalls);
    }

    public function testUpsertContactIncludesContactIdWhenUpdating(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name' => 'Acme Corp',
            'emailAddress' => null,
            'phoneNumber' => null,
            'website' => null,
            'billingAddressStreet' => null,
            'xeroContactId' => 'existing-id-xyz',
        ]);

        $capturedPayload = null;
        $service = $this->makeService(
            ['Contacts' => [['ContactID' => 'existing-id-xyz']]],
            function (string $method, string $url, ?array $body) use (&$capturedPayload) {
                $capturedPayload = $body;
                return ['Contacts' => [['ContactID' => 'existing-id-xyz']]];
            }
        );

        $service->upsertContact('Account', $entity);

        $this->assertSame('existing-id-xyz', $capturedPayload['Contacts'][0]['ContactID']);
    }

    public function testUpsertContactThrowsWhenResponseMissingContacts(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $entity = $this->makeEntity([
            'name' => 'Acme Corp',
            'emailAddress' => null,
            'phoneNumber' => null,
            'website' => null,
            'billingAddressStreet' => null,
            'xeroContactId' => null,
        ]);

        $service = $this->makeService(['Contacts' => []]);

        $this->expectException(Error::class);

        $service->upsertContact('Account', $entity);
    }

    // -------------------------------------------------------------------------
    // upsertInvoice
    // -------------------------------------------------------------------------

    public function testUpsertInvoiceSetsXeroFieldsOnNewInvoice(): void
    {
        $account = $this->makeEntity(['xeroContactId' => 'contact-7']);
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
            ['Account', 'acc-1', $account],
        ]);

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => '2026-06-01',
            'lineItems' => null,
            'amount' => 500.0,
            'name' => 'INV-001',
            'xeroInvoiceId' => null,
        ]);

        $setCalls = [];
        $this->captureSet($invoice, $setCalls);

        $service = $this->makeService([
            'Invoices' => [['InvoiceID' => 'xero-inv-99']],
        ]);

        // saveEntity is called for the invoice write-back and for the sync log;
        // $setCalls assertions below verify the invoice was updated correctly.
        $this->em->method('saveEntity');

        $service->upsertInvoice($invoice);

        $this->assertSame('xero-inv-99', $setCalls['xeroInvoiceId']);
        $this->assertArrayHasKey('xeroSyncedAt', $setCalls);
    }

    public function testUpsertInvoiceIncludesInvoiceIdWhenUpdating(): void
    {
        $account = $this->makeEntity(['xeroContactId' => 'contact-7']);
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
            ['Account', 'acc-1', $account],
        ]);

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => '2026-06-01',
            'lineItems' => null,
            'amount' => 500.0,
            'name' => 'INV-001',
            'xeroInvoiceId' => 'existing-inv-id',
        ]);

        $capturedPayload = null;
        $service = $this->makeService(
            ['Invoices' => [['InvoiceID' => 'existing-inv-id']]],
            function (string $method, string $url, ?array $body) use (&$capturedPayload) {
                $capturedPayload = $body;
                return ['Invoices' => [['InvoiceID' => 'existing-inv-id']]];
            }
        );

        $service->upsertInvoice($invoice);

        $this->assertSame('existing-inv-id', $capturedPayload['Invoices'][0]['InvoiceID']);
    }

    public function testUpsertInvoiceThrowsWhenAccountHasNoXeroContactId(): void
    {
        $account = $this->makeEntity(['xeroContactId' => null]);
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
            ['Account', 'acc-1', $account],
        ]);

        $invoice = $this->makeEntity([
            'accountId' => 'acc-1',
            'dueDate' => null,
            'lineItems' => null,
            'amount' => 100.0,
            'name' => 'INV-001',
            'xeroInvoiceId' => null,
        ]);

        $service = $this->makeService([]);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Xero Contact ID');

        $service->upsertInvoice($invoice);
    }

    // -------------------------------------------------------------------------
    // voidInvoice
    // -------------------------------------------------------------------------

    public function testVoidInvoiceSendsVoidedStatus(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $invoice = $this->makeEntity([
            'xeroInvoiceId' => 'xero-inv-77',
        ]);

        $capturedArgs = null;
        $service = $this->makeService(
            ['Invoices' => []],
            function (string $method, string $url, ?array $body) use (&$capturedArgs) {
                $capturedArgs = ['method' => $method, 'url' => $url, 'body' => $body];
                return ['Invoices' => []];
            }
        );

        $service->voidInvoice($invoice);

        $this->assertSame('POST', $capturedArgs['method']);
        $this->assertStringContainsString('Invoices', $capturedArgs['url']);
        $this->assertSame('xero-inv-77', $capturedArgs['body']['Invoices'][0]['InvoiceID']);
        $this->assertSame('VOIDED', $capturedArgs['body']['Invoices'][0]['Status']);
    }

    public function testVoidInvoiceNoOpsWhenNotPreviouslySynced(): void
    {
        $invoice = $this->makeEntity([
            'xeroInvoiceId' => null,
        ]);

        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        $service->expects($this->never())->method('request');

        $service->voidInvoice($invoice);
    }

    // -------------------------------------------------------------------------
    // Pull methods
    // -------------------------------------------------------------------------

    public function testPullPaymentsSinceBuildsDatimeFilter(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $capturedUrl = null;
        $service = $this->makeService(
            ['Payments' => []],
            function (string $method, string $url) use (&$capturedUrl) {
                $capturedUrl = $url;
                return ['Payments' => []];
            }
        );

        $service->pullPaymentsSince('2026-01-15');

        $this->assertStringContainsString('Payments', $capturedUrl);
        $decoded = rawurldecode($capturedUrl);
        $this->assertStringContainsString('DateTime(2026,1,15', $decoded);
    }

    public function testPullContactsSinceSendsIfModifiedSinceHeader(): void
    {
        $this->em->method('getEntityById')->willReturnMap([
            [Integration::ENTITY_TYPE, 'Xero', $this->integration],
        ]);

        $capturedHeaders = null;
        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        $service->method('request')->willReturnCallback(
            function (string $method, string $url, ?array $body, array $headers) use (&$capturedHeaders) {
                $capturedHeaders = $headers;
                return ['Contacts' => []];
            }
        );

        $service->pullContactsSince('2026-01-15');

        $this->assertNotEmpty($capturedHeaders);
        $headerStr = implode(' ', $capturedHeaders);
        $this->assertStringContainsString('If-Modified-Since', $headerStr);
        $this->assertStringContainsString('2026', $headerStr);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $defaultResponse
     */
    private function makeService(array $defaultResponse, ?callable $callback = null): XeroService
    {
        $service = $this->getMockBuilder(XeroService::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['request'])
            ->getMock();

        if ($callback !== null) {
            $service->method('request')->willReturnCallback($callback);
        } else {
            $service->method('request')->willReturn($defaultResponse);
        }

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
     * Wires entity mock to capture set() calls into the provided array.
     *
     * @param array<string, mixed> $calls
     */
    private function captureSet(Entity $entity, array &$calls): void
    {
        $entity->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$calls, $entity) {
                $calls[$k] = $v;

                return $entity;
            }
        );
    }
}
