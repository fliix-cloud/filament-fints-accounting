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
| F7 subset (not full conformity) | AllowanceCharge supported subset; DE EUR EN 16931 category/rate mapping fail-closed; incoming schema + material BR gate; outbound Factur-X EN 16931 CII (optional XRechnung 3 CII) issuing subset fail-closed before originals | Full Schematron / certification deferred (below) |
| Audit chain / invoice evidence / anchors / dataset export | SHA-256 chain, journal snapshots, intake+artifact verification, external anchors, scoped dataset | Dataset ≠ host backup |

### DEFERRED (package) — with reason

| Item | Reason |
| --- | --- |
| **F7 full EN 16931 Schematron / certification** | Incoming intake and outbound Factur-X generation run a documented **DE-EUR subset** (reception gate below; issuing subset before PDF/XML originals). Full CEN Schematron, KoSIT/XRechnung certification, Peppol Access Point, and a complete BR engine remain a separate e-invoice conformity track. Unsupported or mismatching input must remain preserved and visible, not booked. |
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
  - Business process BT-23 / PEPPOL-EN16931-R001: required when the CIUS is XRechnung; if present, must be `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`.
  - Electronic addresses when the CIUS is XRechnung or Peppol BIS Billing 3.0 XML: PEPPOL-EN16931-R020 seller (BT-34) and PEPPOL-EN16931-R010 buyer (BT-49) with a scheme identifier (BR-62). The scheme must be in the documented EAS subset (BR-CL-25): `EM` (electronic mail), `0060` (DUNS), `0088` (GS1 GLN), `0204` (Leitweg-ID), `0246` (German Electronic Business Address), `9930` (German VAT number). This is not the full CEF/UNECE EAS list and not Peppol routing.
  - Payment means for XRechnung CIUS: BR-DE-13 at least one BG-16 with BT-81 in `{30,48,49,54,57,58,59}`; BR-DE-23 credit transfer (30/58) requires payee IBAN BT-84; BR-DE-25 direct debit (49/59) requires debtor IBAN BT-91.
  - Buyer reference BT-10 / BR-DE-15: required when the CIUS is XRechnung (non-empty `BuyerReference` / CII `BuyerReference`).
  - Seller contact BG-6 for XRechnung CIUS: BR-DE-2 group presence; BR-DE-5 contact point (BT-41), BR-DE-6 telephone (BT-42), BR-DE-7 email (BT-43).
  - Seller postal address for XRechnung CIUS: BR-DE-3 city (BT-37) and BR-DE-4 post code (BT-38). Values come from the parsed seller address (UBL `PostalAddress/CityName` and `PostalZone`, CII `PostalTradeAddress/CityName` and `PostcodeCode`). Seller address line 1 (BT-35) stays optional. EN 16931 core without an XRechnung CIUS does not require these fields.
  - Seller VAT identifier for XRechnung CIUS: BR-DE-16 requires a seller VAT identifier (BT-31) or a complete seller tax representative (BG-11) when an invoice line or the VAT breakdown uses category S, Z, E, AE, K, G, L, or M. BG-11 counts only with name (BT-62, BR-18), VAT identifier (BT-63), and country code (BT-70, BR-20). Seller tax registration (BT-32 / CII scheme `FC`) does not satisfy BR-DE-16. EN 16931 core without an XRechnung CIUS does not require BT-31 or BG-11.
  - VAT identifier format (BR-CO-09) when BT-31 or BT-63 is present: a country prefix plus an alphanumeric body. `DE` must be followed by exactly 9 digits. Greece may use the prefix `EL`. No checksum and no VIES lookup. A present identifier with the wrong shape fails closed on every profile, including EN 16931 core.
  - Tax category/rate mapping onto `DE-19`, `DE-7`, `DE-0`, `DE-RC`, `DE-IG-ACQ`, `DE-EXPORT` (plus temporary 16%/5%); unknown or inconsistent pairs fail closed; foreign VAT rejected.
- AllowanceCharge: detect UBL/CII line- and document-level charges; import shapes whose nets already reconcile into line totals; fail closed otherwise while keeping intake evidence.
- Local XML/PDF checks and ZUGFeRD generation helpers. Outbound generation keeps horstoeko XSD and document validators, then runs the same reception subset gate on the generated CII.
- Outbound ZUGFeRD / Factur-X for issued sales invoices (CII embedded in PDF/A-3), checked **before** an original is stored:
  - Profile `en16931` (config default) or `xrechnung_3`. Any other profile, including EXTENDED and a separate XRechnung UBL document, is refused.
  - Snapshot checks: EUR only; invoice number, issue date, and type code 380 (or 384 when a preceding invoice is present); seller name, city (BT-37), post code (BT-38), and country; seller VAT identifier (BT-31) when a line maps to VAT category S, Z, E, AE, K, G, L, or M; buyer name and country; at least one line; DE-EUR tax mapping; BG-23 totals.
  - A credit-transfer IBAN is written only when the seller snapshot contains `invoice_iban`. The XRechnung profile requires that IBAN. Direct debit still requires the frozen mandate reference, creditor identifier, and debtor IBAN. The generator does not invent an IBAN, buyer reference, or seller contact.
  - Seller contact (BG-6: name, phone, email), buyer reference (BT-10, the customer `external_reference`), and seller/buyer electronic addresses are required only for `xrechnung_3`. The address scheme is `EM` when the value is an email. EN 16931 does not require those CIUS fields. Buyer city and post code are required only for `xrechnung_3`.
  - After generation, horstoeko XSD and document validators run, `ValidateIncomingEInvoice` checks the XML, and the PDF embed must be byte-identical to that XML.
  - A successful artifact set records `meta.validation_status = de_eur_subset_passed` and `meta.profile` as the profile that was generated.

A successful import, and a successful outbound artifact set, record `validation_status = de_eur_subset_passed`. That status means the documented DE-EUR subset gate passed, not that the file is certified EN 16931, XRechnung, or ZUGFeRD.

**Out of scope / deferred:**

- Full EN 16931 Schematron / complete business-rule engines (remaining items such as EAS codes outside the documented subset, VAT-identifier checksums, card/mandate details, EXTENDED `#conformant#` profiles, …).
- Full OASIS UBL 2.1 XSD (TaxScheme, PayableAmount, and other official-required nodes that the current import subset schema does not demand).
- Peppol Access Point, KoSIT validator, XRechnung/ZUGFeRD certification, outbound XRechnung UBL, and EXTENDED `#conformant#` profiles.
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
