<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\Xero\Jobs\ReconcileXero;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ReconcileXeroTest extends TestCase
{
    private EntityManager $em;
    private InjectableFactory $factory;
    private Log $log;
    private XeroService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->factory = $this->createMock(InjectableFactory::class);
        $this->log = $this->createMock(Log::class);
        $this->service = $this->createMock(XeroService::class);

        $this->factory->method('create')->with(XeroService::class)->willReturn($this->service);
    }

    public function testSkipsWhenIntegrationNotFound(): void
    {
        $this->em->method('getEntityById')
            ->with(Integration::ENTITY_TYPE, 'Xero')
            ->willReturn(null);

        $this->service->expects($this->never())->method('upsertContact');
        $this->service->expects($this->never())->method('upsertInvoice');

        $this->makeJob()->run();
    }

    public function testSkipsWhenIntegrationDisabled(): void
    {
        $integration = $this->makeIntegration(enabled: false);

        $this->em->method('getEntityById')->willReturn($integration);

        $this->service->expects($this->never())->method('upsertContact');

        $this->makeJob()->run();
    }

    public function testSkipsAccountWhenModifiedAtNotNewerThanSyncedAt(): void
    {
        $integration = $this->makeIntegration();

        $account = $this->makeEntity([
            'xeroContactId' => 'contact-1',
            'xeroSyncedAt' => '2026-05-10 12:00:00',
            'modifiedAt' => '2026-05-10 11:00:00',
        ]);

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback($this->twoPassAccountRepo([], [$account]));
        $this->em->method('saveEntity');

        $this->service->expects($this->never())->method('upsertContact');

        $this->makeJob()->run();
    }

    public function testPushesAccountWhenModifiedAtIsNewer(): void
    {
        $integration = $this->makeIntegration();

        $account = $this->makeEntity([
            'xeroContactId' => 'contact-1',
            'xeroSyncedAt' => '2026-05-10 10:00:00',
            'modifiedAt' => '2026-05-10 12:00:00',
        ]);

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback($this->twoPassAccountRepo([], [$account]));
        $this->em->method('saveEntity');

        $this->service->expects($this->once())->method('upsertContact')->with('Account', $account);

        $this->makeJob()->run();
    }

    public function testSkipsAccountWhenEitherDateMissing(): void
    {
        $integration = $this->makeIntegration();

        $accountNoSync = $this->makeEntity([
            'xeroContactId' => 'contact-1',
            'xeroSyncedAt' => null,
            'modifiedAt' => '2026-05-10 12:00:00',
        ]);

        $accountNoModified = $this->makeEntity([
            'xeroContactId' => 'contact-2',
            'xeroSyncedAt' => '2026-05-10 10:00:00',
            'modifiedAt' => null,
        ]);

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback($this->twoPassAccountRepo([], [$accountNoSync, $accountNoModified]));
        $this->em->method('saveEntity');

        $this->service->expects($this->never())->method('upsertContact');

        $this->makeJob()->run();
    }

    public function testPushesInvoiceWhenModifiedAtIsNewer(): void
    {
        $integration = $this->makeIntegration();

        $invoice = $this->makeEntity([
            'xeroInvoiceId' => 'inv-1',
            'xeroSyncedAt' => '2026-05-10 10:00:00',
            'modifiedAt' => '2026-05-10 12:00:00',
        ]);

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback(fn($type) => $this->makeRepo($type === 'Invoice' ? [$invoice] : []));
        $this->em->method('saveEntity');

        $this->service->expects($this->once())->method('upsertInvoice')->with($invoice);

        $this->makeJob()->run();
    }

    public function testSkipsInvoiceWhenNotNewer(): void
    {
        $integration = $this->makeIntegration();

        $invoice = $this->makeEntity([
            'xeroInvoiceId' => 'inv-1',
            'xeroSyncedAt' => '2026-05-10 12:00:00',
            'modifiedAt' => '2026-05-10 11:00:00',
        ]);

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback(fn($type) => $this->makeRepo($type === 'Invoice' ? [$invoice] : []));
        $this->em->method('saveEntity');

        $this->service->expects($this->never())->method('upsertInvoice');

        $this->makeJob()->run();
    }

    public function testAccountUpsertErrorIsLoggedAndDoesNotHaltOtherRecords(): void
    {
        $integration = $this->makeIntegration();

        $failingAccount = $this->makeEntity([
            'xeroContactId' => 'contact-bad',
            'xeroSyncedAt' => '2026-05-10 10:00:00',
            'modifiedAt' => '2026-05-10 12:00:00',
        ]);
        $failingAccount->method('getId')->willReturn('acc-bad');

        $goodAccount = $this->makeEntity([
            'xeroContactId' => 'contact-good',
            'xeroSyncedAt' => '2026-05-10 10:00:00',
            'modifiedAt' => '2026-05-10 12:00:00',
        ]);

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback($this->twoPassAccountRepo([], [$failingAccount, $goodAccount]));
        $this->em->method('saveEntity');

        $this->service->method('upsertContact')
            ->willReturnCallback(function (string $type, Entity $entity) use ($failingAccount) {
                if ($entity === $failingAccount) {
                    throw new \RuntimeException('API error');
                }
            });

        $this->log->expects($this->once())->method('warning')
            ->with($this->stringContains('acc-bad'));

        $this->service->expects($this->exactly(2))->method('upsertContact');

        $this->makeJob()->run();
    }

    public function testRecordsAccountErrorInIntegration(): void
    {
        $integration = $this->makeIntegration();

        $account = $this->makeEntity([
            'xeroContactId' => 'contact-1',
            'xeroSyncedAt' => '2026-05-10 10:00:00',
            'modifiedAt' => '2026-05-10 12:00:00',
        ]);
        $account->method('getId')->willReturn('acc-1');

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback(fn($type) => $this->makeRepo($type === 'Account' ? [$account] : []));

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );
        $this->em->method('saveEntity');

        $this->service->method('upsertContact')
            ->willThrowException(new \RuntimeException('upsert failed'));

        $this->makeJob()->run();

        $this->assertStringContainsString('acc-1', $setCalls['lastSyncError'] ?? '');
    }

    public function testClearsLastSyncErrorOnCleanRun(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('getRDBRepository')
            ->willReturnCallback(fn($type) => $this->makeRepo([]));

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );
        $this->em->expects($this->once())->method('saveEntity')->with($integration);

        $this->makeJob()->run();

        $this->assertArrayHasKey('lastSyncError', $setCalls);
        $this->assertNull($setCalls['lastSyncError']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeJob(): ReconcileXero
    {
        return new ReconcileXero($this->factory, $this->em, $this->log);
    }

    private function makeIntegration(bool $enabled = true): Integration
    {
        $integration = $this->createMock(Integration::class);
        $integration->method('isEnabled')->willReturn($enabled);
        $integration->method('set')->willReturnSelf();

        return $integration;
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
     * Returns a getRDBRepository callback that handles the two-pass Account query in
     * reconcileAccounts(): first call = unsynced batch, second call = modified batch.
     *
     * @param array<Entity> $unsyncedEntities  Returned on the first 'Account' call.
     * @param array<Entity> $syncedEntities    Returned on the second 'Account' call.
     */
    private function twoPassAccountRepo(array $unsyncedEntities, array $syncedEntities): \Closure
    {
        $accountCalls = 0;
        return function (string $type) use (&$accountCalls, $unsyncedEntities, $syncedEntities) {
            if ($type !== 'Account') {
                return $this->makeRepo([]);
            }
            return ++$accountCalls === 1
                ? $this->makeRepo($unsyncedEntities)
                : $this->makeRepo($syncedEntities);
        };
    }

    /**
     * Returns a properly-typed repository mock that yields $entities from find().
     *
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
