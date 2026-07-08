# Changelog

All notable changes to `laulamanapps/document-signer-docusign` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [2.3.2] - 2026-07-08

### Fixed — anchored fields were placed too high

DocuSign seats an anchored tab above the anchor line, so fields rendered too
high relative to their `{[type:signer:name]}` placeholder (ValidSign, which
anchors the top-left, was correct). Each tab now carries a vertical
`anchorYOffset` that drops its **top edge onto the anchor**, matching ValidSign,
and every field type is sent with an explicit size mirroring ValidSign's.

Offsets were measured against the live DocuSign API: text/date/checkbox tabs
render at the size we set, so half their height centres the top on the anchor;
signature/initials render as fixed-size "Sign Here" / "Initial Here" adornments
independent of the field size, so they use measured constants.

### Added

- `DocuSignConfig::$anchorYOffsetPixels` — a document-wide vertical fine-tune (in
  pixels) added to every tab's `anchorYOffset` (positive = down). Default 0; use
  it to correct any residual, uniform drift without a code change.

## [2.3.1] - 2026-07-08

### Fixed — `downloadSignedDocument()` failed for every DocuSign envelope

2.3.0 stored the caller's `Document::$id` in each document's inline
`documents[].documentFields` at envelope creation and read it back from the
document list. DocuSign **silently drops** inline per-document fields supplied
at creation, so the field was never returned and resolution failed for every
envelope — including completed, fully-signed ones — with
`SignedDocumentUnavailableException`.

The id → positional-id map is now stored in an **envelope-level** text custom
field (`sdkDocumentMap`), which DocuSign persists and returns. `send()` writes
it; `downloadSignedDocument()` reads it via `GET envelopes/{id}/custom_fields`,
falling back to a normalized document-name match (and always skipping the
`Summary.pdf` certificate). No API or contract change — envelopes sent with this
version resolve correctly, including multi-document envelopes where
`Document::$id` differs from `Document::$name`.

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
