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
