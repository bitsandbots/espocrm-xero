<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class XeroServiceFieldMappingTest extends TestCase
{
    private XeroService $service;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $config = $this->createMock(Config::class);
        $log = $this->createMock(Log::class);

        $this->service = new XeroService($em, $config, $log);
    }

    public function testBuildContactPayloadForAccount(): void
    {
        $entity = $this->createMock(Entity::class);

        $entity->method('get')->willReturnMap([
            ['name', 'Acme Corp'],
            ['emailAddress', 'info@acme.com'],
            ['phoneNumber', '555-1234'],
            ['website', 'https://acme.com'],
            ['billingAddressStreet', '123 Main St'],
            ['billingAddressCity', 'Springfield'],
            ['billingAddressState', 'IL'],
            ['billingAddressPostalCode', '62701'],
            ['billingAddressCountry', 'US'],
        ]);

        $payload = $this->invokeMethod('buildContactPayload', ['Account', $entity]);

        $this->assertSame('Acme Corp', $payload['Name']);
        $this->assertSame('info@acme.com', $payload['EmailAddress']);
        $this->assertSame('DEFAULT', $payload['Phones'][0]['PhoneType']);
        $this->assertSame('555-1234', $payload['Phones'][0]['PhoneNumber']);
        $this->assertSame('https://acme.com', $payload['Website']);
        $this->assertSame('STREET', $payload['Addresses'][0]['AddressType']);
        $this->assertSame('123 Main St', $payload['Addresses'][0]['AddressLine1']);
        $this->assertSame('Springfield', $payload['Addresses'][0]['City']);
        $this->assertTrue($payload['IsCustomer']);
    }

    public function testBuildContactPayloadForContactPerson(): void
    {
        $entity = $this->createMock(Entity::class);

        $entity->method('get')->willReturnMap([
            ['firstName', 'Jane'],
            ['lastName', 'Doe'],
            ['name', 'Jane Doe'],
            ['emailAddress', 'jane@example.com'],
            ['phoneNumber', null],
            ['website', null],
            ['billingAddressStreet', null],
        ]);

        $payload = $this->invokeMethod('buildContactPayload', ['Contact', $entity]);

        $this->assertSame('Jane Doe', $payload['Name']);
        $this->assertSame('Jane', $payload['FirstName']);
        $this->assertSame('Doe', $payload['LastName']);
        $this->assertSame('jane@example.com', $payload['EmailAddress']);
        $this->assertArrayNotHasKey('Phones', $payload);
        $this->assertArrayNotHasKey('Addresses', $payload);
    }

    public function testBuildContactPayloadContactFallsBackToNameWhenNamesEmpty(): void
    {
        $entity = $this->createMock(Entity::class);

        $entity->method('get')->willReturnMap([
            ['firstName', ''],
            ['lastName', ''],
            ['name', 'Fallback Name'],
            ['emailAddress', null],
            ['phoneNumber', null],
            ['website', null],
            ['billingAddressStreet', null],
        ]);

        $payload = $this->invokeMethod('buildContactPayload', ['Contact', $entity]);

        $this->assertSame('Fallback Name', $payload['Name']);
    }

    public function testBuildLineItemsWithExplicitItems(): void
    {
        $lineItems = [
            ['description' => 'Widget A', 'quantity' => 2, 'unitPrice' => 10.0],
            ['description' => 'Widget B', 'quantity' => 1, 'unitPrice' => 25.0],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
            ['amount', null],
            ['name', 'INV-001'],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice]);

        $this->assertCount(2, $lines);
        $this->assertSame('Widget A', $lines[0]['Description']);
        $this->assertSame(2.0, $lines[0]['Quantity']);
        $this->assertSame(10.0, $lines[0]['UnitAmount']);
        $this->assertSame('Widget B', $lines[1]['Description']);
        $this->assertSame(25.0, $lines[1]['UnitAmount']);
    }

    public function testBuildLineItemsFallsBackToAmountWhenEmpty(): void
    {
        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', []],
            ['amount', 150.0],
            ['name', 'INV-002'],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice]);

        $this->assertCount(1, $lines);
        $this->assertSame(150.0, $lines[0]['UnitAmount']);
        $this->assertSame(1.0, $lines[0]['Quantity']);
        $this->assertArrayNotHasKey('AccountCode', $lines[0]);
    }

    public function testBuildLineItemsUsesPerItemAccountCode(): void
    {
        $lineItems = [
            ['description' => 'Consulting', 'quantity' => 1, 'unitPrice' => 100.0, 'xeroAccountCode' => '200'],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice, '400']);

        $this->assertSame('200', $lines[0]['AccountCode']);
    }

    public function testBuildLineItemsFallsBackToDefaultAccountCode(): void
    {
        $lineItems = [
            ['description' => 'Consulting', 'quantity' => 1, 'unitPrice' => 100.0],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice, '400']);

        $this->assertSame('400', $lines[0]['AccountCode']);
    }

    public function testBuildLineItemsNoAccountCodeWhenNeitherSet(): void
    {
        $lineItems = [
            ['description' => 'Consulting', 'quantity' => 1, 'unitPrice' => 100.0],
        ];

        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', $lineItems],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice]);

        $this->assertArrayNotHasKey('AccountCode', $lines[0]);
    }

    public function testBuildLineItemsFallbackAmountUsesDefaultAccountCode(): void
    {
        $invoice = $this->createMock(Entity::class);
        $invoice->method('get')->willReturnMap([
            ['lineItems', []],
            ['amount', 200.0],
            ['name', 'INV-003'],
        ]);

        $lines = $this->invokeMethod('buildLineItems', [$invoice, '500']);

        $this->assertCount(1, $lines);
        $this->assertSame('500', $lines[0]['AccountCode']);
    }

    /**
     * @param array<mixed> $args
     * @return mixed
     */
    private function invokeMethod(string $name, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($this->service, $name);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->service, $args);
    }
}
