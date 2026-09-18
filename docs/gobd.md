# GoBD readiness

Reviewed: 5 September 2026, [commit 99b2218](https://github.com/fliix-cloud/filament-fints-accounting/tree/99b221895511d8c42c1228b36bd3e9f75c0d3e39).

## Verdict

The current package is **not ready for an unqualified “GoBD-konform” claim**.
Useful controls exist, but evidence preservation, accounting correctness,
authorization, and audit access still have release-blocking gaps.

A defensible, scoped claim is achievable. It requires a tested release **and**
an evidenced operating procedure. Installing an open-source package cannot
guarantee the compliance of every host application or deployment.

This is a repository-wide, risk-based technical review of the ledger, documents,
tax, banking, reconciliation, authorization, storage, audit, exports, migrations,
UI integration, tests, and CI. It is not an exhaustive security audit, legal
opinion, or certification. Production infrastructure and business procedures
were not inspected. The baseline findings describe source-level observations
from 5 September: PHP and Composer were unavailable in that review environment.
Subsequent implementation checks were executed locally; their results and the
latest project-state check are recorded below.

## Legal basis and claim boundary

The reviewed basis is [AO § 146][ao146], [AO § 147][ao147], the
[GoBD text through March 2024][gobd], and the [July 2025 amendment][amendment].
The linked AO handbook does not incorporate that amendment; read them together.

- GoBD Rz. 21 and 23: responsibility and assessment include the taxpayer's
  actual system and procedures, not software alone.
- Rz. 100–111: controls must address access, loss, changes, and historical meaning.
- Rz. 150–154: procedures and their changes need understandable documentation.
- Rz. 179–181: the tax authority does not issue a generally binding software
  approval; third-party test reports do not bind it either.

**Current public wording:** “Provides double-entry bookkeeping, audit-chain
verification, and document-integrity controls. GoBD readiness is under review.”

**Target wording, only after the release gates below pass:**
“Version X provides the technical requirements for GoBD-compliant processing
within the documented scope when deployed and operated according to the
specified requirements.” Publish the scope and evidence alongside the claim;
obtain accounting and legal review of any stronger German marketing wording.

Do not equate GoBD readiness with complete VAT correctness, XRechnung
conformance, DATEV compatibility, or statutory financial statements.

## Implementation progress

Updated: 11 September 2026, implementation at `6287428`. The table below tracks changes after the reviewed
baseline; the detailed findings retain that baseline as their reference.
**No finding is fully closed and the compliance verdict is unchanged.**

Historical project-state check after the reported interruption, 10 September 2026: the working
tree was clean at `d4ce364`; the durable intake, outgoing artifact recovery, and
scheduled verification changes are committed. The current verification code and
operations documentation agree on report schema 2 and separate integrity/pending
results. Default audit export remains schema 1 with events and anchors only. The full local
quality gate was rerun on Herd PHP 8.4.25: **241 tests, 2,265 assertions**, PHPStan,
Pint, and strict Composer validation passed. Local links in this document and
operations documentation resolve. Graph access is restored, but coverage metadata
reported changed file metadata, so material checks used current source as well.
This was a reconciliation of the latest implementation and documentation, not a
new legal review or a production database/storage recovery test. Only documentation
clarifications were needed; the next step at that time was the linked export,
which is now implemented within the scope described below.

### Current project-state check — 11 September 2026

The working tree was clean at `6287428` when this check began. The comparison
covers changes since the streaming export (`2c8ca43`): package demo seeding,
catalog transfer (`e6050e2`), invoice editing/versioning/payment snapshots
(`5188211`), and the invoice template refactor. This is a technical delta review,
not a renewed legal assessment or a production deployment test. Historical test
counts below describe their respective slices, not the current suite.

The largest progress is a controlled sales-invoice correction workflow. The
largest newly identified integration gap is that the fixed accounting export
schema has not followed the new business fields. Existing export verification
checks the declared schema; a green result does not establish that it includes
every field added later. The priorities below therefore put schema/evidence
integration before further UI work.

Development scope confirmed on 11 September 2026: there are no deployed
installations to support. The package and disposable demo databases are still in
development. Catch-up migrations for earlier development schemas are not a current
deliverable. Fresh installation must work; a supported upgrade policy becomes a
release requirement when a persistent installation baseline is established.

Validation on Herd PHP 8.4.25: **387 tests, 3,222 assertions** passed; PHPStan,
Pint, and strict Composer validation passed. Graph generation was
`2026-09-10T16:18:58Z`; coverage reported changed metadata. Material conclusions
therefore use current source/diffs and test results, not graph completeness.
No production database, live bank connection, or host installation was changed.

| Findings | Implemented in this change | Still required |
| --- | --- | --- |
| F11 / F9 | New `StoreAttachment` writes retain files after failure and verify retries. Outgoing invoices commit a fixed PDF/XML set, render snapshot, paths, hashes, and preparation evidence before file writes. Retries recover missing attachment references and verify contents before posting. Issuance uses the accounting connection; Filament offers “Complete invoice”. Streaming transfer and offline verification include retained artifact data. | General orphan recovery, production concurrency/storage/restore evidence, third-party import validation, and a supported installation/release baseline. |
| F1 / F3 / F7 / F9 / F11 | A committed intake manifest and verified private raw files precede parsing. PDF, standalone XML, and PDF/XML pairs are supported. Identity includes roles and contents. Attempts are audited; retry reuses preserved inputs. Business rollback retains intake evidence. Purchase registration uses the accounting connection. Source-total mismatches block conversion. Filament exposes open imports, safe downloads, and retry within purchase invoices. | Production concurrency and crash tests, complete conformance/accounting conversion checks, derived-file orphan recovery, complete converted-line evidence, and third-party import/restore validation. |
| F1 / F3 | Purchase draft disposal retains the document, lines, PDF/XML, and actor/reason evidence. It requires a dedicated permission, current company scope, and a locked persisted draft. UI offers “Discard draft”; physical deletion is disabled. Invalid accepted imports are now retained independently of drafts. | Complete operational review/correction of blocked intakes and production retention evidence. |
| F2 / F4 | Original attachment metadata and original-file model deletion are guarded. Documents reject final-state downgrades and identity changes; lines reject reparenting and consult stored parent state. Stale journal models cannot edit posted data. Sales corrections now retain prior versions and files and reverse/replace postings. | Bulk/SQL write prevention, concurrent mutation evidence and remaining correction workflows; sequential correction after reversed payments is now tested. |
| F2 / F8 / F10 | Each ledger posting includes a versioned full journal snapshot and SHA-256 digest. Verification detects changed/missing journal data; CSV/UI use historical account values. Linked streaming export includes records, retained originals, relationships, audit events, and anchors, with isolated inspection tests. Invoice artifacts bind render snapshots and payment/correction details. | Complete finalized settlement and other business evidence; protect storage/database privileges; prove third-party import/restore and production snapshot consistency. This is tamper detection, not prevention of privileged SQL writes. |
| F3 | Undefined Gates now deny access; the provider no longer creates permissive fallback Gates. Tests explicitly configure fixture permissions; hosts must configure their own Gates. | Complete the authorization audit of all public mutation paths and integrations. |
| F5 | Closing cannot weaken a hard lock. Reopening requires a separate permission and non-blank reason. Both record before/after state, use the accounting connection, and lock entity before period. Repeated close is idempotent. | Production database concurrency tests and protection against direct period-model/SQL changes. |
| F6 / F9 | Posting reloads persisted state and accepts issued/received invoices and credit notes. Foreign currency is rejected until conversion exists. Line discounts apply before tax. Non-recoverable purchase tax stays on the expense account. Sequence uses the posted-on year. Ledger posting/reversal and changed document/period services use the accounting connection. | Remaining tax edge cases; connection consistency in the remaining services and cross-connection rollback tests. |

Regression coverage: [document protection](../tests/Documents/RecordProtectionTest.php),
[ledger/period protection](../tests/Ledger/RecordProtectionTest.php),
[authorization](../tests/Authorization/DefaultAccountingAuthorizerTest.php),
the [Filament discard workflow](../tests/Filament/InvoiceLayoutTest.php), and
[journal evidence/export tests](../tests/Audit/JournalIntegrityTest.php).
[CI for implementation commit d8aa32c](https://github.com/fliix-cloud/filament-fints-accounting/actions/runs/33958922570)
passed all 184 tests on PHP 8.3/8.4/8.5, PHPStan, Pint, and Composer validation
(1,843 assertions on PHP 8.3). This includes balanced SQL changes, missing/duplicate
evidence, snapshot validation, rollback, stable historical CSV/UI values, and a
coordinated local-hash rewrite detected by an external anchor.
The attachment-storage continuation was tested locally with Herd PHP 8.4;
regressions cover separate objects for identical content, failed metadata saves,
failed storage verification, and missing/corrupt retry evidence. The full suite
passed 204 tests with 1,916 assertions; all seven attachment tests also passed
after shortening the storage paths. PHPStan reported no errors. These tests do not establish
production concurrency or storage immutability. F7–F12 are not fully resolved;
see [operations](operations.md).

The caller-failure continuation passed the full local suite on Herd PHP 8.4.25:
211 tests, 1,995 assertions. New [import regressions](../tests/Documents/PurchaseInvoiceUploadTest.php)
and [artifact regressions](../tests/Documents/InvoiceArtifactTest.php) cover
injected metadata failures after object writes, retained bytes/rows and original
exceptions, incomplete and repeated retries, missing/corrupt PDF and XML,
changed companion XML, denied import access, and renderer upgrades. After the
final type-guard correction, all 14 affected tests passed again (128 assertions);
PHPStan and the full Pint check passed as well.

**Development schema:** there are no installed/production databases to migrate.
The base migration now includes journal `period_snapshot` and line
`account_snapshot` JSON columns. Drafts may omit them; posted entries without
complete snapshots and exactly one posting event fail verification. Rebuild
disposable DEV databases; no backfill or legacy-evidence acceptance is supplied.

## Current continuation: durable intake with a simple Filament workflow

Targeted continuation: 9 September 2026. This is not a new repository-wide
review. Graph discovery and coverage checks failed with `Transport closed`;
the affected source files were read directly.

### Implemented: preserve inputs before processing (F1 / F3 / F7 / F9 / F11)

[PurchaseInvoiceIntakeStore](../src/Services/PurchaseInvoiceIntakeStore.php)
authorizes the company and actor, checks permitted extensions and size, and
commits a [company-scoped intake](../src/Models/PurchaseInvoiceIntake.php) before
writing files. Its manifest records original filenames, roles, expected paths,
sizes, and SHA-256 hashes. Each verified file is marked preserved in a separate
commit. Input bytes are stored privately as inert `.bin` objects, including
malformed XML and XML with prohibited document type declarations. XML entities
are never resolved by the import entry point. Unsupported file types, oversized
uploads, and unauthorized requests are rejected before acceptance.

[ImportPurchaseInvoice](../src/Services/ImportPurchaseInvoice.php) accepts PDF,
standalone XML, and PDF with companion XML. Identity includes all supplied
content hashes and roles, scoped by company. An entity lock and a database unique
constraint serialize creation of the intake; processing locks entity then intake.
These mechanisms still require concurrent-request proof on the chosen production
database. Sequential retries have been tested; they do not establish that proof.

All raw inputs must be preserved and verified before parsing and business writes.
Supplier, draft, attachment references, completion link, and completion audit event
use the accounting connection's transaction. If this transaction fails, its new
business records roll back while the committed intake and originals survive.
This supersedes the earlier behavior of keeping a partially created import draft.
PDF/XML document attachments reference the retained intake objects. Extracted XML
from a hybrid PDF still uses `StoreAttachment`; the retained PDF contains the
original structured bytes, but a failed derived-file metadata save can still
leave a separate object without an attachment row.

Retries verify the manifest and existing bytes. A file found at its planned path
after an interrupted metadata commit is verified and reused. A missing file that
was already marked preserved, or any changed bytes, blocks processing; supplied
upload bytes never silently repair that evidence. An interrupted initial write
can be completed by uploading the same inputs again. `resume($intake)` processes
preserved inputs without another upload and reuses an already linked, verified
document. Attempts have correlated start/completion/failure audit events. If the
failure event cannot be committed, the start event and prior intake state remain;
the recording error is reported without masking the processing exception.

Intake rejects an enclosing accounting transaction because a later host rollback
would erase the claimed preservation record. Filament's upload page and retry
action disable their outer transaction. Integrations must invoke intake outside
a host accounting transaction. Connection isolation and rollback were tested with
two SQLite connections; this does not prove production locking behavior.

Parsing success is recorded separately from conformance (`extracted` versus
`validation_status: not_checked`). Source net, tax, and gross totals must equal
the calculated draft totals; mismatches roll back business conversion and leave
the original available for review. Full schema/business-rule validation and all
allowance, charge, rounding, and tax cases remain open.

### Filament behavior

The normal flow remains **upload → review → book**. The existing main upload
field accepts PDF or XML; companion XML remains optional. No parser, archive,
hash, or recovery-policy settings are exposed.

**Open imports** is a page within purchase invoices, with filename, receipt time,
and a short status. It includes intakes that have no draft. Verified raw originals
can be downloaded as attachments with an inert content type; they are not rendered
inline. Error details are hidden by default. Preserved, interrupted processing
can be retried and leads directly to the existing invoice review. Invalid input
and integrity failures show a review state; integrity failures have no retry
button. Incomplete preservation instructs the user to upload the same files again.
Authorization and company isolation are checked in services as well as the page.

No additional accounting form fields, compliance dashboard, or approval wizard
were added. Invalid inputs are retained rather than presented as editable invoices.
A corrected source is a distinct intake; replacing originals or silently clearing
blocked intakes is not provided.

### Evidence and remaining boundaries

[PurchaseInvoiceUploadTest](../tests/Documents/PurchaseInvoiceUploadTest.php)
now uses real commits rather than a surrounding test transaction. It covers
standalone XML, malformed/unsafe XML preservation, source-total mismatch, changed
companion XML, storage failure, repeated metadata failure, interrupted-write
recovery, missing/corrupt originals, forbidden outer transactions, a separate
accounting connection, manifest guards, denied access, and the Filament upload,
review, download, and retry flows. The full local suite passed on Herd PHP 8.4.25:
**220 tests, 2,097 assertions**.
After adding coverage for standalone CII, conflicting hybrid/companion XML, and
preservation-marker protection, the 19 import/UI tests passed again with 178
assertions. PHPStan and the full Pint check passed.

The base DEV migration adds `accounting_purchase_invoice_intakes`. Rebuild only
disposable DEV databases. Old imports are not automatically attached to fabricated
intake history. No production migration or backfill is supplied.

General `StoreAttachment` writes still need broader orphan recovery. The outgoing
invoice continuation below now provides a fixed artifact set and recovery of
partial file writes and missing attachment references.

Intake manifest and preservation events join the existing audit chain. The
scheduled verification slice below now checks manifests, originals, and completion
links alongside chain and configured anchor verification. Complete converted-line
evidence and independent offline export verification remain open. Model guards and
row locks do not prevent raw SQL or privileged storage changes. Production
concurrency, create-only/immutable storage controls, restoration, monitoring, and
operator response still need evidence.

### Implemented: recoverable outgoing invoice sets (F2 / F3 / F9 / F11)

[GenerateInvoiceArtifacts](../src/Services/GenerateInvoiceArtifacts.php) now
commits an [InvoiceArtifactSet](../src/Models/InvoiceArtifactSet.php) before its
first filesystem write. The set holds the exact XML and base64-encoded PDF,
the rendered document snapshot, renderer/template metadata, planned object paths,
filenames, sizes, and hashes. Its evidence digest is recorded in a preparation
audit event. The staged bytes are retained as evidence, not deleted after upload.

Each file write, read-back verification, attachment reference, and preservation
marker is processed under accounting-connection locks. A retry uses the committed
bytes even after renderer or configuration changes. Objects found at their planned
paths after an interrupted metadata commit are verified and reused; missing
attachment rows can be reconstructed from the set. Missing files already marked
preserved, changed bytes, duplicate references, changed render-source values, and
altered local preparation evidence block continuation. Generated attachments and
the set reject model deletion; preservation markers cannot be reset through models.
If the set disappears but its preparation event remains, regeneration is refused
even when every attachment row is also absent.

Verification compares staged bytes, manifest, current render-source snapshot,
attachment metadata/files, and the preparation event's payload, canonical payload,
and hash. These are local checks. The scheduled verification command now checks
these sets alongside the full audit chain and configured independent anchors.
The default audit export contains events and anchors; the dataset option described
below additionally transfers linked accounting records and retained files.

[IssueSalesInvoice](../src/Services/IssueSalesInvoice.php) freezes the artifact
requirement when issuing. Changing the current generation setting cannot skip
an already-required artifact step. Issuance and its sequence/audit updates use the
accounting connection; generation requires an independent commit and rejects an
enclosing accounting transaction. [PostDocument](../src/Services/PostDocument.php)
verifies required or existing sets before posting. A retry keeps the invoice number
and uses existing posting idempotency to avoid a second journal.

Filament exposes **Complete invoice** only for issued invoices with pending
completion and the required permissions. The action is available in the sales
list and invoice view and runs without an outer transaction. It reports failure
without deleting evidence; successful completion leads to the existing invoice
view. No additional accounting fields or artifact-version choices are required.

[InvoiceArtifactTest](../tests/Documents/InvoiceArtifactTest.php) covers repeated
metadata failure, PDF storage failure, renderer upgrades, lost attachment rows,
lost authoritative sets, stale models after source tampering, staged-byte and
preparation-event tampering, model deletion guards, denied access, outer-transaction
rejection, separate accounting-connection recovery, and Filament completion with
exactly one invoice number and journal. Local tests use real commits and fake
storage; production concurrency and crash/restore behavior remain release gates.
The subsequent verification slice and its regression results are recorded below.

The base DEV migration adds `accounting_invoice_artifact_sets`. Include this table
and its staged contents in retention, backup, restore, and future audit exports.
Existing generated attachments without a set are not silently adopted. Rebuild
only disposable DEV databases; no production migration or evidence backfill is
provided. First rendering can be retried if it failed before committing a set,
because no artifact file has yet been written by this workflow.

### Scheduled invoice evidence verification

`filament-accounting:verify` now runs
[InvoiceEvidenceVerifier](../src/Audit/InvoiceEvidenceVerifier.php) under the same
entity lock as journal, chain, and anchor verification. It checks intake manifests
against creation events, preservation markers against file events, stored sizes
and hashes, completion links, and original attachment references. It also checks
outgoing staged bytes, render snapshots, generated attachments, and preservation
evidence, including interrupted sets. Reverse checks from audit events and invoice
links detect missing or reassigned evidence records. Existing files at planned but
not yet committed paths are checked as well; verification never repairs them.

The command's JSON report is now **schema version 2**. Each entity has an
`invoice_evidence` section with counts, `issues`, and `pending` lists. Integrity
issues cause a non-zero exit code. An uncompleted intake (including preserved but
unsupported XML) or interrupted unposted issuance is reported separately as
pending, without asserting corruption. A posted invoice with required but missing
or incomplete artifacts is an integrity failure. Pending work also appears as
warnings in text output; operators must monitor it separately from exit codes.
No Filament fields or additional user steps were added.

[InvoiceEvidenceTest](../tests/Audit/InvoiceEvidenceTest.php) and the invoice tests
cover deleted/changed files, rewritten manifests, reset preservation state,
deleted/reassigned intakes, changed render inputs/staged PDF data, missing sets,
completed import links, rejected XML, and interrupted issuance. Verification works
without a web actor and on the separate accounting connection. The graph tools
again returned `Transport closed`; the affected implementation was checked through
direct source inspection. These tests do not prove production locking, storage
immutability, or resistance to coordinated rewriting without independent anchors.

The full local suite passed on Herd PHP 8.4.25 with **241 tests and 2,265
assertions**. PHPStan, Pint, and strict Composer validation passed.

This extends invoice-original and outgoing-render evidence checks, not a complete
snapshot of every business relation. Intake verification does not yet bind every
converted invoice line to the incoming structured data. The linked export below
adds offline package verification within its explicit scope. General attachment-
orphan inventory and independent import/restore evidence remain open. The command reads retained files; schedule and measure it against
the deployment's actual dataset and storage performance.

### Linked accounting dataset export (F10)

The `--dataset` option on `filament-accounting:audit-export` now transfers a
company-scoped package with 36 explicitly selected tables, retained file contents,
column and relationship descriptions, audit events, and anchors. See
[AccountingDatasetSchema](../src/Export/AccountingDatasetSchema.php),
[AccountingDatasetExporter](../src/Export/AccountingDatasetExporter.php), and
[operations](operations.md) for the exact scope and command examples. Child tables
are scoped through their parent. Originals shared by intake and attachment
references occur once per storage path; unpreserved missing inputs are explicitly
marked absent. Preserved missing or changed files block export. Outgoing staged
PDF/XML data and pending processing records are retained in the transfer.

The existing integrity checks run before a dataset digest is recorded in the
`accounting_export.prepared` audit event. File delivery happens after that event's
commit; `prepared` is not proof of delivery. Optional `--anchor` anchors committed
evidence before delivery. Without it, an included earlier anchor need not cover
the new export event, which the report exposes as `export_event_anchored: false`.
Independently trusted hashes/anchors are still needed to detect replacement of
the complete package and its local evidence.

[AccountingDatasetVerifier](../src/Export/AccountingDatasetVerifier.php) checks the
package without database or source-storage access. It checks the fixed schema,
company ownership, record inventory/IDs, declared references, file inventory and
bytes, dataset commitment, audit chain, and included anchors. Changing data and
recalculating only the package hash does not satisfy the recorded commitment.
The default audit-only schema and its tests remain supported. This initial JSON
package is identified by `format: filament-accounting-dataset`, schema version 1;
it remains available with `--dataset --legacy-json`. Streaming version 2 below
is now the default for `--dataset`.

[AccountingDatasetTest](../tests/Audit/AccountingDatasetTest.php) exercises the
invoice → journal/open item → settlement/reconciliation → bank path, outgoing and
incoming originals, pending intakes, tenant separation, omitted connection secrets,
offline verification without queries, altered/removed content, fully rehashed local
forgery against an anchor, separate accounting connections, invalid destinations,
and overwrite refusal. Filament receives no additional fields or mandatory steps.
The full local suite passed on Herd PHP 8.4.25 with **249 tests and 2,325
assertions**; PHPStan, Pint, and strict Composer validation passed.
Graph access failed again during this slice; changed/unavailable coverage was
supplemented with direct source and migration inspection.

This transfers the current supported dataset and binds it at export time. It does
not retroactively provide missing finalized settlement or purchase-line history.
Host records, TAN sessions, connection credentials/state, institute-directory data,
unreferenced storage objects, and logo/template assets are outside this package.
Unsupported attachment-owner types fail explicitly. There is no web export action
yet: the builder is a trusted operator service, and a future UI must add explicit
authorization and company scope. Production snapshot consistency under concurrent
writes, independent third-party import, and full restore evidence remain open.
F10 and the release gates are not closed.

### Streaming transfer and isolated inspection (F10)

[StreamDatasetExporter](../src/Export/StreamDatasetExporter.php) now makes
`--dataset` a version 2 streaming transfer with lazy record/event reads and 64 KiB
file chunks. Journal preflight checks entries incrementally; pending invoice work
is emitted without accumulating the complete pending list. A dataset digest binds
the exact body bytes to the committed export event. Delivery is verified by
reading back the package and comparing its full transport digest. No Filament
fields or additional mandatory user steps were introduced.

[StreamDatasetVerifier](../src/Export/StreamDatasetVerifier.php) checks the framed
stream using a disposable SQLite index rather than loading the complete package.
It validates schema, ownership, references, file bytes, audit chain, anchors, and
footer counts, and rejects truncation or trailing input. A requested covering
anchor cannot simply be removed. Legacy JSON and audit-only packages remain
supported. See [operations](operations.md) for format, commands, temporary storage,
and the required `pdo_sqlite` extension.

[StreamDatasetTest](../tests/Audit/StreamDatasetTest.php) exercises an isolated
inspection database after removal of source originals and audit events. It
reconstructs original file bytes, independently queries equal debit/credit totals,
and joins invoice, open item, settlement, reconciliation, and bank transaction
without host database queries. Further cases cover interrupted issuance and
blocked intakes, corrupt journals, damaged packages, anchor removal, and command
delivery/readback. The load fixture exports more than 48 MiB of originals into a
package exceeding 64 MiB with less than 24 MiB additional PHP memory.

This is an inspection import using the package's own verifier, not independent
third-party interoperability or a full production restore. Frames are bounded at
64 MiB, but the largest single row/invoice and anchor list still affect memory.
Temporary disk requirements and production-scale performance need deployment
measurements. Preparation and evidence capture use separate entity-locked
transactions; concurrent production snapshot behavior remains unproven.

The full local suite passed on Herd PHP 8.4.25 with **255 tests and 2,363
assertions**; PHPStan, Pint, strict Composer validation, and documentation link
checks passed. Graph transport was unavailable during this slice,
so relevant implementation and test evidence was checked directly in source.

### Invoice versions, payments, catalog, and demo updates (F2–F4 / F8–F11)

[IssueSalesInvoice](../src/Services/IssueSalesInvoice.php) now creates a separate
correction draft with the same number, the next version, a predecessor reference,
and a nonempty reason. The correction event records before/after data. Issuance
retains the preceding document and files; [PostDocument](../src/Services/PostDocument.php)
reverses the old journal, marks the old open item reversed, and posts the new
version within the accounting transaction. [Correction tests](../tests/Documents/SalesInvoiceCorrectionTest.php)
cover preserved originals, successive versions, unchanged numbering sequence,
repeat issuance, reason/permission checks, and a payment added before issuance.
The test named for parallel replacements exercises sequential duplicate requests;
it is not a production concurrency test.

The [version migration](../database/migrations/2026_09_10_000002_add_invoice_versions.php)
backfills version 1 and changes invoice-number uniqueness. Its
[migration tests](../tests/database/InvoiceVersionsMigrationTest.php) exercise
existing records and refusal to roll back once later versions exist. Intake and
artifact-set creation lives in the base migration. Given the confirmed absence
of deployed installations, no catch-up migration for older development databases
is required. Resetting disposable development fixtures is distinct from the
future requirement to preserve accounting records in persistent installations.

[ResolveInvoicePayment](../src/Services/ResolveInvoicePayment.php) validates a
direct-debit mandate against company, customer, active status, and signing date.
Payment details are frozen at issuance and included in the artifact snapshot.
The [payment/layout tests](../tests/Documents/InvoicePaymentLayoutTest.php)
exercise mandate rejection, XML payment information, escaped rendering, and logo
preservation after source removal. Selecting direct debit does not itself submit
a bank collection. Blade/Dompdf rendering, draft previews, defaults, and version
history improve everyday Filament use; they do not independently close a GoBD
finding. See [invoice workflow](sales-invoice-editing.md).

[CatalogImporter](../src/Catalog/ImportExport/CatalogImporter.php) and
[CatalogExporter](../src/Catalog/ImportExport/CatalogExporter.php) provide scoped,
authorized catalog transfer. This is a master-data convenience, not the accounting
inspection export. The importer still uses default `DB::transaction` while
entity/catalog models can use the configured accounting connection. Its transfer
method does not record before/after catalog audit events. Connection consistency
and the historical meaning of changed master data therefore remain F8/F9 work.
The package demo seeders reduce host/package drift; demo fixtures are not
evidence of a production upgrade or recovery procedure.

### Export schema integration — 11 September 2026 (F10)

The export-schema gap identified above is now addressed for the current model.
Both JSON and streaming exporters include invoice versions, payment method,
mandate ID and payment snapshot, line SKU, catalog EAN/purchase price, and company
subtitle/contact. The document-to-mandate relationship is checked with the other
declared references. Exported metadata declares `schema_revision: 2`, covered by
the dataset commitment; container versions remain unchanged. Revision 1 packages
remain readable using their original field and relationship definitions. Their
missing newer values are not reconstructed or inferred.

[StreamDatasetTest](../tests/Audit/StreamDatasetTest.php) exports an issued invoice
and two corrections with direct-debit details and catalog data. Isolated SQL
inspection checks versions, predecessor/mandate links, payment snapshots, EAN and
prices, SKU, balanced journal sums, and reconstructed original-file hashes.
A schema-drift test compares database columns with the allowlist for all fully
exported tables; bank connections remain an explicitly restricted projection.
Compatibility fixtures exercise earlier streaming and JSON schemas, and the
separate-connection fixture now applies all current migrations. This completes
the identified field-integration slice, not F10 as a whole: independent third-party
import, production snapshots, and full restore remain open. Filament is unchanged.

Validation for this continuation on Herd PHP 8.4.25: **391 tests, 3,276
assertions** passed, as did PHPStan, Pint, strict Composer validation, and local
documentation link checks.

Concrete gaps to address next:

- **Correction/payment verification (F4/F8/F9):** the earlier assertion that
  retained reversed settlements block correction was incorrect. The
  [Document settlement relation](../src/Models/Document.php) already filters
  `accounting_settlements.is_reversed = false`; the service-level `exists()` checks
  therefore consider active allocations only. No runtime change was needed.
  New [correction regressions](../tests/Documents/SalesInvoiceCorrectionTest.php)
  now exercise real bank allocation, allocation reversal, correction, and
  reallocation to the replacement while preserving both historical settlement
  records. A payment added after issuance still blocks replacement posting.
  Injected failure when creating the replacement journal rolls back the invoice
  reversal, open-item change, and correction event together; retry retains the
  prepared artifacts and produces exactly one reversal/replacement. These are
  sequential SQLite tests, not proof of concurrent production behavior.
  Validation for this test/documentation continuation: **89 tests, 487 assertions**
  passed across correction, reconciliation, and audit suites on Herd PHP 8.4.25;
  Pint passed. The earlier full-suite result remains the result of its own slice.
- **Operational proof (F9/F11):** demonstrate correction/export under concurrent
  writes, process interruption, and backup/restore on the database and storage
  selected for the first supported deployment.

### Transaction sync catch-up after long interruptions — 12 September 2026 (F12)

[TransactionSyncService](../src/Banking/FinTs/Services/TransactionSyncService.php)
persists a coverage frontier (`catch_up_from` on `AccountingBankAccount`) and
drains a truncated range **oldest-first in `max_range_days` chunks**. Each
successful chunk advances the marker to the next uncovered frontier and moves
the watermark to the chunk's end; the marker clears once the final chunk reaches
today. Repeated syncs therefore drain the full gap without holes or re-fetching
the most recent window. The [SyncCommand](../src/Banking/FinTs/Commands/SyncCommand.php)
reports the remaining gap and estimated chunk count after each truncated run.

The initial drain implementation advanced the watermark but never moved the
marker, so a resumed sync re-requested the same newest window forever.
This is corrected by oldest-first chunking, with a regression test proving a
200-day gap drains in three 90-day chunks and the marker clears
([TransactionSyncServiceTest](../tests/Banking/TransactionSyncServiceTest.php)).
The opt-in [MySqlInstallBaselineTest](../tests/Integration/MySqlInstallBaselineTest.php)
repeats the long-outage drain on MySQL: three oldest-first chunks each import a
distinct transaction once (idempotent across chunk boundaries) and the coverage
marker clears; it passed locally on MySQL 9.7.0.

This covers the sequential case: repeated sync calls eventually cover the full
range. It does not yet prove concurrent catch-up with SCA interruptions or
automatically queue subsequent chunks. F12 remains open for those production
behaviors and the remaining requirements (pending/booked transitions, bank
statement/balance reconciliation evidence, intake/posting backlog controls).
Concurrent catch-up would serialize on the same entity lock already proven for
other banking operations in the MySQL concurrency suite.

The base DEV migration adds `catch_up_from` to `accounting_bank_accounts`.
Rebuild disposable DEV databases; no production backfill is supplied.

Validation: the full local suite passed on Herd PHP 8.4.25 with **486 tests,
3,567 assertions** (27 MySQL skips). PHPStan reported zero errors; Pint passed.

### Next slices

1. **Extend concurrency and interruption evidence (F9).** The MySQL slice below
   covers duplicate/competing allocations and payment versus correction in both
   winning orders, plus booking-process termination before/after commit and retry.
   Allocation reversal versus correction is also covered in both lock orders.
   Invoice and correction issuance now also cover worker termination after XML/PDF
   writes on local storage and concurrent issuance with artifact generation.
   Rejected writes and successful-but-truncated writes are covered through a
   test-only storage adapter. A quiescent full-fixture restore now verifies all
   backed-up tables and retained files after source removal. The 12 September
   export slice below now covers consistent JSON/streaming reads during concurrent
   master-data commits and payment allocation. Next review remaining mutation
   paths for connection consistency and validate the reference host's storage
   and backup snapshot behavior.
   Keep the existing UI.
2. **Prove operation and recovery (F2/F7/F9–F11).** Extend the database export
   evidence to the production storage and backup system; test independent import
   and full restore with real keys and external anchors;
   measure temporary storage and lock duration. Establish integrity/pending alerts.
   Only then add a company-authorized, simple export action in Filament.
3. Complete remaining service authorization, finalized business evidence, supported
   tax cases and bank catch-up completeness (F2/F3/F6/F8/F12), then the documented
   release and operating gates. Keep technical controls automatic where possible;
   do not add bookkeeping forms solely to expose internal verification details.

The other open P0 findings and all release gates still apply. This continuation
does not authorize a GoBD-readiness claim.

### Reconciliation transaction boundaries — 11 September 2026 (F9)

[FinalizeReconciliation](../src/Services/FinalizeReconciliation.php) and
[ReverseReconciliation](../src/Services/ReverseReconciliation.php) now use the
accounting model's connection for the transaction and after-commit callbacks.
Both lock the entity before the bank/reconciliation record, matching the entity-first
ordering used for invoice posting and correction. Authorization and company scope
are checked again on the reloaded record. Filament needs no new fields or actions.

[ReconciliationConnectionTest](../tests/Reconciliation/ReconciliationConnectionTest.php)
uses a separate, disposable SQLite accounting database and real transaction
commits. Injected failure at the final audit write rolls back journal entries,
settlements and reconciliation records, with audit counts unchanged. Reversal
failure retains the active settlement and removes the attempted reversal.
Retries succeed; completion/reversal events are withheld until the outer
accounting transaction commits. Default-connection tables do not receive the
accounting reconciliation. This is not a production concurrency or process-kill
test, and does not establish connection consistency for every package service.

Validation: **91 tests, 507 assertions** passed across reconciliation, correction,
and audit suites on Herd PHP 8.4.25; PHPStan and Pint passed.

Catalog, customer and supplier imports remain a separate data-quality workstream.
Catalog texts/prices are editable defaults, not binding invoice contents. Their
import frequency is not itself a GoBD criterion. For this assessment, preserving
the finalized invoice snapshots takes priority over catalog import enhancements.

### MySQL concurrency evidence — 11 September 2026 (F9)

[MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php) uses two
independent PHP processes and an explicitly held entity lock. It verifies the
worker's entity-row lock wait in MySQL's `performance_schema.data_lock_waits` before letting the
winning operation commit; timing alone is not taken as evidence of contention.
Four scenarios check duplicate requests returning the same reconciliation,
competing bank lines not over-settling one invoice, payment preventing correction,
and correction preventing allocation to the reversed original open item.
Assertions include the expected rejection reason, journal/settlement counts,
open-item state, audit-chain validity, and journal integrity.

Each scenario creates a randomly named `acct_concurrency_<24 hex digits>` database
and drops only that database during cleanup. No host/demo database is migrated or
reset. The ordinary suite skips these opt-in scenarios; the helper test runs only
inside the worker process. [CI](../.github/workflows/tests.yml) now contains a
dedicated PHP 8.4 / MySQL 8.4 job; its remote execution has not been observed here.
See [operations](operations.md) for the local command and access requirements.

These contention cases isolate the database boundary and disable generated invoice artifacts
in their fixtures. They do not prove production storage
durability, full rendering under contention, or all database isolation settings.
F9 and the wider release gates remain open.

Local validation: **four contention scenarios, 40 assertions passed** on MySQL
9.7.0 and Herd PHP 8.4.25. PHPUnit reports five tests with one expected skip for
the worker-only entry point. Lock-wait observation uses `performance_schema`
because repeated `information_schema.innodb_trx` polling was not reliable in the
local run. The ordinary opt-out path and separate-connection regressions passed;
Pint, strict Composer validation, workflow YAML parsing and documentation links
passed. The configured MySQL 8.4 CI job remains unverified until CI executes it.

### Booking-process interruption and retry — 11 September 2026 (F9)

[MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php) additionally
terminates a separate PHP booking process at two explicit barriers: after the
final reconciliation audit row has been inserted inside the transaction, and
inside the after-commit completion event before the caller receives its result.
The worker reports actual journal, settlement and reconciliation counts plus its
transaction level before pausing. The parent forcibly terminates that worker
(`SIGKILL` on Unix, `taskkill /F /T` through Symfony on Windows).

Before commit, the independent parent connection cannot see the settlement.
After termination, acquiring the entity lock waits for MySQL to finish rollback;
the test checks that the original open balance and complete audit history remain,
with no attempted payment journal, settlement or reconciliation left behind.
After commit, the payment remains complete. A fresh PHP process retries with the
same idempotency key and returns the already committed reconciliation without
additional audit events. A further replay also preserves counts and audit history.
Both cases verify the audit chain and journal integrity before and after retry.

This evidence covers termination of the application worker during a bank-payment
allocation. It does not simulate database-server termination, power loss, durable
event delivery, invoice artifact storage, or backup restoration. Generated invoice
artifacts remain disabled in these fixtures. F9 stays open for the remaining
concurrency, storage and recovery gates; no Filament controls were added.

Validation: **six MySQL scenarios, 89 assertions passed** on MySQL 9.7.0 / Herd
PHP 8.4.25 (four contention cases and two interruption cases; one expected skip
for the worker-only entry point). The ordinary opt-out run skipped all seven
MySQL entries and passed the two separate-connection regressions with 20
assertions. Pint passed. The MySQL 8.4 CI execution remains unverified.

### Concurrent payment reversal and invoice correction — 11 September 2026 (F9)

[MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php) now covers
a correction draft followed by a payment allocation, with reversal and correction
competing for the entity lock in two independent PHP processes. Both cases wait
for MySQL to report the child blocked on that lock before proceeding.

- Reversal first: the waiting correction sees the committed reversal and succeeds.
- Correction first: the active settlement rejects issuance with the expected
  business error. The draft, original open item, payment and complete audit history
  remain unchanged. After the waiting reversal commits, retrying issuance succeeds.

Both cases preserve the original invoice attributes, retain the payment and its
linked negative settlement, and create exactly one payment reversal and invoice
correction. Reassigning the same bank line settles the replacement invoice with
one active settlement. Journal counts, open balances, reconciliation history,
audit-chain validity and journal integrity are checked. No runtime service or
Filament changes were needed. Invoice artifact generation remains disabled, so
this does not close the full storage/concurrency and recovery gates.

Validation: **eight MySQL scenarios, 142 assertions passed** on MySQL 9.7.0 /
Herd PHP 8.4.25, with one expected worker-only skip. The two new lock-order
scenarios contribute 53 assertions. The ordinary opt-out run passed 12 correction
and separate-connection tests with 84 assertions and skipped the nine MySQL
entries. Pint and diff whitespace checks passed. Remote MySQL 8.4 CI remains
unverified.

### Invoice file interruption on real local storage — 11 September 2026 (F9)

[MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php) adds four
cases: initial invoice and correction issuance, each interrupted after writing
XML or PDF bytes but before inserting the corresponding attachment record.
These cases enable the real renderer, XML validation and embedded-XML PDF
generation. Parent and child use the same private local disk in an exclusively
created temporary directory named after the isolated MySQL schema. The parent
terminates the worker forcibly and removes only its own directory during cleanup.

At the barrier, the file hash already matches the committed artifact manifest,
while its attachment record is still absent. After rollback, the document remains
issued but unposted, the artifact set is incomplete and the evidence verifier
reports pending work without an integrity issue. For the PDF barrier, XML and its
attachment have already committed. A fresh process resumes issuance on the same
document and completes storage and posting using the same manifest and evidence
hash. A further issuance replay preserves attachment identities and journal counts.

Assertions cover file sizes/hashes, exactly two files per invoice, one artifact
set per invoice, completed evidence with no remaining pending items, and valid
audit/journal chains. Corrections preserve the original document attributes and
file hashes, reverse its open item and leave exactly three journal entries across
original, reversal and replacement. The interrupted attempt remains in the audit
history; completion of a later attempt does not rewrite it. No runtime service or
Filament changes were needed.

This proves recovery at the selected boundary after complete filesystem writes.
It does not test a kill during a partial write, simultaneous artifact generation,
database-server/power failure, object-store durability, immutable storage or full
backup restoration. Those release gates remain open.

Validation: **12 MySQL scenarios, 318 assertions passed** on MySQL 9.7.0 /
Herd PHP 8.4.25 (one expected worker-only skip). The four artifact cases contribute
176 assertions. The ordinary opt-out run passed ten correction tests with 64
assertions and skipped all 13 MySQL entries. Pint, local documentation links and
diff whitespace checks passed. Remote MySQL 8.4 CI remains unverified.

### Concurrent issuance with real invoice files — 11 September 2026 (F9)

[MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php) covers two
simultaneous issuance requests for the same initial invoice or correction. The
first worker pauses while the artifact-set preparation transaction is uncommitted,
or after writing the PDF before its attachment metadata commits. The second worker
starts issuance, and the parent verifies its entity-row lock wait through MySQL
`performance_schema` before releasing the first worker. Neither worker is killed
in these four scenarios; both must finish successfully with the same document ID.

The tests require one issuance event, one prepared artifact set, two completed
artifact attempts, exactly one XML/PDF pair per document, matching file hashes and
sizes, and one posting for an initial invoice. For a correction, the original,
reversal and replacement produce exactly three journal entries. Original invoice
attributes remain unchanged, original files still match their manifests, and the
old open item is reversed exactly once. Evidence verification reports neither
integrity issues nor pending work; audit and journal verification pass.

The fixtures use the real renderer and a private, isolated local directory shared
by both workers. No runtime service or Filament changes were required. This adds
specific contention evidence at preparation and PDF preservation; partial writes,
storage outages, database-server crashes and full restoration remain separate gates.

Validation: **16 MySQL scenarios, 466 assertions passed** on MySQL 9.7.0 /
Herd PHP 8.4.25, with one expected worker-only skip. The four new concurrent
artifact scenarios contribute 148 assertions. The ordinary opt-out run passed
ten correction tests with 64 assertions and skipped all 17 MySQL entries. Pint,
local documentation links and diff whitespace checks passed. Remote MySQL 8.4
CI remains unverified.

### Rejected and partial invoice writes — 11 September 2026 (F9)

[MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php) adds four
storage-fault cases for correction invoices: XML/PDF writes returning failure
without creating a file, and XML/PDF writes storing half the intended bytes while
reporting success. A test-only filesystem adapter injects these faults inside a
separate PHP process; bytes are stored on the same isolated real local disk used
by the other artifact tests. This is deterministic fault injection, not a hardware
failure or an OS-level process kill during a write.

Each failure leaves the correction issued but unposted, its artifact set incomplete,
and its original invoice posting/open item intact. No attachment metadata is
committed for the failed file; earlier XML preservation remains committed when PDF
fails. The failure is recorded in the audit chain. Verification reports pending
work and additionally flags the truncated file as an integrity failure.

A fresh process retries without the fault adapter. A rejected write with no file
can complete from the same prepared manifest; the correction then has exactly
the original, reversal and replacement journal entries. A truncated existing file
blocks retry with an integrity error and remains byte-for-byte unchanged. No
automatic overwrite or deletion conceals it. In both cases the original invoice
attributes and file hashes remain intact, with valid audit and journal chains.
No runtime service or Filament changes were required.

These cases establish detection and safe refusal, not automatic recovery of a
damaged file. Independent restoration, storage outages/read failures, full-volume
behavior, object-store durability and database/power failure remain separate gates.

Validation: **four new MySQL fault scenarios, 134 assertions passed** on MySQL
9.7.0 / Herd PHP 8.4.25. The ordinary artifact/correction regression run passed
27 tests with 208 assertions and skipped all 21 opt-in MySQL entries. Pint and
diff whitespace checks passed. The prior 16-scenario MySQL result above is retained
as historical evidence; the full expanded MySQL group was not rerun in this slice.
Remote MySQL 8.4 CI remains unverified.

### Export inspection and full-fixture restoration — 11 September 2026 (F9/F10)

[MySqlConcurrencyTest](../tests/Integration/MySqlConcurrencyTest.php) now builds a
real MySQL fixture with an issued invoice, correction, payment and four PDF/XML
files. It creates the streaming accounting export, then a separate logical backup
of every test-database table (DDL and rows, including fixture users and audit heads)
and copies the retained files to a new private directory. The fixture is quiescent
during this backup; it is not a concurrent backup implementation.

The test drops only its owned source database and removes its owned source file
directory before rebuilding a newly named MySQL database from the saved backup.
A fresh PHP process compares row hashes for every table, verifies audit/journal
integrity and invoice evidence, and reads the exported dataset into the isolated
SQLite inspection database. Independent SQL checks journal totals and the link
from corrected invoice through settlement/reconciliation to the bank line. File
chunks reconstructed from the export match both their hashes and restored files.

Replaying issuance produces no new journal entry. Reversing and reallocating the
restored payment then succeeds, leaving one active settlement, a zero open balance
and six journal entries, with valid audit and journal chains. Both temporary
databases/directories are cleaned up. No runtime importer or Filament controls
were introduced.

The inspection export remains distinct from the full fixture backup: its deliberate
omissions mean it cannot alone restore an application. The backup reader executes
only SQL saved by this test from its own schema; it is not an external-package
importer. This exercise does not establish third-party interoperability, production
backup tooling, encryption/key recovery, external anchors, object-store guarantees,
live-write snapshot consistency, recovery time objectives or disaster recovery.

Validation: **one MySQL restore scenario, 61 parent assertions passed** on MySQL
9.7.0 / Herd PHP 8.4.25; the child process additionally checks all restored tables,
exported files and continued accounting operations. The ordinary export regression
run passed 18 tests with 140 assertions and skipped all 22 opt-in MySQL entries.
The full expanded MySQL group was not rerun in this slice. Remote MySQL 8.4 CI
remains unverified.

### Consistent dataset reads under concurrent writes — 12 September 2026 (F9/F10)

The JSON and streaming exporters previously inherited the host's transaction
isolation. The entity lock serialized cooperating bookings, but did not stop
independent master-data updates. Under MySQL `READ COMMITTED`, a worker updating
a party and catalog item in one committed transaction between export table reads
produced a mixed dataset: the old party and new catalog item. Both formats passed
their portable integrity verification despite this inconsistency. Two real
MySQL regressions reproduced that failure before the fix.

Both exporters now use `DatasetSnapshot` to select `REPEATABLE READ` for their
independent MySQL/MariaDB transaction. This is a next-transaction setting, not a
session-default change. The existing entity lock remains in place. The later
audit-evidence read also has its own snapshot and entity lock, including the
JSON export's post-anchor refresh. Anchors still follow database commit.
SQLite remains available; unsupported database drivers explicitly fail.
MySQL/MariaDB accounting tables must use InnoDB. See the MySQL documentation for
[consistent reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-consistent-read.html)
and [transaction setting scope](https://dev.mysql.com/doc/refman/8.4/en/set-transaction.html).

Five new scenarios passed on local MySQL 9.7.0 / Herd PHP 8.4.25 (50 assertions):
both formats retain the pre-update party/catalog state while an independent
worker commits changes; both formats make a payment worker wait on the entity
lock (observed in `performance_schema`), exclude that payment from the first
dataset, and include it in the subsequent verified export. The fifth scenario
injects failure, verifies rollback, and proves that a subsequent ordinary host
transaction retains `READ COMMITTED` visibility. An ordinary unit regression
also rejects unsupported drivers before executing the export callback.

Final validation of this slice: the **entire opt-in MySQL group** ran on MySQL
9.7.0 / Herd PHP 8.4.25: **27 entries, 711 parent assertions, one expected skip**
for the worker-only entry point (26 executed scenarios, including the existing
crash/retry, invoice-file and full-fixture restoration tests). The ordinary suite
passed **472 tests, 3,502 assertions**, with the 27 opt-in entries skipped there.
PHPStan reported zero errors; Pint, strict Composer validation and local Markdown
link checks passed. The configured remote MySQL 8.4 CI job was not observed in
this session; this is local 9.7.0 evidence, not an 8.4 deployment approval.

This closes the demonstrated mixed-read defect, not F9/F10 as a whole. The
snapshot covers database rows; retained file hashes/sizes still detect changed
bytes but do not establish immutable storage or a coordinated physical backup.
Schema changes during export are outside the operating contract. Audit evidence
may extend beyond the dataset's `export_event_sequence`; it must not be treated
as a replayable backup. Independent import, real key/anchor recovery, production
storage guarantees and export duration/temp-space/lock-wait measurements remain
release work. No live bank connection or application database was changed.

### Payment claim connection consistency — 12 September 2026 (F9/S-8)

[S-8](../docs/security-audit-2026-09-11-findings.md) moved the transfer, direct
debit, and SCA claims to the accounting model connection. The reconciliation,
invoice, and import paths had cross-connection rollback tests, but the payment
path did not. New [PaymentConnectionTest](../tests/Banking/FinTs/PaymentConnectionTest.php)
mirrors `ReconciliationConnectionTest` with its own migrated SQLite accounting
connection and proves:

- A transfer whose bank is unreachable after the claim commits ends up
  `Ambiguous` **on the accounting connection**, and the default connection's
  `fints_bank_transfers` stays at zero rows for the whole lifecycle.
- A transfer failing local validation inside the claim transaction rolls back
  to `Draft` atomically (no `Initiating` residue) and never leaks to the default
  connection.

This closes the missing payment-path cross-connection regression, not F9 as a
whole: the SCA resume (`mutateOpenSession`) and DirectDebit paths share the same
claim structure, and production concurrency / abrupt-process-kill behavior under
a separate connection remain release-level evidence.

Validation: the full local suite passed on Herd PHP 8.4.25 with **478 tests,
3,525 assertions** (27 MySQL skips). PHPStan reported zero errors; Pint passed.

### Supported tax and rounding evidence — 12 September 2026 (F6)

The F6 requirement to "test ... discounts, credit notes, non-recoverable tax,
mixed/zero rates, and rounding against reviewed expected journals" lacked cent
boundary evidence for rounding. [LineMoneyCalculatorTest](../tests/Support/LineMoneyCalculatorTest.php)
now covers quantity × price, discount, and tax rounding at half-up cent
boundaries, plus exact-value preservation and zero-rate short-circuits.
[InvoiceFlowTest](../tests/Documents/InvoiceFlowTest.php) adds an end-to-end
posting of a fractional quantity (`0.333` units × €1.00, 19% tax): the document
rounds net 33, tax 6, gross 39 and the posted journal balances to those exact
amounts across receivable, revenue, and output-tax accounts.

This adds rounding evidence to the supported tax cases; it does not close F6 as
a whole. Foreign exchange remains rejected until conversion exists, and complete
allowance/charge e-invoice conformance stays open.

Validation: the full local suite passed on Herd PHP 8.4.25 with **482 tests,
3,543 assertions** (27 MySQL skips). PHPStan reported zero errors; Pint passed.

### ZUGFeRD parse coverage and exact tax conversion — 12 September 2026 (F7)

The ZUGFeRD (CII) parse path had no direct unit coverage and converted the tax
rate to basis points with `(int) round($taxRate * 100)`, a float-path the UBL
parser had already abandoned (S-16). [ZugferdEInvoiceAdapter](../src/Documents/ZugferdEInvoiceAdapter.php)
now converts the rate via an exact two-decimal `ExactMoney` conversion, and a new
[ZugferdEInvoiceAdapterTest](../tests/Documents/ZugferdEInvoiceAdapterTest.php)
round-trips a generated EN16931 invoice (19% + 7% lines) through `parse()`,
asserting exact `tax_rate_bp` (1900/700), totals, seller VAT/name, document
number, and issue date.

This adds the missing parse coverage and removes the float conversion, not the
full allowance/charge and business-rule conformance that F7 documents as open.
The import path already reconciles recomputed document totals against the
declared source totals (`intake_totals_mismatch`), so internally inconsistent
sources are rejected before posting.

### E-invoice line-level allowances and charges — 12 September 2026 (F7)

[RegisterPurchaseInvoice::writeLines](../src/Services/RegisterPurchaseInvoice.php)
previously recomputed every line net as quantity × price and ignored the parsed
source net, so a valid line carrying a line-level allowance or charge (where
`LineExtensionAmount` differs from quantity × price) was rejected by the
source-total check. The ZUGFeRD adapter now reads the per-line summation net and
tax, the UBL parser already exposed `line_net_minor`, and the importer carries
the parsed net through to the draft, which posts the source amount. A new
regression proves quantity 2 × €100 with a €50 line allowance posts €150 net /
€28.50 tax / €178.50 gross and passes reconciliation
([PurchaseInvoiceUploadTest](../tests/Documents/PurchaseInvoiceUploadTest.php)).

This closes the line-level allowance/charge conversion case. A **document-level**
allowance (net basis below the sum of line nets) is not distributed across lines
and still blocks conversion (the original is preserved); that remains an
unsupported-conversion boundary, not a silent mis-booking.

### AllowanceCharge parse subset (UBL + CII) — 18 September 2026 (F7)

[UblEInvoiceParser](../src/Documents/UblEInvoiceParser.php) and
[ZugferdEInvoiceAdapter](../src/Documents/ZugferdEInvoiceAdapter.php) now detect
line- and document-level `AllowanceCharge` nodes. The supported subset keeps the
line net already reflected in `LineExtensionAmount` / CII line summation, attaches
structured metadata (`charge_indicator`, `amount_minor`, reason/reasonCode, percent),
and stores reconciling document-level metadata on the parse result /
`e_invoice_meta` when the document net still equals the sum of line nets (zero net
effect). Unknown structures (for example `BaseAmount`), non-reconciling amounts, and
document-level allowances that would require separate posting lines **fail closed**
with a clear `DocumentException`. This is F7 progress, not a claim that F7 or full
EN 16931 allowance/charge conformance is closed. No FX support was added.
Intake archive-before-parse is unchanged. Unit fixtures under
[tests/Fixtures/ubl](../tests/Fixtures/ubl) and
[UblAllowanceChargeTest](../tests/Documents/UblAllowanceChargeTest.php) cover the
supported and fail-closed cases; please run the full suite locally or in CI before
merge.

### E-invoice tax mapping (DE EUR) — 18 September 2026 (F7)

[MapImportedEInvoiceTax](../src/Tax/MapImportedEInvoiceTax.php) maps EN 16931 /
UNTDID 5305 category + rate from structured purchase imports onto the package
DE tax codes (DE-19, DE-7, DE-0, DE-RC, DE-IG-ACQ, DE-EXPORT), including
temporary COVID rates 16%/5% that share the DE-19/DE-7 codes. Unknown rates,
inconsistent category/rate pairs, and unknown categories **fail closed** with
unmapped_e_invoice_tax; intake evidence is retained and no draft document is
created. Scope remains DE EUR only — foreign VAT rates stay rejected. This does
not close F7 (schema validation and full EN 16931 conformance remain open).
Coverage: [MapImportedEInvoiceTaxTest](../tests/Tax/MapImportedEInvoiceTaxTest.php)
and [EInvoiceTaxMappingImportTest](../tests/Documents/EInvoiceTaxMappingImportTest.php).

### CI verification — 12 September 2026 (F9)

The previously unobserved remote MySQL 8.4 concurrency job ran and passed on
CI for the payment connection and authorization commits
([run 34683038525](https://github.com/fliix-cloud/filament-fints-accounting/actions/runs/34683038525)):
all seven jobs (`phpunit` on PHP 8.3/8.4/8.5, `mysql concurrency`, `pint`,
`phpstan`, `composer validate`) succeeded. This is the first observed remote
execution of the opt-in concurrency suite; it does not replace the local
MySQL 9.7 evidence or the remaining storage/restore release gates.

## Existing foundation

| Area | Evidence in the reviewed code | Assessment |
| --- | --- | --- |
| Ledger | [FirstPartyLedgerEngine](../src/Ledger/FirstPartyLedgerEngine.php), [ledger tests](../tests/Ledger/LedgerEngineTest.php): balance checks, numbering, idempotency, period locks, linked reversals | Useful foundation; see F2–F6 |
| Documents and tax | Party/company snapshots, confirmed expense categories, [TaxRuleVersion](../src/Models/TaxRuleVersion.php) reference/overlap checks, [invoice tests](../tests/Documents/InvoiceFlowTest.php) | Partial lifecycle protection |
| Banking and reconciliation | Source versions, booked-only finalization, exact splits and settlements; [import tests](../tests/Banking/UnifiedBankTransactionImporterTest.php), [reconciliation tests](../tests/Reconciliation/ReconciliationTest.php), [payment safety tests](../tests/Banking/FinTs/PaymentSubmissionSafetyTest.php) | Good mechanisms; not proof of complete bank records |
| Audit and files | Canonical event chain, external anchors, offline evidence verification, SHA-256 checks on attachment reads; [audit tests](../tests/Audit/AuditAnchorTest.php), [attachment tests](../tests/Attachments/AttachmentStorageTest.php) | Detects specific failures, not all business-data changes |
| Quality checks | [Baseline CI](https://github.com/fliix-cloud/filament-fints-accounting/actions/runs/33943471032): PHPUnit on PHP 8.3/8.4/8.5, PHPStan, Pint, Composer validation passed | Existing suite is green; compliance gaps remain |

## Baseline findings and acceptance criteria

The findings describe commit 99b2218. Code links locate the affected files;
the progress table above records subsequent corrections and remaining work.

P0 means a direct integrity/access risk. P1 means another mandatory item before
the scoped readiness claim. These priorities are engineering judgments, not
official GoBD classifications.

| ID | Baseline finding and code location | Required outcome / regression evidence |
| --- | --- | --- |
| F1 · P0 | [DeletePurchaseInvoiceDraft](../src/Services/DeletePurchaseInvoiceDraft.php) deletes the received PDF/XML and metadata without a retained deletion event. [InvoiceLayoutTest](../tests/Filament/InvoiceLayoutTest.php) explicitly expects this deletion. A draft booking does not make a received original disposable. | Preserve tax-relevant originals from intake, including rejected/invalid imports. Discard the booking draft separately, with actor/reason and a retained intake record. Never remove the only retained copy of a received invoice. |
| F2 · P0 | `journal.posted` logs sequence/source type, not journal amounts/accounts. [VerifyCommand](../src/Commands/VerifyCommand.php) checks balance and line count, but does not bind journal/document/settlement contents to audit hashes. A balanced SQL change or account substitution can leave these checks green. [Attachment](../src/Models/Attachment.php) has no update/delete guard. | Protect finalized records and references against application and bulk-write paths; verify canonical business snapshots against independently anchored evidence. Test balanced tampering, missing records, changed attachments, and privileged mutation. ORM events and self-contained hashes alone are insufficient. |
| F3 · P0 | [DefaultAccountingAuthorizer](../src/Authorization/DefaultAccountingAuthorizer.php) allows any resolved actor when a Gate is undefined. `DeletePurchaseInvoiceDraft` has no service-level authorization. | Deny undefined abilities, validate every public mutation service, and test anonymous/read-only users through services and UI. Provide an explicit least-privilege setup; do not rely on hidden navigation. |
| F4 · P0 | [Document](../src/Models/Document.php) does not protect status/ownership fields as commercial fields: a saved transition back to draft can remove later commercial-field protection. [JournalLine](../src/Models/JournalLine.php) and [DocumentLine](../src/Models/DocumentLine.php) inspect the current parent, not both original and new parents. | Enforce allowed state transitions and immutable parent/owner links. Test status downgrade across two saves, line reassignment, stale loaded relations, and bulk updates. Add controlled correction workflows rather than editable posted states. |
| F5 · P1 | [CloseAccountingPeriod](../src/Services/CloseAccountingPeriod.php) can replace hard-closed with soft-closed via `hard: false`; the ledger blocks only hard-closed. [ReopenAccountingPeriod](../src/Services/ReopenAccountingPeriod.php) accepts an empty reason. | Prevent close from weakening a lock. Require separately authorized, non-empty-reason reopening with before/after history. Test backdated and concurrent posting against closure. |
| F6 · P1 | [PostDocument](../src/Services/PostDocument.php) uses [JournalLineDraft](../src/Ledger/JournalLineDraft.php) helpers that copy transaction amounts into base amounts without applying the exchange rate. It does not require issued/received status. Sales credit notes follow the sales-invoice debit/credit branch. Invoice line writers store `discount` but calculate quantity × price without it. | Reject unsupported currencies/features server-side or implement them correctly. Require a valid document lifecycle before posting. Test FX, discounts, credit notes, non-recoverable tax, mixed/zero rates, and rounding against reviewed expected journals. A balanced journal is not necessarily correct. |
| F7 · P1 | [ImportPurchaseInvoice](../src/Services/ImportPurchaseInvoice.php) requires a PDF, parses before preservation, and deduplicates by PDF hash before considering separately supplied XML. [UblEInvoiceParser](../src/Documents/UblEInvoiceParser.php) checks basic fields, not complete format/business rules. Source totals are metadata; registration recalculates lines without reconciling those totals. | Accept and retain standalone XML; archive first, validate second. Use the structured content in import identity. Report parse success separately from conformance. Test identical PDF/different XML, allowances/charges, invalid XML, and source-total mismatches; block unsupported accounting conversion without losing the original. |
| F8 · P1 | [PostingRuleVersion](../src/Models/PostingRuleVersion.php), [LedgerAccount](../src/Models/LedgerAccount.php), settlements, and reconciliation splits lack comparable finalized-history guards. The CSV exporter reads current account names/codes. Draft updates replace lines without recording before/after values. | Preserve the historical meaning of used mappings and tax-relevant intake changes. Test later master-data edits against old exports and document history. Distinguish disposable, unissued sales working drafts from records already introduced into accounting processing. |
| F9 · P1 | Package models support `ACCOUNTING_DB_CONNECTION`, but ledger/document/reconciliation/payment services use default `DB::transaction` while [AuditLogger](../src/Services/AuditLogger.php) uses the entity connection. With different connections, rollback/locking boundaries need not cover the business writes. | Use one explicit accounting connection for related writes, locks, audit, and after-commit events, or reject unsupported configurations. Inject failures midway and prove complete rollback on the supported production database, including concurrent requests. |
| F10 · P1 | [GenericJournalCsvExporter](../src/Export/GenericJournalCsvExporter.php) exports journal rows, not the complete retained accounting dataset with machine-readable relationships. Audit JSON exports events/anchors, not all records. Journal views do not establish complete account-ledger reporting or Z1/Z2/Z3 access. | Implement authorized read-only inspection, requested evaluations, and scoped machine-readable transfer of records, originals, structures, and relationships. Prove document → journal → settlement → bank and reverse tracing, stable historical exports, totals, and independent import. Do not export credentials or unrelated personal data. |
| F11 · P1 | [StoreAttachment](../src/Services/StoreAttachment.php) uses ordinary `put` and error cleanup deletes. Paths depend on company/hash/filename, not the owning document. [GenerateInvoiceArtifacts](../src/Services/GenerateInvoiceArtifacts.php) may regenerate after renderer changes; issuance is committed before artifacts/posting finish. | Define immutable originals and the authoritative issued artifact. Prevent cleanup from deleting pre-existing/shared objects. Test interrupted issuance, retry, storage failure, restore, and renderer upgrades; expose incomplete operations for recovery. |
| F12 · P1 | [TransactionSyncService](../src/Banking/FinTs/Services/TransactionSyncService.php) clips requested history to `max_range_days` instead of proving catch-up completeness. Import counts and source versions do not prove no transactions were omitted. | Chunk catch-up ranges, track coverage and unresolved failures, reconcile available bank statement/balance evidence, and test long outages, duplicate-looking bookings, pending/booked transitions, and corrections. Add intake/posting backlog controls; do not infer completeness from a successful sync. |

F6/F7 include accounting and e-invoice defects relevant to record accuracy;
they are not claims that every format feature is itself mandated by GoBD.
Unsupported conversions must remain visible and preserve their input evidence.

## Operating requirements still to evidence

- **Retention:** define classes, start dates, extensions, and legal holds.
  AO § 147 generally distinguishes ten-year books, eight-year booking vouchers,
  and six-year other listed records; do not apply one blanket expiry. The FinTS
  SCA cleanup setting is not an accounting retention policy. Initially disable
  accounting disposal; automatic deletion is not needed to reach readiness.
- **Storage and recovery:** demonstrate private storage, protected originals,
  independent anchor permissions, scheduled verification, alert response,
  encrypted backups, key recovery, and a full restore/export exercise.
  `ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED=true` is an assertion, not a test.
  Object lock is a recommended implementation, not a universal statutory
  technology requirement; equivalent effective controls need evidence.
- **Procedures and people:** document capture, review, correction, period close,
  access assignment, incidents, and control execution. Keep the procedure's
  history and the responsible people identifiable. Separation of duties must
  fit the organization; an elaborate approval UI is not automatically required.
- **Deployment identity:** record exact dependencies, configuration, database,
  storage, and application revision. `nemiah/php-fints` currently follows
  `dev-master`; retain the resolved host lock file. Prove non-destructive
  upgrades and historical readability. Never use development database resets
  to migrate real accounting records.

The 2025 GoBD amendment permits retaining only the structured part of a hybrid
e-invoice when the image adds no tax-relevant information. It also permits
content-identical regeneration of outgoing invoice images under its conditions.
This does not permit dropping XML or silently replacing relevant content.
See also the [BMF e-invoice FAQ][einvoice].

## Smallest credible delivery plan

1. **Protect records and access:** fix F1–F5 and F8–F9, add negative regression
   tests, and prove atomicity on one explicitly supported production database.
2. **Make the supported workflows reliable:** fix F6–F7 and F11–F12. A proposed
   first scope is one German company, EUR transactions, ordinary sales/purchase
   invoices, FinTS, reconciliation, and corrections. Customers may be abroad;
   test the supported tax cases. Reject unsupported calculations, not originals.
3. **Make the system inspectable:** complete F10 and establish the operating
   evidence above. If positioned as a subledger, define and test the complete
   handoff to the main ledger. Do not advertise a full accounting replacement
   without the required account/reporting functions.
4. **Validate the claim:** freeze a release, retain the evidence, and commission
   an independent accounting/IT-controls review of the reference installation.
   This is our recommended claim gate, not a statutory certification requirement.
   Reassess affected controls after material changes.

Release acceptance must include adversarial mutation tests, crash/retry and
concurrency tests, end-to-end invoice/correction/settlement scenarios, and an
auditor-style export/restore exercise. Existing [CI](../.github/workflows/tests.yml)
uses SQLite in memory and [fake storage](../tests/Attachments/AttachmentStorageTest.php)
for the ordinary suite, plus a dedicated MySQL 8.4 process-test job whose remote
execution remains unverified here. The local MySQL evidence above covers specific
locking and worker-termination cases; immutable-storage behavior remains unproven.

Keep the core compliance and operating guidance in [installation](install.md), [architecture](architecture.md),
[operations](operations.md), and this assessment. Deployment-specific procedures
and evidence belong to the operator; do not recreate an internal roadmap archive
in `docs/`. Only the partial runtime corrections listed above are implemented;
this assessment is not a release approval.

[ao146]: https://www.gesetze-im-internet.de/ao_1977/__146.html
[ao147]: https://www.gesetze-im-internet.de/ao_1977/__147.html
[gobd]: https://ao.bundesfinanzministerium.de/ao/2025/Anhaenge/BMF-Schreiben-und-gleichlautende-Laendererlasse/Anhang-33/inhalt.html
[amendment]: https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Weitere_Steuerthemen/Abgabenordnung/2025-07-14-GoBD-2-aenderung.html
[einvoice]: https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html
