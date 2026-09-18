# GoBD readiness

_Status: technical documentation of the pre-release package state reviewed in
September 2026. This is not a legal opinion, certification, or production
assessment._

## Verdict and claim boundary

The package is **not ready for an unqualified "GoBD-konform" claim**. It contains
useful controls, but a defensible claim requires a tested release, a defined
scope, and evidence that the host deployment and operating procedures meet the
requirements. No supported persistent-installation baseline or production
release approval is established.

The current public wording should remain:

> Provides double-entry bookkeeping, audit-chain verification, and document-
> integrity controls. GoBD readiness is under review.

Even after technical release gates pass, do not equate GoBD readiness with
complete VAT correctness, XRechnung conformity, DATEV compatibility, statutory
financial statements, or a tax/legal approval. GoBD responsibility covers the
actual taxpayer system, permissions, infrastructure, retention, procedures, and
change documentation, not software alone.

## Controls currently present

The current implementation and documentation describe these controls:

- legal-entity scoping, explicit authorization Gates, immutable posted journals,
  invoice versions, linked reversals, period controls, and idempotent posting;
- exact money handling, explicit rejection of unsupported foreign-currency
  conversion, versioned tax/posting rules, and retained payment evidence;
- private intake of PDF/XML originals before parsing, intake manifests, hashes,
  retry/recovery workflows, and retained outgoing PDF/XML artifact sets;
- SHA-256 audit-chain verification, versioned journal snapshots, invoice-evidence
  checks, external audit anchors, and scoped audit/dataset exports;
- deterministic bank reconciliation and tracked bank-sync catch-up ranges; and
- local XML/PDF validation and structured e-invoice generation through ZUGFeRD.

These are technical controls and test evidence, not proof that every business
case, deployment, or statutory obligation is covered.

## Recent F7 progress (September 2026)

Shipped on `main`, still **not** a claim that F7 is closed:

- **AllowanceCharge subset:** UBL/CII line- and document-level nodes are detected.
  Line nets already reflected in line totals are imported with metadata; unknown
  structures and non-reconciling amounts fail closed and keep intake evidence
  (`UblEInvoiceParser`, `ZugferdEInvoiceAdapter`, related tests).
- **DE EUR tax mapping:** EN 16931 / UNTDID 5305 category + rate map onto package
  codes (`DE-19`, `DE-7`, `DE-0`, `DE-RC`, `DE-IG-ACQ`, `DE-EXPORT`), including
  temporary COVID 16%/5% rates. Unknown rates and inconsistent pairs fail closed
  (`MapImportedEInvoiceTax`). Foreign VAT remains rejected.

Still open for F7: schema / full EN 16931 business-rule validation, and any
allowance/charge shapes outside the supported subset.

## Open release and operating gaps

The following remain material limits on an unqualified claim:

1. **Production evidence:** concurrency, process interruption, database/storage
   failure, immutable-storage behavior, production snapshot consistency,
   third-party import, and full restore with real keys and external anchors must
   be demonstrated on the first supported deployment.
2. **Complete evidence model:** finalized settlement history, every relevant
   converted purchase line, and other business relationships are not all bound
   to independently preserved historical evidence. Dataset export has a fixed,
   documented scope and is not a full host backup.
3. **Authorization and mutation coverage:** all public mutation services,
   integrations, bulk-write paths, and host wiring still require a complete
   least-privilege audit. ORM guards do not protect privileged SQL or storage
   access.
4. **Accounting correctness:** F6 core paths (EUR-only, discounts, credit notes)
   are largely in place; remaining tax, rounding, and edge cases still need
   reviewed expected results. Foreign currency remains unsupported and must not
   be inferred from balanced postings.
5. **E-invoice conformity:** the AllowanceCharge subset and DE EUR tax mapping
   above are progress only. Parsing/extraction and local checks are not complete
   format or business-rule validation. Unsupported or mismatching input must
   remain preserved and visible rather than being booked.
6. **Master data and connection boundaries:** catalog transfer still needs
   connection-consistency and historical-change evidence. The host must use the
   documented single accounting connection unless a separately tested setup is
   provided.
7. **Bank completeness:** oldest-first catch-up chunking, gap reporting, and an
   automatic multi-chunk drain loop (`drainCatchUp` / SyncCommand `--drain`) are
   in place. Pending→booked (and pending/booked→storno) promotion updates the
   existing statement line in place when end-to-end id or counterparty identity
   matches uniquely; ambiguous or weak matches fail closed without merging.
   Transaction sync now retains statement/balance reconciliation evidence on
   each FinTS sync run (matched / mismatched / unavailable); mismatches fail
   closed and unavailable evidence is surfaced so a green sync is not treated as
   completeness proof. Operator backlog controls list open catch-up markers,
   failed/attention/stuck sync runs, pending intakes, and statement lines needing
   review (`BankingBacklogService`, `filament-accounting:banking-backlog`,
   Filament sync-backlog resource/widget, continue/ack actions). Acknowledgement
   records operator review without inventing completeness. Concurrent syncs on
   the same account fail closed under a row lock while another run is `running`
   or awaiting SCA; SCA completion of a transaction sync resumes catch-up via
   `finalizeInterruptedSyncAndContinueCatchUp` / `drainCatchUp` and never claims
   completeness while `catch_up_from` remains or a follow-up SCA/concurrent stop
   recurs. Hosts should still retain operational evidence of SCA resumes and
   competing sync attempts.
8. **Release baseline:** a supported schema baseline, upgrade matrix, migration
   policy for retained data, rollback limits, dependency state, and recovery
   procedure must be published before a production release claim.

## Required operator evidence

A host seeking a scoped assessment must at least retain:

- the deployed package commit, lock file, migrations, configuration, and change
  procedure;
- role/Gate definitions, access reviews, company scope checks, and server access
  controls for console commands;
- database, private-file, artifact, key, and external-anchor backup/restore
  tests;
- scheduled verification, pending-work and sync-gap alerts, incident handling,
  retention/legal-hold/disposal procedures, and release test results; and
- accounting/tax review of supported transaction cases and an assessment of the
  applicable jurisdiction and business process.

See [Operations](operations.md) for the concrete commands and trust boundaries.

## Conditions for future wording

Only after the release gates and operating evidence above pass should the project
consider wording such as:

> Version X provides the technical requirements for GoBD-compliant processing
> within the documented scope when deployed and operated according to the
> specified requirements.

Any stronger German marketing wording needs accounting and legal review. This
document should be updated with the exact version, scope, evidence set, and
remaining limitations rather than converting historical test results into a
blanket compliance statement.
