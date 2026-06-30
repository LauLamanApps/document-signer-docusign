<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\DocuSign\Auth;

use LauLamanApps\DocumentSigner\Sdk\Exception\ProviderException;
use LauLamanApps\DocumentSigner\DocuSign\DocuSignConfig;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Mints, caches and refreshes a DocuSign access token via the JWT user-consent grant.
 *
 * The token is cached in memory until 60s before its expiry; callers should reuse one
 * instance per process to avoid hammering the oauth endpoint.
 */
final class DocuSignJwtAuth
{
    private ClientInterface $http;

    private ?string $cachedToken = null;
    private int     $cachedExpiresAt = 0;

    public function __construct(
        private readonly DocuSignConfig $config,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => 'https://' . $this->config->oauthBaseUrl . '/',
            'timeout'  => $this->config->timeoutSeconds,
        ]);
    }

    public function accessToken(): string
    {
        if ($this->cachedToken !== null && $this->cachedExpiresAt - 60 > time()) {
            return $this->cachedToken;
        }

        $now = time();
        $assertion = JWT::encode(
            [
                'iss'   => $this->config->integrationKey,
                'sub'   => $this->config->userId,
                'aud'   => $this->config->oauthBaseUrl,
                'iat'   => $now,
                'exp'   => $now + $this->config->accessTokenTtlSeconds,
                'scope' => $this->config->scopes,
            ],
            $this->config->privateKey,
            'RS256',
        );

        try {
            $response = $this->http->request('POST', 'oauth/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            throw new ProviderException(
                'DocuSign JWT exchange failed: ' . $e->getMessage(),
                httpStatus: $r?->getStatusCode(),
                providerBody: $r?->getBody()?->getContents(),
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException('DocuSign JWT exchange failed: ' . $e->getMessage(), previous: $e);
        }

        $body = (string) $response->getBody();
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException(
                'DocuSign JWT response was not valid JSON.',
                providerBody: $body,
                previous: $e,
            );
        }

        $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
        $expiresIn = is_array($decoded) ? ($decoded['expires_in'] ?? null) : null;

        if (!is_string($token) || $token === '' || !is_int($expiresIn)) {
            throw new ProviderException(
                'DocuSign JWT response missing access_token or expires_in.',
                providerBody: $body,
            );
        }

        $this->cachedToken     = $token;
        $this->cachedExpiresAt = $now + $expiresIn;

        return $token;
    }
}
