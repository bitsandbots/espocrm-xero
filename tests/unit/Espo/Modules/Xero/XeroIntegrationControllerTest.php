<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Entities\Integration;
use Espo\Entities\User;
use Espo\Modules\Xero\Controllers\XeroIntegration;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class XeroIntegrationControllerTest extends TestCase
{
    private EntityManager $em;
    private InjectableFactory $factory;
    private Config $config;
    private User $user;
    private Request $request;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->factory = $this->createMock(InjectableFactory::class);
        $this->config = $this->createMock(Config::class);
        $this->user = $this->createMock(User::class);
        $this->request = $this->createMock(Request::class);

        $this->config->method('get')->with('siteUrl')->willReturn('https://cake.local:8443');
    }

    private function makeController(): XeroIntegration
    {
        return new XeroIntegration($this->em, $this->factory, $this->config, $this->user);
    }

    private function makeIntegrationMock(string $clientId = 'TEST_CLIENT_ID'): Integration
    {
        $integration = $this->createMock(Integration::class);
        $integration->method('get')->willReturnCallback(
            fn (string $k) => match ($k) {
                'clientId' => $clientId,
                default    => null,
            }
        );
        $integration->method('set')->willReturnSelf();
        return $integration;
    }

    public function testThrowsForbiddenForNonAdminUser(): void
    {
        $this->user->method('isAdmin')->willReturn(false);

        $this->expectException(Forbidden::class);

        $this->makeController();
    }

    public function testInitOAuthReturnsAuthUrl(): void
    {
        $this->user->method('isAdmin')->willReturn(true);

        $this->em->method('getEntityById')
            ->with(Integration::ENTITY_TYPE, 'Xero')
            ->willReturn($this->makeIntegrationMock());

        $result = $this->makeController()->postActionInitOAuth($this->request);

        $this->assertObjectHasProperty('authUrl', $result);
        $this->assertStringStartsWith('https://login.xero.com/identity/connect/authorize?', $result->authUrl);
        $this->assertStringContainsString('client_id=TEST_CLIENT_ID', $result->authUrl);
        $this->assertStringContainsString('code_challenge=', $result->authUrl);
        $this->assertStringContainsString('code_challenge_method=S256', $result->authUrl);
        $this->assertStringContainsString('redirect_uri=', $result->authUrl);
        $this->assertStringContainsString('offline_access', $result->authUrl);
    }

    public function testInitOAuthStateIsCryptographicallyRandom(): void
    {
        $this->user->method('isAdmin')->willReturn(true);

        $this->em->method('getEntityById')->willReturn($this->makeIntegrationMock());

        $controller = $this->makeController();

        $url1 = $controller->postActionInitOAuth($this->request)->authUrl;
        $url2 = $controller->postActionInitOAuth($this->request)->authUrl;

        parse_str(parse_url($url1, PHP_URL_QUERY), $p1);
        parse_str(parse_url($url2, PHP_URL_QUERY), $p2);

        $this->assertNotSame($p1['state'], $p2['state']);
        $this->assertNotSame($p1['code_challenge'], $p2['code_challenge']);
    }

    public function testInitOAuthPersistsStateToIntegration(): void
    {
        $this->user->method('isAdmin')->willReturn(true);

        $integration = $this->createMock(Integration::class);
        $integration->method('get')->willReturnCallback(
            fn (string $k) => match ($k) {
                'clientId' => 'TEST_CLIENT_ID',
                default    => null,
            }
        );

        $savedState = null;
        $integration->method('set')->willReturnCallback(
            function (string $k, mixed $v) use (&$savedState, $integration) {
                if ($k === 'oauthState') {
                    $savedState = $v;
                }
                return $integration;
            }
        );

        $this->em->method('getEntityById')->willReturn($integration);
        $this->em->expects($this->once())->method('saveEntity')->with($integration);

        $result = $this->makeController()->postActionInitOAuth($this->request);

        parse_str(parse_url($result->authUrl, PHP_URL_QUERY), $params);
        $this->assertSame($params['state'], $savedState);
    }
}
