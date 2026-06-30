<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\DocuSign\Http;

use LauLamanApps\DocumentSigner\Sdk\Exception\ProviderException;
use LauLamanApps\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LauLamanApps\DocumentSigner\DocuSign\DocuSignConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

final class DocuSignClient
{
    private ClientInterface $http;

    public function __construct(
        private readonly DocuSignConfig $config,
        private readonly DocuSignJwtAuth $auth,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => $this->config->trimmedApiBaseUrl() . '/',
            'timeout'  => $this->config->timeoutSeconds,
        ]);
    }

    /**
     * @param array<string, mixed> $payload Full envelope definition with embedded base64 documents.
     * @return array<string, mixed>
     */
    public function createEnvelope(array $payload): array
    {
        return $this->jsonRequest('POST', $this->accountPath('envelopes'), [
            'json'    => $payload,
            'timeout' => $this->config->uploadTimeoutSeconds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnvelope(string $envelopeId): array
    {
        return $this->jsonRequest('GET', $this->accountPath('envelopes/' . rawurlencode($envelopeId)));
    }

    public function downloadCombinedPdf(string $envelopeId): string
    {
        return $this->rawRequest(
            'GET',
            $this->accountPath('envelopes/' . rawurlencode($envelopeId) . '/documents/combined'),
            ['headers' => ['Accept' => 'application/pdf']],
        );
    }

    public function voidEnvelope(string $envelopeId, ?string $reason): void
    {
        $this->jsonRequest('PUT', $this->accountPath('envelopes/' . rawurlencode($envelopeId)), [
            'json' => [
                'status'       => 'voided',
                'voidedReason' => $reason ?? 'Cancelled via SDK',
            ],
        ]);
    }

    private function accountPath(string $tail): string
    {
        return 'v2.1/accounts/' . rawurlencode($this->config->accountId) . '/' . $tail;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function jsonRequest(string $method, string $path, array $options = []): array
    {
        $body = $this->rawRequest($method, $path, $options);
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProviderException(
                "DocuSign returned non-JSON response for {$method} {$path}.",
                providerBody: $body,
                previous: $e,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function rawRequest(string $method, string $path, array $options = []): string
    {
        $options['headers'] = array_merge(
            ['Accept' => 'application/json'],
            $options['headers'] ?? [],
            ['Authorization' => 'Bearer ' . $this->auth->accessToken()],
        );

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            throw new ProviderException(
                "DocuSign {$method} {$path} failed: " . $e->getMessage(),
                httpStatus: $response?->getStatusCode(),
                providerBody: $response?->getBody()?->getContents(),
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException(
                "DocuSign {$method} {$path} failed: " . $e->getMessage(),
                previous: $e,
            );
        }

        return (string) $response->getBody();
    }
}
