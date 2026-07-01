<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\DocuSign;

use LauLamanApps\DocumentSigner\Sdk\Document\Document;
use LauLamanApps\DocumentSigner\Sdk\Envelope\Envelope;
use LauLamanApps\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LauLamanApps\DocumentSigner\Sdk\Exception\ProviderException;
use LauLamanApps\DocumentSigner\Sdk\Field\FieldType;
use LauLamanApps\DocumentSigner\Sdk\Pdf\BrowsershotPdfRenderer;
use LauLamanApps\DocumentSigner\Sdk\Pdf\PageDecoration;
use LauLamanApps\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LauLamanApps\DocumentSigner\Sdk\Placeholder\PlaceholderParser;
use LauLamanApps\DocumentSigner\Sdk\Placeholder\PreparedField;
use LauLamanApps\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LauLamanApps\DocumentSigner\Sdk\Provider\FieldValue;
use LauLamanApps\DocumentSigner\Sdk\Provider\SignatureProvider;
use LauLamanApps\DocumentSigner\Sdk\Signer\Signer;
use LauLamanApps\DocumentSigner\Sdk\Signer\SigningOrder;
use LauLamanApps\DocumentSigner\Sdk\Support\TempFile;
use LauLamanApps\DocumentSigner\DocuSign\Auth\DocuSignJwtAuth;
use LauLamanApps\DocumentSigner\DocuSign\Http\DocuSignClient;
use LauLamanApps\DocumentSigner\DocuSign\Placeholder\DocuSignPlaceholderReplacer;

final class DocuSignProvider implements SignatureProvider
{
    public const string NAME = 'docusign';

    private readonly DocuSignConfig $config;
    private readonly DocuSignClient $client;
    private readonly PdfRenderer $pdfRenderer;
    private readonly DocuSignPlaceholderReplacer $replacer;
    private readonly PlaceholderParser $parser;

    public function __construct(
        DocuSignConfig $config,
        ?DocuSignClient $client = null,
        ?PdfRenderer $pdfRenderer = null,
        ?DocuSignPlaceholderReplacer $replacer = null,
        ?PlaceholderParser $parser = null,
    ) {
        $this->config      = $config;
        $this->client      = $client      ?? new DocuSignClient($config, new DocuSignJwtAuth($config));
        $this->pdfRenderer = $pdfRenderer ?? new BrowsershotPdfRenderer();
        $this->replacer    = $replacer    ?? new DocuSignPlaceholderReplacer();
        $this->parser      = $parser      ?? new PlaceholderParser();
    }

    public function send(Envelope $envelope): EnvelopeReceipt
    {
        $signerIndex = $this->indexSigners($envelope);
        $apiDocuments = [];
        $tabsBySigner = array_fill_keys(array_keys($signerIndex), $this->emptyTabBuckets());

        $docNumber = 1;
        foreach ($envelope->documents as $document) {
            $prepared = $this->replacer->replace($document->html, $this->parser->parse($document->html));
            $this->assertFieldsResolvable($envelope, $document, $prepared->fields);

            $pdf = $this->pdfRenderer->render($prepared->html, new PageDecoration(
                headerHtml: $document->headerHtml,
                footerHtml: $document->footerHtml,
                headerPlacement: $document->headerPlacement,
                footerPlacement: $document->footerPlacement,
            ));
            $documentId = (string) $docNumber++;

            $apiDocuments[] = [
                'documentBase64' => base64_encode($pdf),
                'name'           => $document->name,
                'fileExtension'  => 'pdf',
                'documentId'     => $documentId,
            ];

            foreach ($prepared->fields as $field) {
                $bucket = $this->bucketForFieldType($field->type);
                $tabsBySigner[$field->signerKey][$bucket][] = $this->buildTab($documentId, $field);
            }
        }

        $signers = [];
        foreach ($envelope->signers as $signer) {
            $signers[] = $this->buildSigner(
                $signer,
                $signerIndex[$signer->key],
                $envelope->signingOrder,
                $tabsBySigner[$signer->key],
            );
        }

        $payload = [
            'emailSubject' => $envelope->emailSubject,
            'emailBlurb'   => $envelope->emailMessage ?? '',
            'status'       => 'sent',
            'documents'    => $apiDocuments,
            'recipients'   => ['signers' => $signers],
        ];

        if ($envelope->expiresAt !== null) {
            $payload['notification'] = [
                'expirations' => [
                    'expireEnabled' => 'true',
                    'expireAfter'   => max(1, (int) ceil(
                        ($envelope->expiresAt->getTimestamp() - time()) / 86400
                    )),
                ],
            ];
        }

        if ($envelope->metadata !== []) {
            $payload['customFields'] = ['textCustomFields' => $this->buildCustomFields($envelope->metadata)];
        }

        $response = $this->client->createEnvelope($payload);

        $envelopeId = $response['envelopeId'] ?? null;
        if (!is_string($envelopeId) || $envelopeId === '') {
            throw new ProviderException(
                'DocuSign did not return an envelopeId in the create-envelope response.',
                providerBody: json_encode($response),
            );
        }

        try {
            return new EnvelopeReceipt(
                provider: self::NAME,
                providerEnvelopeId: $envelopeId,
                status: $this->mapStatus($response['status'] ?? 'sent'),
                signerUrls: [],
                raw: $response,
            );
        } catch (ProviderException $e) {
            throw $e->withProviderEnvelopeId($envelopeId);
        } catch (\Throwable $e) {
            throw new ProviderException(
                message: 'DocuSign envelope was created but the SDK failed to build the receipt: ' . $e->getMessage(),
                previous: $e,
                providerEnvelopeId: $envelopeId,
            );
        }
    }

    public function getStatus(string $providerEnvelopeId): EnvelopeStatus
    {
        $response = $this->client->getEnvelope($providerEnvelopeId);
        return $this->mapStatus($response['status'] ?? null);
    }

    public function downloadSigned(string $providerEnvelopeId): \SplFileInfo
    {
        return TempFile::fromBytes(
            bytes: $this->client->downloadSignedArchive($providerEnvelopeId),
            prefix: 'docusign-signed-',
            extension: 'zip',
        );
    }

    public function downloadAudit(string $providerEnvelopeId): \SplFileInfo
    {
        return TempFile::fromBytes(
            bytes: $this->client->downloadAuditEventsJson($providerEnvelopeId),
            prefix: 'docusign-audit-',
            extension: 'json',
        );
    }

    public function getFieldValues(string $providerEnvelopeId): array
    {
        $response = $this->client->getRecipientsWithTabs($providerEnvelopeId);

        $out = [];
        foreach (($response['signers'] ?? []) as $signer) {
            if (!is_array($signer)) {
                continue;
            }
            $signerId = is_string($signer['recipientId'] ?? null) ? $signer['recipientId'] : '';
            $tabs     = is_array($signer['tabs'] ?? null) ? $signer['tabs'] : [];

            // DocuSign exposes filled values on textTabs, checkboxTabs, dateSignedTabs,
            // fullNameTabs, emailTabs, etc. — we surface every tab with a `value` or
            // `selected` string, so callers can pull SEPA IBANs, opt-in checkboxes,
            // signed dates, etc.
            foreach (['textTabs', 'checkboxTabs', 'dateSignedTabs', 'listTabs', 'radioGroupTabs',
                      'ssnTabs', 'zipTabs', 'phoneTabs', 'emailTabs', 'firstNameTabs', 'lastNameTabs',
                      'fullNameTabs', 'titleTabs', 'companyTabs'] as $bucket) {
                foreach (($tabs[$bucket] ?? []) as $tab) {
                    if (!is_array($tab)) {
                        continue;
                    }
                    $value = $tab['value'] ?? ($tab['selected'] ?? null);
                    $out[] = new FieldValue(
                        documentId: is_string($tab['documentId'] ?? null) ? $tab['documentId'] : '',
                        signerKey:  $signerId,
                        fieldName:  is_string($tab['tabLabel'] ?? null) ? $tab['tabLabel'] : '',
                        value:      is_string($value) && $value !== '' ? $value : null,
                    );
                }
            }
        }

        return $out;
    }

    public function cancel(string $providerEnvelopeId, ?string $reason = null): void
    {
        $this->client->voidEnvelope($providerEnvelopeId, $reason);
    }

    private function mapStatus(mixed $status): EnvelopeStatus
    {
        $normalised = is_string($status) ? strtolower($status) : '';
        return match ($normalised) {
            'created'            => EnvelopeStatus::Draft,
            'sent'               => EnvelopeStatus::Sent,
            'delivered'          => EnvelopeStatus::Delivered,
            'completed', 'signed' => EnvelopeStatus::Completed,
            'declined'           => EnvelopeStatus::Declined,
            'voided'             => EnvelopeStatus::Voided,
            default              => EnvelopeStatus::Unknown,
        };
    }

    /**
     * @return array<string, int> Map of signer key → 1-based recipientId.
     */
    private function indexSigners(Envelope $envelope): array
    {
        $i = 1;
        $map = [];
        foreach ($envelope->signers as $signer) {
            $map[$signer->key] = $i++;
        }
        return $map;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $tabs
     * @return array<string, mixed>
     */
    private function buildSigner(Signer $signer, int $recipientId, SigningOrder $order, array $tabs): array
    {
        $routingOrder = $order === SigningOrder::Sequential ? (string) $signer->order : '1';

        return [
            'email'        => $signer->email,
            'name'         => $signer->name,
            'recipientId'  => (string) $recipientId,
            'routingOrder' => $routingOrder,
            'tabs'         => array_filter($tabs, static fn (array $bucket) => $bucket !== []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTab(string $documentId, PreparedField $field): array
    {
        $tab = [
            'documentId'               => $documentId,
            'pageNumber'               => '1',
            'tabLabel'                 => $field->fieldName,
            'anchorString'             => $field->anchorString,
            'anchorXOffset'            => '0',
            'anchorYOffset'            => '0',
            'anchorUnits'              => 'pixels',
            'anchorIgnoreIfNotPresent' => 'false',
            'anchorCaseSensitive'      => 'true',
        ];

        // Signature / initials / dateSigned tabs are always required in DocuSign
        // and don't accept the `required` attribute. Text and Checkbox tabs do.
        if ($field->type === FieldType::Text) {
            $tab['required'] = $field->required ? 'true' : 'false';
            $tab['width']    = 180;
            $tab['height']   = 18;
        } elseif ($field->type === FieldType::Checkbox) {
            $tab['required'] = $field->required ? 'true' : 'false';
        }

        return $tab;
    }

    private function bucketForFieldType(FieldType $type): string
    {
        return match ($type) {
            FieldType::Signature => 'signHereTabs',
            FieldType::Initials  => 'initialHereTabs',
            FieldType::Text      => 'textTabs',
            FieldType::Date      => 'dateSignedTabs',
            FieldType::Checkbox  => 'checkboxTabs',
        };
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function emptyTabBuckets(): array
    {
        return [
            'signHereTabs'    => [],
            'initialHereTabs' => [],
            'textTabs'        => [],
            'dateSignedTabs'  => [],
            'checkboxTabs'    => [],
        ];
    }

    /**
     * @param array<string, scalar|null> $metadata
     * @return list<array<string, string>>
     */
    private function buildCustomFields(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $name => $value) {
            $out[] = [
                'name'     => (string) $name,
                'value'    => $value === null ? '' : (string) $value,
                'required' => 'false',
                'show'     => 'false',
            ];
        }
        return $out;
    }

    /**
     * @param PreparedField[] $fields
     */
    private function assertFieldsResolvable(Envelope $envelope, Document $document, array $fields): void
    {
        foreach ($fields as $field) {
            if (!$envelope->signerByKey($field->signerKey) instanceof Signer) {
                throw new ProviderException(sprintf(
                    "Document '%s' references unknown signer key '%s' in field '%s'.",
                    $document->id,
                    $field->signerKey,
                    $field->fieldName,
                ));
            }
        }
    }
}
