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
