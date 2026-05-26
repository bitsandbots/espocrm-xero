<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\Xero\EntryPoints\XeroOauthCallback;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class XeroOauthCallbackTest extends TestCase
{
    private EntityManager $em;
    private Config $config;
    private Log $log;
    private Request $request;
    private Response $response;

    private const FAKE_TOKENS = [
        'access_token' => 'access-tok-abc',
        'refresh_token' => 'refresh-tok-xyz',
        'expires_in' => 1800,
    ];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->config = $this->createMock(Config::class);
        $this->log = $this->createMock(Log::class);
        $this->request = $this->createMock(Request::class);
        $this->response = $this->createMock(Response::class);

        $this->config->method('get')->with('siteUrl')->willReturn('https://crm.example.com');
        $this->response->method('writeBody')->willReturnSelf();
    }

    // -------------------------------------------------------------------------
    // Rejection paths
    // -------------------------------------------------------------------------

    public function testRejectsOnErrorQueryParam(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', 'access_denied'],
        ]);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenCodeMissing(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', null],
            ['state', 'some-state'],
        ]);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenIntegrationNotFound(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['state', 'some-state'],
        ]);

        $this->em
            ->method('getEntityById')
            ->with(Integration::ENTITY_TYPE, 'Xero')
            ->willReturn(null);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenOauthStateNotStored(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['state', 'callback-state'],
        ]);

        $integration = $this->makeIntegration(storedState: null);
        $this->em->method('getEntityById')->willReturn($integration);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsOnStateMismatch(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['state', 'callback-state-WRONG'],
        ]);

        $integration = $this->makeIntegration(storedState: 'stored-state-ABC');
        $this->em->method('getEntityById')->willReturn($integration);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        $this->makeCallback()->run($this->request, $this->response);
    }

    public function testRejectsWhenNoTenantFound(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['state', 'matching-state'],
        ]);

        $integration = $this->makeIntegration(storedState: 'matching-state');
        $this->em->method('getEntityById')->willReturn($integration);

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'error'"));

        // Stub exchange succeeds but tenant fetch returns null
        $callback = $this->getMockBuilder(XeroOauthCallback::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['exchangeCodeForTokens', 'fetchFirstTenantId'])
            ->getMock();

        $callback->method('exchangeCodeForTokens')->willReturn(self::FAKE_TOKENS);
        $callback->method('fetchFirstTenantId')->willReturn(null);

        $callback->run($this->request, $this->response);
    }

    // -------------------------------------------------------------------------
    // Success path
    // -------------------------------------------------------------------------

    public function testSuccessRendersSuccessMessage(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['state', 'matching-state'],
        ]);

        $integration = $this->makeIntegration(storedState: 'matching-state');
        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->method('saveEntity');

        $this->response
            ->expects($this->once())
            ->method('writeBody')
            ->with($this->stringContains("status:'success'"));

        $this->makeCallbackWithFakeExchange('tenant-001')->run($this->request, $this->response);
    }

    public function testClearsOauthStateOnSuccess(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['state', 'matching-state'],
        ]);

        $integration = $this->makeIntegration(storedState: 'matching-state');
        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );

        $this->makeCallbackWithFakeExchange('tenant-001')->run($this->request, $this->response);

        $this->assertArrayHasKey('oauthState', $setCalls);
        $this->assertNull($setCalls['oauthState']);
    }

    public function testStoresTokenFieldsAndTenantIdOnSuccess(): void
    {
        $this->request->method('getQueryParam')->willReturnMap([
            ['error', null],
            ['code', 'auth-code-123'],
            ['state', 'matching-state'],
        ]);

        $integration = $this->makeIntegration(storedState: 'matching-state');
        $this->em->method('getEntityById')->willReturn($integration);

        $setCalls = [];
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$setCalls, $integration) {
                $setCalls[$k] = $v;
                return $integration;
            }
        );

        $this->makeCallbackWithFakeExchange('tenant-xyz')->run($this->request, $this->response);

        $this->assertSame(self::FAKE_TOKENS['access_token'], $setCalls['accessToken'] ?? null);
        $this->assertSame(self::FAKE_TOKENS['refresh_token'], $setCalls['refreshToken'] ?? null);
        $this->assertSame('tenant-xyz', $setCalls['tenantId'] ?? null);
        $this->assertArrayHasKey('connectedAt', $setCalls);
        $this->assertNotEmpty($setCalls['connectedAt']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeCallback(): XeroOauthCallback
    {
        return new XeroOauthCallback($this->em, $this->config, $this->log);
    }

    private function makeCallbackWithFakeExchange(string $tenantId): XeroOauthCallback
    {
        $callback = $this->getMockBuilder(XeroOauthCallback::class)
            ->setConstructorArgs([$this->em, $this->config, $this->log])
            ->onlyMethods(['exchangeCodeForTokens', 'fetchFirstTenantId'])
            ->getMock();

        $callback->method('exchangeCodeForTokens')->willReturn(self::FAKE_TOKENS);
        $callback->method('fetchFirstTenantId')->willReturn($tenantId);

        return $callback;
    }

    private function makeIntegration(?string $storedState): Integration
    {
        $integration = $this->createMock(Integration::class);

        $integration->method('get')->willReturnMap([
            ['oauthState', $storedState],
        ]);

        return $integration;
    }
}
