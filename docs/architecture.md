# Architecture

`fliix-cloud/filament-fints-accounting` is the Composer package name. The
package's internal Laravel name remains `filament-accounting`; its PSR-4
namespace is `FilamentAccounting\`. Laravel auto-discovers
`FilamentAccounting\FilamentAccountingServiceProvider` from `composer.json`.
Filament integration is provided by `FilamentAccountingPlugin`.

## Scope

The package is a Laravel 13 / Filament 5 accounting package with a Germany-first
profile. Its documented scope includes:

- legal-entity-scoped customers, suppliers, catalog items, sales and purchase
  invoices, open items, periods, reversals, and a first-party double-entry ledger;
- versioned tax and posting rules, with exact money handling through
  `brick/money`;
- FinTS account, balance, and transaction synchronization, SEPA transfers and
  direct debits, mandates, and SCA workflows;
- bank reconciliation with direct assignments, partial payments, splits, and
  explainable suggestions;
- private invoice attachments, structured e-invoices, invoice versions, and
  audit evidence; and
- catalog import/export in XLSX, XLS, CSV, and JSON formats.

Fixed assets, payroll, cash-register/TSE workflows, consolidation, foreign-
currency conversion, and complete foreign-tax advice are outside the current
scope. FinTS support is Germany-first and depends on the bank; it is not a
universal European banking integration.

## Package structure and boundaries

The source tree is organized around `Models`, `Ledger`, `Tax`, `Documents`,
`Banking`, `Reconciliation`, `Catalog`, `Export`, `Audit`, `Authorization`,
`Ownership`, `Services`, and `Filament`. `Filament` resources are the user
interface, not the accounting or security boundary. Business rules belong in
services and contracts.

`LegalEntity` is the reporting and integrity boundary. The default resolver
expects one company per application instance. The host resolves the actor and
authorization separately; request data does not select the company. Queue jobs
carry scalar identifiers and activate trusted context before loading records.

`AccountingBankAccount` and `BankStatementLine` are the canonical bank models.
FinTS imports into them, while material source changes are retained as
append-only source versions rather than silently changing posted accounting
data.

## Accounting rules

- Money uses integer minor units and exact decimal conversion; calculations do
  not rely on floating-point values.
- Postings use the legal entity's base currency. Foreign-currency conversion is
  not implemented and unsupported currency cases must be rejected.
- A posted journal has at least two non-zero lines and balanced debits and
  credits in transaction and base currency.
- Posting is idempotent per legal entity and idempotency key. Hard-closed
  periods reject new postings.
- Posted journals and issued document versions are immutable. Corrections use
  linked reversals and replacement versions rather than editing history.
- Payment state is derived from open items and active settlements. Tax and
  posting rules are versioned by effective date.

Purchase invoices start with private PDF/XML intake. The business category is
confirmed before registration and the package resolves the internal ledger
account. Source files and interrupted processing are retained for review; a
successful parse is not by itself an e-invoice conformity determination.

## Reconciliation and banking

A direct assignment consumes one complete bank transaction, even when it only
partially settles an invoice. A split is required when a transaction targets
multiple invoices, categories, or ledger purposes. Signed, currency-matched
allocations must equal the transaction exactly.

Finalization locks the relevant records, validates ownership and amounts, posts
one balanced journal, creates settlements, and marks the result posted in one
accounting transaction. Reversals restore accounting state without deleting
history. Suggestions are deterministic and explainable; they never post
automatically or cross legal entities.

## E-invoices, security, and audit

Structured invoices use `horstoeko/zugferd`. Generation is based on the issued
document snapshot. Local XML/PDF checks support validation but are not an
independent conformity certification.

Attachments use a private Laravel disk and content-based MIME detection.
Credentials, dialog state, SCA data, and resumable payment state are encrypted
or redacted. FinTS endpoints require HTTPS by default, and ambiguous payment
submissions are not retried automatically.

Critical actions are recorded in a per-company SHA-256 audit chain. Journal
posting stores a versioned, hashed snapshot of the persisted journal and lines.
Verification detects changed, missing, and unsealed postings; verified exports
refuse failed ledger, chain, audit-anchor, or evidence checks. External anchors
make later coordinated manipulation detectable only when they are stored outside
the application's normal database and permission boundary.

These controls support traceability but do not establish GoBD certification.
See [GoBD readiness](gobd.md) and [Operations](operations.md) for the remaining
technical and operational requirements.

## Extension points

Host applications may replace documented contracts for ownership, actor
resolution, tenancy activation, authorization, compliance profiles, audit-anchor
storage, accounting export, and e-invoice handling. The first-party ledger
remains behind `LedgerEngine`.
