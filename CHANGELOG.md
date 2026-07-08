# Changelog

All notable changes to `laulamanapps/document-signer-docusign` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [2.3.0] - 2026-07-08

### Changed — `downloadSignedDocument()` now takes your own document id

`downloadSignedDocument(string $providerEnvelopeId, string $documentId)` now
accepts the **caller's `Document::$id`** (the id you set when building the
envelope), consistently with every other provider — instead of DocuSign's
internal positional id (`"1"`, `"2"`).

Internally it resolves that id to DocuSign's positional id by listing the
envelope's documents and matching:

1. the `sdkDocumentId` per-document custom field written on `send()` (primary), then
2. the normalized document name (case-folded, `[\s_]+` collapsed) as a fallback
   for envelopes sent before this release.

The certificate-of-completion (`Summary.pdf`) is skipped automatically, and
DocuSign's space-to-underscore filename mangling no longer matters. Consumers
can drop any "download the whole ZIP and match the filename" workaround.

### Added

- `send()` writes each `Document::$id` into the DocuSign document's
  `documentFields` (as `sdkDocumentId`) so it can be resolved back on download.
- `downloadSignedDocument()` throws the new, **retryable**
  `SignedDocumentUnavailableException` (from the SDK) when no document matches
  the id yet — typically because the envelope isn't finalized. Back off and retry.

### Changed — `downloadAudit()` now returns the Certificate of Completion PDF

`downloadAudit()` now serves the **Certificate of Completion** (`.pdf`) — the
human-readable evidence report, and the analog of ValidSign's Evidence Summary —
instead of the raw `audit_events` JSON feed. This makes `downloadAudit()`
consistent across providers. The JSON events are still available directly via
`DocuSignClient::downloadAuditEventsJson()`.

### Changed

- Minimum PHP lowered to **8.3** (was 8.5); CI now tests 8.3–8.5.

### Upgrade notes

- If you were passing DocuSign's positional ids (`"1"`, `"2"`) to
  `downloadSignedDocument()`, pass your own `Document::$id` instead.
- If you relied on `downloadAudit()` returning `.json` audit events, switch to
  `DocuSignClient::downloadAuditEventsJson()`; `downloadAudit()` now returns a
  `.pdf` certificate.
- Envelopes **sent before this release** have no `sdkDocumentId` field; they
  resolve by (normalized) document name. Pass the `Document::$name` for those,
  or re-send under the new version to get exact-id resolution.
- Requires `laulamanapps/document-signer-sdk` ≥ 2.3.0 (for
  `SignedDocumentUnavailableException`).
