<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\Xero\Jobs\SyncFromXero;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

use Throwable;

class SyncFromXeroTest extends TestCase
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

        $this->service->expects($this->never())->method('pullContactsSince');
        $this->service->expects($this->never())->method('pullPaymentsSince');

        $this->makeJob()->run();
    }

    public function testSkipsWhenIntegrationDisabled(): void
    {
        $integration = $this->makeIntegration(enabled: false);

        $this->em->method('getEntityById')->willReturn($integration);

        $this->service->expects($this->never())->method('pullContactsSince');

        $this->makeJob()->run();
    }

    public function testUsesLastSyncAtDateWhenAvailable(): void
    {
        $integration = $this->makeIntegration(lastSyncAt: '2026-04-15 10:00:00');

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('saveEntity');

        $capturedDate = null;
        $this->service->method('pullContactsSince')
            ->willReturnCallback(function (string $date) use (&$capturedDate) {
                $capturedDate = $date;
            });

        $this->makeJob()->run();

        $this->assertSame('2026-04-15', $capturedDate);
    }

    public function testFallsBackToSevenDaysWhenNoLastSync(): void
    {
        $integration = $this->makeIntegration(lastSyncAt: null);

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('saveEntity');

        $capturedDate = null;
        $this->service->method('pullContactsSince')
            ->willReturnCallback(function (string $date) use (&$capturedDate) {
                $capturedDate = $date;
            });

        $this->makeJob()->run();

        $this->assertNotNull($capturedDate);
        $sevenDaysAgo = (new \DateTime())->modify('-7 days')->format('Y-m-d');
        $this->assertSame($sevenDaysAgo, $capturedDate);
    }

    public function testCallsBothPullMethods(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('saveEntity');

        $this->service->expects($this->once())->method('pullContactsSince');
        $this->service->expects($this->once())->method('pullPaymentsSince');

        $this->makeJob()->run();
    }

    public function testUpdatesLastSyncAtAfterRun(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );

        $this->em->expects($this->once())->method('saveEntity')->with($integration);

        $this->makeJob()->run();

        $this->assertArrayHasKey('lastSyncAt', $setCalls);
        $this->assertNotEmpty($setCalls['lastSyncAt']);
    }

    public function testClearsLastSyncErrorOnCleanRun(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );
        $this->em->method('saveEntity');

        $this->makeJob()->run();

        $this->assertArrayHasKey('lastSyncError', $setCalls);
        $this->assertNull($setCalls['lastSyncError']);
    }

    public function testRecordsContactPullError(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );
        $this->em->method('saveEntity');

        $this->service->method('pullContactsSince')
            ->willThrowException(new \RuntimeException('Xero API timeout'));

        $this->makeJob()->run();

        $this->assertStringContainsString('Contacts', $setCalls['lastSyncError'] ?? '');
        $this->assertStringContainsString('Xero API timeout', $setCalls['lastSyncError'] ?? '');
    }

    public function testRecordsPaymentPullError(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );
        $this->em->method('saveEntity');

        $this->service->method('pullPaymentsSince')
            ->willThrowException(new \RuntimeException('Rate limit exceeded'));

        $this->makeJob()->run();

        $this->assertStringContainsString('Payments', $setCalls['lastSyncError'] ?? '');
        $this->assertStringContainsString('Rate limit exceeded', $setCalls['lastSyncError'] ?? '');
    }

    public function testRecordsBothErrorsWhenBothFail(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );
        $this->em->method('saveEntity');

        $this->service->method('pullContactsSince')
            ->willThrowException(new \RuntimeException('Contact error'));

        $this->service->method('pullPaymentsSince')
            ->willThrowException(new \RuntimeException('Payment error'));

        $this->makeJob()->run();

        $error = $setCalls['lastSyncError'] ?? '';
        $this->assertStringContainsString('Contacts', $error);
        $this->assertStringContainsString('Payments', $error);
    }

    public function testStillSavesIntegrationEvenWhenPullFails(): void
    {
        $integration = $this->makeIntegration();

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->expects($this->once())->method('saveEntity')->with($integration);

        $this->service->method('pullContactsSince')
            ->willThrowException(new \RuntimeException('failure'));

        $this->makeJob()->run();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeJob(): SyncFromXero
    {
        return new SyncFromXero($this->factory, $this->em, $this->log);
    }

    private function makeIntegration(
        bool $enabled = true,
        ?string $lastSyncAt = null
    ): Integration {
        $integration = $this->createMock(Integration::class);
        $integration->method('isEnabled')->willReturn($enabled);
        $integration->method('get')->willReturnMap([
            ['lastSyncAt', $lastSyncAt],
        ]);
        $integration->method('set')->willReturnSelf();

        return $integration;
    }
}
