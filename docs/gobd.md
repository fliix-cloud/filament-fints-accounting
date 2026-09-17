# GoBD readiness

_Status: technical documentation of the pre-release package state reviewed in
September 2026. This is not a legal opinion, certification, or production
assessment._

## Verdict and claim boundary

The package is **not ready for an unqualified “GoBD-konform” claim**. It contains
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
4. **Accounting correctness:** remaining tax, rounding, credit-note, allowance,
   charge, and business-rule cases need reviewed expected results. Foreign
   currency remains unsupported and must not be inferred from balanced postings.
5. **E-invoice conformity:** parsing/extraction and local checks are not complete
   format or business-rule validation. Unsupported or mismatching input must
   remain preserved and visible rather than being booked.
6. **Master data and connection boundaries:** catalog transfer still needs
   connection-consistency and historical-change evidence. The host must use the
   documented single accounting connection unless a separately tested setup is
   provided.
7. **Bank completeness:** sequential catch-up is tracked, but concurrent/SCA
   interruption behavior, pending-to-booked transitions, statement/balance
   reconciliation, and backlog controls need operational evidence.
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
