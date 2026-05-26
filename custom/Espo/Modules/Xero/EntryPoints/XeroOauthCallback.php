<?php

namespace Espo\Modules\Xero\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;

use DateTime;

/**
 * Handles the Xero OAuth2 authorization callback.
 * Xero redirects here with ?code=...&state=...
 *
 * Register as redirect URI: {siteUrl}?entryPoint=XeroOauthCallback
 *
 * After exchanging the code for tokens, fetches the tenant list from
 * /connections and stores the first authorized tenant ID.
 */
class XeroOauthCallback implements EntryPoint
{
    use NoAuth;

    private const TOKEN_ENDPOINT = 'https://identity.xero.com/connect/token';
    private const CONNECTIONS_URL = 'https://api.xero.com/connections';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    public function run(Request $request, Response $response): void
    {
        $error = $request->getQueryParam('error');

        if ($error) {
            $this->log->warning("Xero OAuth callback error: $error");
            $this->renderResult($response, false, "Xero authorization was denied: $error");

            return;
        }

        $code = $request->getQueryParam('code');
        $state = $request->getQueryParam('state');

        if (!$code) {
            $this->renderResult($response, false, "Missing authorization code in callback.");

            return;
        }

        try {
            /** @var ?Integration $integration */
            $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'Xero');

            if (!$integration) {
                $this->renderResult($response, false, "Xero integration not found.");

                return;
            }

            $storedState = $integration->get('oauthState');

            if (!$storedState || $state !== $storedState) {
                $this->log->warning("Xero OAuth: state mismatch. stored=[$storedState] received=[$state]");
                $this->renderResult($response, false, "Invalid OAuth state parameter.");

                return;
            }

            $tokens = $this->exchangeCodeForTokens($integration, $code);

            $accessToken = $tokens['access_token'] ?? null;

            if (!$accessToken) {
                throw new Error("Xero token exchange returned no access token.");
            }

            $tenantId = $this->fetchFirstTenantId($accessToken);

            if (!$tenantId) {
                throw new Error("No Xero organisations found for this connection. Authorize at least one organisation.");
            }

            $expiresAt = (new DateTime())
                ->modify('+' . ($tokens['expires_in'] ?? 1800) . ' seconds')
                ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

            $integration->set('accessToken', $accessToken);
            $integration->set('refreshToken', $tokens['refresh_token'] ?? null);
            $integration->set('accessTokenExpiresAt', $expiresAt);
            $integration->set('tenantId', $tenantId);
            $integration->set('connectedAt', (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));
            $integration->set('oauthState', null);
            $integration->set('oauthCodeVerifier', null);

            $this->entityManager->saveEntity($integration);

            $this->renderResult($response, true, "Xero connected successfully.");
        } catch (\Throwable $e) {
            $this->log->error("Xero OAuth callback exception: " . $e->getMessage());
            $this->renderResult($response, false, "Failed to connect: " . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     * @throws Error
     */
    protected function exchangeCodeForTokens(Integration $integration, string $code): array
    {
        $clientId = $integration->get('clientId');
        $clientSecret = $integration->get('clientSecret');
        $siteUrl = rtrim($this->config->get('siteUrl') ?? '', '/');
        $redirectUri = $siteUrl . '/?entryPoint=XeroOauthCallback';

        $ch = curl_init(self::TOKEN_ENDPOINT);

        $codeVerifier = $integration->get('oauthCodeVerifier');

        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode("$clientId:$clientSecret"),
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $httpCode !== 200) {
            throw new Error("Xero token exchange failed (HTTP $httpCode).");
        }

        return Json::decode($raw, true);
    }

    /**
     * Fetches the list of authorised Xero tenants and returns the first tenant ID.
     */
    protected function fetchFirstTenantId(string $accessToken): ?string
    {
        $ch = curl_init(self::CONNECTIONS_URL);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $httpCode !== 200) {
            $this->log->warning("Xero /connections call failed (HTTP $httpCode).");

            return null;
        }

        $connections = Json::decode($raw, true);

        foreach ($connections as $conn) {
            $id = $conn['tenantId'] ?? null;

            if ($id) {
                return $id;
            }
        }

        return null;
    }

    private function renderResult(Response $response, bool $success, string $message): void
    {
        $status = $success ? 'success' : 'error';
        $color = $success ? '#2b7de9' : '#c0392b';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><title>Xero Connection</title></head>
<body style="font-family:sans-serif;text-align:center;padding:40px;">
  <p style="color:{$color};font-size:16px;">{$message}</p>
  <script>
    if (window.opener) {
      window.opener.postMessage({name:'xeroOAuth',status:'{$status}'}, '*');
      setTimeout(function(){ window.close(); }, 1500);
    }
  </script>
</body>
</html>
HTML;

        $response->writeBody($html);
    }
}
