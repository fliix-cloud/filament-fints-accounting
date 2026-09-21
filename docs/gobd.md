# GoBD readiness — package close-out

_Status: technical documentation of the package state as of September 2026.
This is not a legal opinion, certification, or production assessment._

## Verdict / claim boundary

The package is **not ready for an unqualified "GoBD-konform" claim**. It ships
technical bookkeeping and integrity controls, but a defensible claim still needs
a tested release, a defined host scope, and operator evidence. No supported
persistent-installation baseline or production release approval is established.

Keep public wording at:

> Provides double-entry bookkeeping, audit-chain verification, and document-
> integrity controls. GoBD readiness is under review.

Even after package technical gates are done, do **not** equate GoBD readiness with
complete VAT correctness, full EN 16931 / XRechnung conformity, DATEV
compatibility, statutory financial statements, or tax/legal approval. GoBD
responsibility covers the taxpayer system — permissions, infrastructure,
retention, procedures, and change documentation — not software alone.

## Package technical gates

### DONE (stop iterating on package GoBD for these)

| Gate | What shipped | Notes |
| --- | --- | --- |
| F3 least-privilege (package surface) | Public mutation services call `AccountingAuthorizer::authorize`; FinTS sync / backlog continue / ack require `sync_bank`; assign/split/finalize/reverse reconciliation gated; inventory test `MutationGateCoverageTest` | Hosts must still define Gates / `AccountingAuthorizer` and protect artisan |
| Settlement evidence binding | Settlements store frozen open-item / document / party / statement-line evidence (`SettlementEvidence`, `accounting_settlements.evidence`) including reversals | Complements journal snapshots; does not replace host backups |
| Converted purchase-line binding | Intake-backed lines already carry `source_line_hash`; registration now **fails closed** if a `source_line_index` is present without its hash | Manual drafts without import metadata remain unbound by design |
| F12 banking completeness controls | Catch-up drain, pending→booked promotion, balance evidence on sync runs, backlog inventory / ack, concurrent+SCA fail-closed resume | Green sync ≠ completeness while catch-up remains |
| F6 core EUR paths | Exact money, discounts, credit notes, foreign currency rejected | Remaining edge cases → tax/accounting review (host) |
| F7 subset (not full conformity) | AllowanceCharge supported subset; DE EUR EN 16931 category/rate mapping fail-closed; incoming schema + material BR gate including buyer, BG-23, CIUS identifiers | Full Schematron / certification deferred (below) |
| Audit chain / invoice evidence / anchors / dataset export | SHA-256 chain, journal snapshots, intake+artifact verification, external anchors, scoped dataset | Dataset ≠ host backup |

### DEFERRED (package) — with reason

| Item | Reason |
| --- | --- |
| **F7 full EN 16931 Schematron / certification** | Incoming intake now runs a documented **reception subset** schema + material BR gate (buyer, BG-23, CIUS identifiers — below). Full CEN Schematron, KoSIT/XRechnung certification, Peppol Access Point, and a complete BR engine remain a separate e-invoice conformity track. Unsupported or mismatching input must remain preserved and visible, not booked. |
| Exhaustive ORM/SQL/storage privilege proof | Package Gates cannot stop privileged DB/storage admins; residual is host | 
| Complete master-data historical-change archive for every catalog/party edit | Connection-consistency controls exist; full change-evidence productization deferred | 
| Foreign-currency bookkeeping | Explicitly unsupported | 

## Host-only residuals (forever outside package)

These cannot be coded away in the package. Treat them as the operator checklist:

1. **Production evidence** — concurrency under real load, process interruption, DB/storage failure, immutable storage behavior, snapshot consistency, third-party import, full restore with real keys and external anchors on the first supported deployment.
2. **Gate / role wiring** — define every ability in `authorization.abilities` (or custom `AccountingAuthorizer`); review company scope; protect console (`sync-bank`, `audit-anchor`, `audit-export`, verify). Authentication and hidden navigation are not access control.
3. **Infrastructure & retention** — private disks, independent anchor credentials, versioning/object-lock attestation (`ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED`), backups of DB + private files + artifacts + keys + config, restore drills.
4. **Monitoring & procedures** — scheduled verify / storage-integrity, backlog alerts, SCA resume evidence, incident handling, retention/legal-hold/disposal, release identity (commit, lockfile, migrations).
5. **Tax / legal / accounting review** — supported transaction matrix, jurisdiction assessment, any stronger German marketing wording.
6. **Release baseline** — schema/upgrade matrix, migration policy for retained data, rollback limits, dependency state, recovery procedure before any production claim.

See [Operations](operations.md) for commands and trust boundaries.

## F7 supported-subset boundary (e-invoice, not GoBD close)

Incoming structured XML is checked **before** a purchase document is created.
Failures keep the intake evidence (`blocked`) and do not invent postable amounts.
This is **not** a claim of full EN 16931, XRechnung, or ZUGFeRD certification.

**In scope for the package today:**

- XML Schema on original bytes for the profiles the package imports:
  - UBL Invoice: package subset schema (`resources/e-invoice/schema/ubl/`) type-checking identifiers, dates, amounts, seller, lines, tax, and allowance/charge, plus required-element presence. This is not the full OASIS UBL 2.1 XSD.
  - CII / Factur-X: guessed profile XSD from `horstoeko/zugferd` (EN 16931 / XRechnung CII / BASIC / …) against the original XML.
- EN 16931 business rules that are material for the DE-EUR reception subset:
  - Pflichtfelder: BR-02 number, BR-03 issue date, BR-04 invoice type code (UNTDID 1001 subset: 380/381/384/389/326/261/382/386/875/876/877), BR-05 currency, BR-07 buyer name, BR-08 seller name, BR-16 at least one line.
  - Buyer postal address BG-8: BR-10 presence, BR-11 country code. When the specification identifier is an XRechnung CIUS, also BR-DE-8 city and BR-DE-9 post code.
  - Currency must be EUR (package scope).
  - Totals: BR-CO-10 (Σ line nets = TaxExclusiveAmount), BR-CO-15 (exclusive + VAT = inclusive), BR-CO-17 (VAT amount = Σ line VAT from category rates).
  - VAT breakdown BG-23: at least one group with category/rate/taxable/tax amount; per-group tax = taxable × rate; BR-CO-13 (Σ taxable = TaxExclusiveAmount); BR-CO-14 (Σ VAT = invoice VAT); groups must match invoice lines by category and rate. Inconsistent breakdowns fail closed.
  - Specification identifier BR-01 (BT-24): EN 16931 core (`urn:cen.eu:en16931:2017`) or an allow-listed `#compliant#` CIUS (XRechnung 1.2–3.0, Peppol BIS Billing 3.0 XML, Factur-X/ZUGFeRD EN16931 and BASIC). This is identifier checking, not Peppol network access.
  - Business process BT-23 / BR-DE-2: required when the CIUS is XRechnung; if present, must be `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`.
  - Tax category/rate mapping onto `DE-19`, `DE-7`, `DE-0`, `DE-RC`, `DE-IG-ACQ`, `DE-EXPORT` (plus temporary 16%/5%); unknown or inconsistent pairs fail closed; foreign VAT rejected.
- AllowanceCharge: detect UBL/CII line- and document-level charges; import shapes whose nets already reconcile into line totals; fail closed otherwise while keeping intake evidence.
- Local XML/PDF checks and ZUGFeRD generation helpers (outbound generation still uses horstoeko XSD + object validators).

A successful import records `e_invoice_meta.validation_status = de_eur_subset_passed`. That status means the documented reception subset gate passed, not that the file is certified EN 16931, XRechnung, or ZUGFeRD.

**Out of scope / deferred:**

- Full EN 16931 Schematron / complete business-rule engines (payment means, seller/buyer electronic addresses BT-34/BT-49, remaining BR-DE rules, EXTENDED `#conformant#` profiles, …).
- Full OASIS UBL 2.1 XSD (TaxScheme, PayableAmount, and other official-required nodes that the current import subset schema does not demand).
- Peppol Access Point, KoSIT validator, XRechnung/ZUGFeRD certification, and outbound XRechnung issuing.
- Allowance/charge shapes outside the supported subset.

## Conditions for future wording

Only after host evidence above exists should the project consider:

> Version X provides the technical requirements for GoBD-compliant processing
> within the documented scope when deployed and operated according to the
> specified requirements.

Any stronger claim needs accounting and legal review. Update this document with
exact version, scope, evidence set, and remaining host limits — do not convert
historical tests into a blanket compliance statement.

## What Marc can treat as forgotten vs checklist

**Forgotten (stop package GoBD iteration):** F3 package Gate coverage audit +
code fixes, settlement/purchase-line evidence binding holes that were
code-fixable, F12 banking package controls, F7 subset documentation and
explicit deferral of full EN 16931.

**Keep on host checklist:** production restore drills, Gate/role definitions,
anchor storage attestation, monitoring, tax/legal review, release baseline,
and any future e-invoice conformity project (separate from GoBD bookkeeping).
