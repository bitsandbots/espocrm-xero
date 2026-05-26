<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Xero\Hooks\Account\XeroSync as AccountSync;
use Espo\Modules\Xero\Hooks\Contact\XeroSync as ContactSync;
use Espo\Modules\Xero\Hooks\Invoice\XeroSync as InvoiceSync;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class HooksSyncTest extends TestCase
{
    private InjectableFactory $factory;
    private Log $log;
    private XeroService $service;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(InjectableFactory::class);
        $this->log = $this->createMock(Log::class);
        $this->service = $this->createMock(XeroService::class);

        $this->factory->method('create')->with(XeroService::class)->willReturn($this->service);
    }

    // -------------------------------------------------------------------------
    // Account hook
    // -------------------------------------------------------------------------

    public function testAccountSyncSkipsWhenFlagSet(): void
    {
        $entity = $this->createMock(Entity::class);
        $options = $this->makeOptions(skip: true);

        $this->service->expects($this->never())->method('upsertContact');

        (new AccountSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testAccountSyncCallsUpsertContact(): void
    {
        $entity = $this->createMock(Entity::class);
        $options = $this->makeOptions(skip: false);

        $this->service->expects($this->once())->method('upsertContact')->with('Account', $entity);

        (new AccountSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testAccountSyncLogsWarningOnError(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('acc-99');
        $options = $this->makeOptions(skip: false);

        $this->service->method('upsertContact')
            ->willThrowException(new \RuntimeException('API down'));

        $this->log->expects($this->once())->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('acc-99'),
                $this->stringContains('API down')
            ));

        // Must not throw
        (new AccountSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    // -------------------------------------------------------------------------
    // Contact hook
    // -------------------------------------------------------------------------

    public function testContactSyncSkipsWhenFlagSet(): void
    {
        $entity = $this->createMock(Entity::class);
        $options = $this->makeOptions(skip: true);

        $this->service->expects($this->never())->method('upsertContact');

        (new ContactSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testContactSyncCallsUpsertContact(): void
    {
        $entity = $this->createMock(Entity::class);
        $options = $this->makeOptions(skip: false);

        $this->service->expects($this->once())->method('upsertContact')->with('Contact', $entity);

        (new ContactSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testContactSyncLogsWarningOnError(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn('con-42');
        $options = $this->makeOptions(skip: false);

        $this->service->method('upsertContact')
            ->willThrowException(new \RuntimeException('timeout'));

        $this->log->expects($this->once())->method('warning')
            ->with($this->stringContains('con-42'));

        (new ContactSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    // -------------------------------------------------------------------------
    // Invoice hook — upsert path
    // -------------------------------------------------------------------------

    public function testInvoiceSyncSkipsWhenFlagSet(): void
    {
        $entity = $this->makeInvoiceEntity(status: 'Draft', xeroInvoiceId: null);
        $options = $this->makeOptions(skip: true);

        $this->service->expects($this->never())->method('upsertInvoice');
        $this->service->expects($this->never())->method('voidInvoice');

        (new InvoiceSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testInvoiceSyncCallsUpsertInvoiceForDraftStatus(): void
    {
        $entity = $this->makeInvoiceEntity(status: 'Draft', xeroInvoiceId: null);
        $options = $this->makeOptions(skip: false);

        $this->service->expects($this->once())->method('upsertInvoice')->with($entity);
        $this->service->expects($this->never())->method('voidInvoice');

        (new InvoiceSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testInvoiceSyncCallsUpsertInvoiceForSentStatus(): void
    {
        $entity = $this->makeInvoiceEntity(status: 'Sent', xeroInvoiceId: 'xero-inv-1');
        $options = $this->makeOptions(skip: false);

        $this->service->expects($this->once())->method('upsertInvoice')->with($entity);

        (new InvoiceSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testInvoiceSyncLogsWarningOnUpsertError(): void
    {
        $entity = $this->makeInvoiceEntity(status: 'Draft', xeroInvoiceId: null);
        $entity->method('getId')->willReturn('inv-5');
        $options = $this->makeOptions(skip: false);

        $this->service->method('upsertInvoice')
            ->willThrowException(new \RuntimeException('no contact'));

        $this->log->expects($this->once())->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('inv-5'),
                $this->stringContains('no contact')
            ));

        (new InvoiceSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    // -------------------------------------------------------------------------
    // Invoice hook — void path
    // -------------------------------------------------------------------------

    public function testInvoiceSyncCallsVoidInvoiceWhenStatusIsVoided(): void
    {
        $entity = $this->makeInvoiceEntity(status: 'Voided', xeroInvoiceId: 'xero-inv-77');
        $options = $this->makeOptions(skip: false);

        $this->service->expects($this->once())->method('voidInvoice')->with($entity);
        $this->service->expects($this->never())->method('upsertInvoice');

        (new InvoiceSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testInvoiceSyncSkipsVoidWhenNeverSynced(): void
    {
        // Voided status but no xeroInvoiceId — nothing to void in Xero
        $entity = $this->makeInvoiceEntity(status: 'Voided', xeroInvoiceId: null);
        $options = $this->makeOptions(skip: false);

        $this->service->expects($this->never())->method('voidInvoice');
        $this->service->expects($this->never())->method('upsertInvoice');

        (new InvoiceSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    public function testInvoiceSyncLogsWarningOnVoidError(): void
    {
        $entity = $this->makeInvoiceEntity(status: 'Voided', xeroInvoiceId: 'xero-inv-88');
        $entity->method('getId')->willReturn('inv-88');
        $options = $this->makeOptions(skip: false);

        $this->service->method('voidInvoice')
            ->willThrowException(new \RuntimeException('already voided'));

        $this->log->expects($this->once())->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('inv-88'),
                $this->stringContains('already voided')
            ));

        (new InvoiceSync($this->factory, $this->log))->afterSave($entity, $options);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeOptions(bool $skip): SaveOptions
    {
        $options = $this->createMock(SaveOptions::class);
        $options->method('get')->with('skipXeroSync')->willReturn($skip);

        return $options;
    }

    private function makeInvoiceEntity(string $status, ?string $xeroInvoiceId): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnMap([
            ['status', $status],
            ['xeroInvoiceId', $xeroInvoiceId],
        ]);

        return $entity;
    }
}
