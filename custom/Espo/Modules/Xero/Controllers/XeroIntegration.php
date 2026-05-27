<?php

namespace Espo\Modules\Xero\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Entities\Integration;
use Espo\Entities\User;
use Espo\Modules\Xero\Jobs\ReconcileXero;
use Espo\Modules\Xero\Jobs\SyncFromXero;
use Espo\Modules\Xero\Services\XeroService;
use Espo\ORM\EntityManager;

use stdClass;
use Throwable;

class XeroIntegration
{
    private const AUTHORIZE_ENDPOINT = 'https://login.xero.com/identity/connect/authorize';
    private const SCOPES = 'openid profile email offline_access accounting.transactions accounting.contacts';

    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private Config $config,
        private User $user,
        private XeroService $xeroService,
    ) {
        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }
    }

    /**
     * Generates a cryptographically random OAuth state token, persists it to
     * the Integration entity, and returns it to the frontend. The frontend
     * must embed this value in the Xero authorization URL; the callback will
     * then verify it before accepting any tokens.
     *
     * @throws Forbidden
     * @throws Error
     */
    public function postActionInitOAuth(Request $request): stdClass
    {
        /** @var ?Integration $integration */
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'Xero');

        if (!$integration) {
            throw new Error("Xero integration not found.");
        }

        $clientId = $integration->get('clientId');

        if (!$clientId) {
            throw new Error("Xero Client ID not configured.");
        }

        $state = bin2hex(random_bytes(16));
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $integration->set('oauthState', $state);
        $integration->set('oauthCodeVerifier', $codeVerifier);
        $this->entityManager->saveEntity($integration);

        $siteUrl = rtrim($this->config->get('siteUrl') ?? '', '/');
        $redirectUri = $siteUrl . '/?entryPoint=XeroOauthCallback';

        $authUrl = self::AUTHORIZE_ENDPOINT . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        $result = new stdClass();
        $result->authUrl = $authUrl;

        return $result;
    }

    /**
     * Verifies the stored Xero credentials by calling GET /Organisation.
     * Triggers a token refresh if the access token is expiring soon.
     * Always returns HTTP 200; check `ok` in the response body.
     *
     * Response: {ok: true, organisation: string} | {ok: false, error: string}
     */
    public function getActionPing(Request $request): stdClass
    {
        $result = new stdClass();

        try {
            $ping = $this->xeroService->ping();
            $result->ok = true;
            $result->organisation = $ping['organisation'];
        } catch (Throwable $e) {
            $result->ok = false;
            $result->error = $e->getMessage();
        }

        return $result;
    }

    /**
     * Clears all OAuth tokens from the Integration entity, disconnecting
     * EspoCRM from Xero without starting a new OAuth session.
     *
     * @throws Forbidden
     * @throws Error
     */
    public function deleteActionConnection(Request $request): stdClass
    {
        /** @var ?Integration $integration */
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'Xero');

        if (!$integration) {
            throw new Error("Xero integration not found.");
        }

        $integration->set('accessToken', null);
        $integration->set('refreshToken', null);
        $integration->set('tenantId', null);
        $integration->set('connectedAt', null);
        $this->entityManager->saveEntity($integration);

        return new stdClass();
    }

    /**
     * Runs SyncFromXero then ReconcileXero synchronously.
     * Suitable for on-demand use via the admin UI on small datasets.
     *
     * @throws Forbidden
     */
    public function postActionRunSync(Request $request): stdClass
    {
        $this->injectableFactory->create(SyncFromXero::class)->run();
        $this->injectableFactory->create(ReconcileXero::class)->run();

        $result = new stdClass();
        $result->success = true;

        return $result;
    }
}
