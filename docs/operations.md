# Operations

This package supplies accounting controls, not a complete compliance program.
The operator remains responsible for access management, infrastructure,
retention, backups, monitoring, and documented procedures. Installation alone
does not make a system GoBD-compliant or certified.

## Production baseline

- Use separate identities and least-privilege roles for the application,
  migrations, administration, backups, and audit storage.
- Define every Gate in `authorization.abilities`, or provide an
  `AccountingAuthorizer`. Authentication and hidden navigation are not access
  control.
- Keep attachments and audit anchors private and on storage with independently
  enforced versioning, retention, or immutability where required.
- Back up the database, private files, artifact sets, audit anchors, keys,
  configuration, and release identity together. Test restoration regularly.
- Run queues and the scheduler under supervision. Monitor failed imports,
  incomplete invoice issuance, SCA sessions, payment submissions, sync gaps,
  backups, and integrity checks.
- Record the package version/commit, migrations, dependencies, database engine,
  and relevant configuration for every release.

## Audit anchors and verification

Configure an anchor disk outside the normal database trust boundary:

```dotenv
ACCOUNTING_AUDIT_ANCHOR_DISK=audit-anchors
ACCOUNTING_AUDIT_ANCHOR_PREFIX=accounting/audit-anchors
ACCOUNTING_AUDIT_ANCHOR_REQUIRED=true
ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED=true
```

`ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED` is an operator assertion. Set it
only after verifying independent credentials, denied overwrite/delete access,
versioning or object lock, retention, backup, and restore.

Create anchors on a documented schedule and after important accounting or
release operations:

```bash
php artisan filament-accounting:audit-anchor --json
php artisan filament-accounting:verify --json
```

Both commands fail with a non-zero exit code on integrity failure. The current
verification report is schema version 2 and includes invoice evidence. Review
`pending` separately: preserved but incomplete imports and interrupted unposted
invoice generation can be warnings without being integrity failures. The
command is read-only and never repairs or regenerates evidence.

Portable evidence can be exported and verified offline:

```bash
php artisan filament-accounting:audit-export ENTITY_UUID exports/audit-evidence.json --json
php artisan filament-accounting:audit-verify-file exports/audit-evidence.json --json
php artisan filament-accounting:audit-export ENTITY_UUID exports/accounting.dataset --dataset --json
php artisan filament-accounting:audit-verify-file exports/accounting.dataset --json
```

The default audit-only format remains schema version 1. Dataset export defaults
to streaming schema version 2; `--dataset --legacy-json` remains available for
older consumers. Dataset export needs `pdo_sqlite`, protected temporary space,
and a supported snapshot connection: MySQL/MariaDB with InnoDB or SQLite.
Other drivers are rejected. It is an inspection transfer, not a backup, DATEV
export, full host restore image, digital signature, or independent third-party
validation. Host tables, credentials, TAN state, institute data, unreferenced
objects, and logo/template assets are excluded.

Retain the reported dataset hash and obtain an independent anchor or hash. A
local audit chain and package digest do not prevent a privileged database or
storage administrator from rewriting both history and local hashes.

## Retention and recovery

Do not cascade-delete issued documents, original attachments, posted journals,
or audit evidence. Corrections use retained invoice versions and reversals.
Purchase drafts are discarded with actor/reason evidence; their originals and
intake records remain available. No automatic disposal workflow is provided.

Purchase intake preserves private PDF/XML bytes and a manifest before parsing.
Open imports exposes incomplete processing for review. Changed or missing bytes
that were already marked preserved must be investigated and restored; retry
must not silently replace them. Outgoing invoices stage an authoritative PDF/XML
artifact set before file writes. **Complete invoice** resumes interrupted
issuance using the existing number and staged bytes. Include intake records,
artifact sets, staged contents, attachments, and anchors in backup and retention
procedures.

Storage writes and database commits are not inherently atomic. Preserve orphaned
objects for investigation; do not assume model guards prevent raw SQL,
privileged storage changes, or coordinated evidence rewrites. Laravel filesystem
APIs alone do not prove statutory retention or immutability.

## Catalog transfer

The catalog page provides **Import**, **Export**, and **Download import template**.
All require catalog-management permission and an active legal entity. XLSX is
the recommended/default format; XLS, CSV, and JSON are also supported.

The canonical spreadsheet/CSV header order is:

```text
sku;name;description;type;unit;quantity;sales_price;purchase_price;currency;tax_code;ean;active
```

Imports validate the complete file, show a preview, validate again at
confirmation, and write atomically. Non-empty SKUs identify records only within
the current legal entity; duplicate SKUs are rejected, empty SKUs create new
items, and names are not identity. Existing SKUs update by default, or can be
skipped. Files are limited to 10 MiB and 10,000 items. Exports include active
and inactive catalog items but no internal IDs or timestamps.

CSV is semicolon-delimited and exported as deterministic UTF-8 without BOM.
JSON uses the versioned `filament-accounting.catalog` envelope. Decimal and
money values are exact strings; SKU/EAN values must be text in Excel so leading
zeroes survive. Invalid encodings, formulas, unknown columns, unsupported units,
invalid tax codes, and malformed values are rejected. After upgrading, run
`php artisan migrate` for the nullable EAN and purchase-price columns.

This is catalog data transfer, not the accounting dataset export and not an
accounting-history or compliance archive. Catalog changes are still subject to
the host's authorization and release procedures.

## Sales invoices and recovery

Invoice PDFs use the package Blade view and Dompdf. Hosts can override
`resources/views/vendor/filament-accounting/documents/invoice.blade.php`.
Preview uses the same view but does not issue, post, allocate a number, or
archive files. Issued PDFs/XML are retained; generation and completion verify
existing artifacts instead of overwriting archived originals.

Drafts can be edited. Editing an issued invoice requires the relevant permission
and a non-empty reason and creates the next immutable version with the same
invoice number. Issuing that version creates corrected XML (type 384) and a PDF,
reverses the preceding journal/open item, and posts the replacement under the
existing period controls. Payment allocations must be reversed before
correction; only one correction targets an original at a time. Failed generation
or posting is resumed through **Complete invoice** without duplicating numbers
or reversals.

Direct debit can be selected for a draft, but issuance requires an active,
company/customer-owned mandate valid on the invoice date. Payment details are
frozen in the invoice snapshot; selecting the method does not submit a bank
collection. These controls support traceable correction, not blanket GoBD, VAT,
or e-invoice certification.

## Bank synchronization and scheduling

A truncated bank history is tracked with a catch-up frontier and drained oldest
first in `max_range_days` chunks. Repeated runs resume the gap; a green command
exit does not mean the whole requested range is covered. Review sync runs where
`requested_from_date` is set and monitor the reported remaining gap:

```bash
php artisan filament-accounting:sync-bank --transactions --from=2025-01-01
php artisan filament-accounting:banking-backlog --json
```

`banking-backlog` fails closed (non-zero exit) while catch-up markers, open sync
runs, pending intakes, or review-flagged statement lines remain. Use
`--continue` to drain catch-up and `--ack-run=` only to record that a failed or
evidence-gap run was reviewed — acknowledgement does not clear catch-up or
invent completeness. The Filament sync-backlog view and dashboard widget expose
the same inventory with continue/ack actions.

SCA remains a user action. After SCA completes a transaction sync chunk, catch-up
continues automatically under the account lock; a green single-chunk SCA result is
not completeness while `catch_up_from` remains. Competing syncs for the same account
fail closed (`stopped_for=concurrent`). Retain operational evidence of SCA resumes
and blocked concurrent attempts before making a completeness claim.

## Release checks and alerting

Run the repository quality gate before release:

```bash
composer check
php artisan filament-accounting:verify --json
php artisan filament-accounting:storage-integrity --json
```

Schedule verification and storage-integrity checks, retain their reports, and
alert on integrity failures and pending counts separately. Dataset exports can
hold the legal-entity lock and require temporary disk space; measure runtime,
lock duration, and storage latency on the reference host. Follow the schema and
release policy in [Installation](install.md) and the current limitations in
[GoBD readiness](gobd.md).
