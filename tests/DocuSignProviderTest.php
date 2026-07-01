<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\DocuSign\Tests;

use LauLamanApps\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LauLamanApps\DocumentSigner\DocuSign\DocuSignConfig;
use LauLamanApps\DocumentSigner\DocuSign\DocuSignProvider;
use LauLamanApps\DocumentSigner\DocuSign\Http\DocuSignClient;
use LauLamanApps\DocumentSigner\Sdk\Document\Document;
use LauLamanApps\DocumentSigner\Sdk\Envelope\Envelope;
use LauLamanApps\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LauLamanApps\DocumentSigner\Sdk\Exception\ProviderException;
use LauLamanApps\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LauLamanApps\DocumentSigner\Sdk\Signer\Signer;
use LauLamanApps\DocumentSigner\Sdk\Signer\SigningOrder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignProviderTest extends TestCase
{
    private static ?string $privateKey = null;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource === false) {
            self::markTestSkipped('openssl_pkey_new failed; skipping provider tests.');
        }
        openssl_pkey_export($resource, $pem);
        self::$privateKey = $pem;
    }

    #[Test]
    public function send_uploads_base64_pdf_and_returns_receipt_with_provider_name(): void
    {
        $envelope = $this->envelopeWithOneSigner();

        [$provider, $history] = $this->buildProvider([
            // JWT exchange:
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            // Envelope create:
            new Response(201, [], json_encode(['envelopeId' => 'env-abc', 'status' => 'sent'])),
        ]);

        $receipt = $provider->send($envelope);

        self::assertSame(DocuSignProvider::NAME, $receipt->provider);
        self::assertSame('docusign', $receipt->provider);
        self::assertSame('env-abc', $receipt->providerEnvelopeId);
        self::assertSame(EnvelopeStatus::Sent, $receipt->status);

        self::assertCount(2, $history);
        $envelopeRequest = $history[1]['request'];
        self::assertSame('POST', $envelopeRequest->getMethod());
        self::assertStringContainsString('/envelopes', (string) $envelopeRequest->getUri());

        $payload = json_decode((string) $envelopeRequest->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sent', $payload['status']);
        self::assertSame('Please sign the NDA', $payload['emailSubject']);
        self::assertSame('Jane Doe', $payload['recipients']['signers'][0]['name']);
        self::assertSame(
            '**DS:signature:s1:sig**',
            $payload['recipients']['signers'][0]['tabs']['signHereTabs'][0]['anchorString'],
        );
        self::assertNotEmpty($payload['documents'][0]['documentBase64']);
        self::assertStringStartsWith('%PDF-FAKE', base64_decode($payload['documents'][0]['documentBase64']));
    }

    #[Test]
    public function send_throws_when_response_lacks_envelope_id(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(201, [], json_encode(['noEnvelope' => true])),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('did not return an envelopeId');

        $provider->send($this->envelopeWithOneSigner());
    }

    #[Test]
    public function send_throws_a_validation_exception_with_the_provider_message_for_400_responses(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(400, [], json_encode([
                'errorCode' => 'INVALID_EMAIL_ADDRESS',
                'message'   => 'The email address is invalid.',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderValidationException.');
        } catch (\LauLamanApps\DocumentSigner\Sdk\Exception\ProviderValidationException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('INVALID_EMAIL_ADDRESS', $e->providerCode);
            self::assertSame('The email address is invalid.', $e->providerMessage);
            self::assertStringContainsString('[400 INVALID_EMAIL_ADDRESS]', $e->getMessage());
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function send_carries_the_envelope_id_when_the_error_body_echoes_one(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(409, [], json_encode([
                'errorCode'  => 'ENVELOPE_ALREADY_SENT',
                'message'    => 'The envelope is already in the sent state.',
                'envelopeId' => 'env-echo-42',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderValidationException.');
        } catch (\LauLamanApps\DocumentSigner\Sdk\Exception\ProviderValidationException $e) {
            self::assertSame('env-echo-42', $e->providerEnvelopeId);
        }
    }

    #[Test]
    public function send_throws_an_authentication_exception_for_401_responses(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(401, [], json_encode([
                'errorCode' => 'AUTHORIZATION_INVALID_TOKEN',
                'message'   => 'The access token is missing or invalid.',
            ])),
        ]);

        try {
            $provider->send($this->envelopeWithOneSigner());
            self::fail('Expected ProviderAuthenticationException.');
        } catch (\LauLamanApps\DocumentSigner\Sdk\Exception\ProviderAuthenticationException $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame('AUTHORIZATION_INVALID_TOKEN', $e->providerCode);
            self::assertFalse($e->isRetryable());
        }
    }

    #[Test]
    public function get_status_maps_provider_status_strings(): void
    {
        [$provider] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['status' => 'completed'])),
            new Response(200, [], json_encode(['status' => 'voided'])),
            new Response(200, [], json_encode(['status' => 'declined'])),
            new Response(200, [], json_encode(['status' => 'mystery'])),
        ]);

        self::assertSame(EnvelopeStatus::Completed, $provider->getStatus('e1'));
        self::assertSame(EnvelopeStatus::Voided,    $provider->getStatus('e2'));
        self::assertSame(EnvelopeStatus::Declined,  $provider->getStatus('e3'));
        self::assertSame(EnvelopeStatus::Unknown,   $provider->getStatus('e4'));
    }

    #[Test]
    public function download_signed_returns_the_archive_zip(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], 'PK-FAKE-ZIP-BYTES'),
        ]);

        $file = $provider->downloadSigned('env-42');

        self::assertInstanceOf(\SplFileInfo::class, $file);
        self::assertSame('zip', $file->getExtension());
        self::assertSame('PK-FAKE-ZIP-BYTES', file_get_contents($file->getPathname()));

        self::assertStringContainsString(
            '/envelopes/env-42/documents/archive',
            (string) $history[1]['request']->getUri(),
        );

        @unlink($file->getPathname());
    }

    #[Test]
    public function download_audit_returns_the_audit_events_json(): void
    {
        [$provider, $history] = $this->buildProvider([
            new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
            new Response(200, [], '{"auditEvents":[{"eventFields":[{"name":"action","value":"Sent"}]}]}'),
        ]);

        $file = $provider->downloadAudit('env-42');

        self::assertSame('json', $file->getExtension());

        $payload = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('auditEvents', $payload);
        self::assertSame('Sent', $payload['auditEvents'][0]['eventFields'][0]['value']);

        self::assertStringContainsString(
            '/envelopes/env-42/audit_events',
            (string) $history[1]['request']->getUri(),
        );

        @unlink($file->getPathname());
    }

    /**
     * @param array<int, Response> $responses
     * @return array{0: DocuSignProvider, 1: \ArrayObject<int, array<string, mixed>>}
     */
    private function buildProvider(array $responses): array
    {
        $mock = new MockHandler($responses);
        $history = new \ArrayObject();
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        $config = new DocuSignConfig(
            integrationKey: 'k', userId: 'u', accountId: 'a',
            privateKey:     (string) self::$privateKey,
        );

        $auth = new DocuSignJwtAuth($config, $http);
        $client = new DocuSignClient($config, $auth, $http);

        $provider = new DocuSignProvider(
            $config,
            client: $client,
            pdfRenderer: $this->fakePdfRenderer(),
        );

        return [$provider, $history];
    }

    private function envelopeWithOneSigner(): Envelope
    {
        return new Envelope(
            name:         'NDA',
            documents:    [new Document(
                id:   'nda',
                name: 'NDA',
                html: '<p>Sign: {[signature:s1:sig]}</p>',
            )],
            signers:      [new Signer('s1', 'Jane Doe', 'jane@example.com')],
            emailSubject: 'Please sign the NDA',
            signingOrder: SigningOrder::Parallel,
        );
    }

    private function fakePdfRenderer(): PdfRenderer
    {
        return new class implements PdfRenderer {
            public function render(string $html): string
            {
                return '%PDF-FAKE' . $html;
            }
        };
    }
}
