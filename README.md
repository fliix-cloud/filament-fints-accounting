# filament-fints-accounting

Laravel 13 / Filament v5 accounting package with a first-party ledger, German-first invoicing, integrated FinTS banking, reconciliation, and e-invoice storage.

This repository is an **installable Composer package**, not a Laravel application.

## What it is

- Legal-entity scoped double-entry journal (`LedgerEngine`)
- Customers, suppliers, catalog, sales and purchase invoices, open items
- [Strict catalog import/export](docs/operations.md) with XLSX, XLS, CSV and JSON templates
- One canonical bank account and bank transaction model, direct FinTS synchronization, SEPA transfers/direct debits, mandates, and SCA
- Append-only bank source versions for pending, booked, changed, and reversed source states
- Direct assignment, partial settlement, true multi-target splits, and explainable local learning rules
- Versioned tax rates and internal posting rules; German 19%, 7%, 0%, historical 16%/5%, EU and export treatments
- Upload-first purchase invoices with a required business category and automatic internal ledger mapping
- German and English UI translations
- Exact money via `brick/money` (integer minor units, no floats)

Applications install and register only this package. The framework-free
[`nemiah/php-fints`](https://github.com/nemiah/phpFinTS) is the transitive,
framework-independent protocol dependency. All Laravel,
Filament, persistence, tenancy, and banking workflows live in this package.

Installing this package does not make a host “GoBD certified”. Compliance also depends on deployment, permissions, backups, retention, and procedure.

## Regional scope

Designed for Germany-first accounting and FinTS-enabled bank connections, with
European workflows such as SEPA and EU VAT treatments. FinTS is a
[German banking standard](https://www.hbci-zka.de/); support depends on the bank
and does not cover every European bank. U.S. banking and U.S. accounting/tax
workflows are outside the supported scope. Suitability depends on the bank and
accounting jurisdiction, not the user's nationality.

## Requirements

- PHP 8.3+
- Laravel 13
- Filament v5

## Installation

For the complete setup, including production permissions and first company setup,
see [Installation und Inbetriebnahme](docs/install.md).

Install the product package and its migrations:

```bash
composer config repositories.filament-fints-accounting vcs https://github.com/fliix-cloud/filament-fints-accounting.git
composer require fliix-cloud/filament-fints-accounting:dev-main nemiah/php-fints:@dev
php artisan filament-accounting:install --migrate --country=DE
php artisan filament-accounting:verify
```

For tamper-evident audit-chain anchors outside the application database, configure independently controlled immutable/versioned storage, then run:

```bash
php artisan filament-accounting:audit-anchor --json
php artisan filament-accounting:verify --json
php artisan filament-accounting:audit-export ENTITY_UUID exports/audit-evidence.json --json
php artisan filament-accounting:audit-verify-file exports/audit-evidence.json --json
```

See [Operations](docs/operations.md) for the required trust boundary,
configuration, scheduling, and residual risks.

Register the plugin on a Filament panel:

```php
use FilamentAccounting\FilamentAccountingPlugin;

$panel->plugin(
    FilamentAccountingPlugin::make()
);
```

The package uses one `LegalEntity` per application instance. `SingleLegalEntityResolver` resolves that company directly from the database; record ownership is never taken from an untrusted request parameter.

Configure `FINTS_PRODUCT_ID` before creating a bank connection. The integrated
commands are `filament-accounting:sync-institutes`,
`filament-accounting:sync-bank`, and `filament-accounting:cleanup-sca`.

The project is pre-release. The schema may change, including edits to base
migrations. Recreate **disposable development databases only** with
`php artisan migrate:fresh --seed`. Never use this command on retained accounting
data. Existing forward migrations do not establish a supported upgrade path
from every earlier development commit. See the [schema and release policy](docs/install.md).

## Package rename

The Composer package is now `fliix-cloud/filament-fints-accounting`. Existing hosts
should replace the old Composer requirement and update any local path repository.
The `FilamentAccounting` PHP namespace, `filament-accounting` configuration,
Artisan commands, routes, and view/translation namespaces remain unchanged.

## Documentation

- [Installation und Inbetriebnahme](docs/install.md) — installation, panel,
  permissions, company setup, and schema/release policy
- [Architecture](docs/architecture.md) — scope, boundaries, accounting rules,
  reconciliation, e-invoices, and extension points
- [Operations](docs/operations.md) — production responsibilities, audit anchors,
  retention, recovery, catalog transfer, and invoice operations
- [GoBD readiness](docs/gobd.md) — current controls, open gaps, and claim
  boundaries

## Development

```bash
composer install
composer check
vendor/bin/testbench serve
```

The workbench panel is available at `/admin`. On Windows, run
`scripts/setup-herd-demo.ps1` to link the package into the optional Herd demo.

## License

MIT. See [LICENSE](LICENSE).
