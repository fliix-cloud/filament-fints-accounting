<?php

use FilamentAccounting\Audit\FilesystemAuditAnchorStore;
use FilamentAccounting\Authorization\DefaultAccountingAuthorizer;
use FilamentAccounting\Compliance\GenericComplianceProfile;
use FilamentAccounting\Compliance\Germany\GermanComplianceProfile;
use FilamentAccounting\Ownership\AuthenticatedUserAccountingActorResolver;
use FilamentAccounting\Ownership\NullAccountingTenancyContextActivator;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;

return [
    'demo' => [
        'profile_path' => env('ACCOUNTING_DEMO_PROFILE'),
    ],
    'database' => [
        'connection' => env('ACCOUNTING_DB_CONNECTION'),
    ],

    'storage' => [
        'disk' => env('ACCOUNTING_DISK', 'local'),
        'attachments_directory' => 'accounting/attachments',
        'maximum_attachment_bytes' => 15 * 1024 * 1024,
    ],

    'company' => [
        'country' => env('ACCOUNTING_COUNTRY', 'DE'),
    ],

    'money' => [
        'rounding_mode' => 'half_up',
        'line_rounding' => 'line',
    ],

    'features' => [
        'dashboard' => true,
        'customers' => true,
        'suppliers' => true,
        'catalog' => true,
        'sales_invoices' => true,
        'purchase_invoices' => true,
        'bank_reconciliation' => true,
        'journal' => true,
        'chart_of_accounts' => false,
        'tax_and_posting_rules' => true,
        'settings' => true,
        'audit' => false,
    ],

    'ownership' => [
        'entity_resolver' => SingleLegalEntityResolver::class,
        'required' => true,
    ],

    'actor' => [
        'resolver' => AuthenticatedUserAccountingActorResolver::class,
    ],

    'tenancy' => [
        'context_activator' => NullAccountingTenancyContextActivator::class,
    ],

    'authorization' => [
        'authorizer' => DefaultAccountingAuthorizer::class,
        'abilities' => [
            'view' => 'accounting.view',
            'manage_parties' => 'accounting.manage-parties',
            'manage_catalog' => 'accounting.manage-catalog',
            'create_draft_invoices' => 'accounting.invoices.draft',
            'issue_invoices' => 'accounting.invoices.issue',
            'register_purchase_invoices' => 'accounting.invoices.register-purchase',
            'discard_purchase_invoices' => 'accounting.invoices.discard-purchase',
            'post_documents' => 'accounting.documents.post',
            'view_bank' => 'accounting.bank.view',
            'manage_bank_connections' => 'accounting.bank.manage-connections',
            'sync_bank' => 'accounting.bank.sync',
            'create_bank_transfer' => 'accounting.bank.transfer.create',
            'create_bank_direct_debit' => 'accounting.bank.direct-debit.create',
            'confirm_bank_sca' => 'accounting.bank.sca.confirm',
            'draft_reconciliation' => 'accounting.reconciliation.draft',
            'finalize_reconciliation' => 'accounting.reconciliation.finalize',
            'reverse_reconciliation' => 'accounting.reconciliation.reverse',
            'view_journal' => 'accounting.journal.view',
            'create_manual_journal_drafts' => 'accounting.journal.draft',
            'post_manual_journals' => 'accounting.journal.post',
            'manage_chart' => 'accounting.chart.manage',
            'close_periods' => 'accounting.periods.close',
            'reopen_periods' => 'accounting.periods.reopen',
            'view_audit' => 'accounting.audit.view',
            'export_audit' => 'accounting.audit.export',
            'manage_settings' => 'accounting.settings.manage',
        ],
    ],

    'compliance' => [
        'profiles' => [
            'generic' => GenericComplianceProfile::class,
            'DE' => GermanComplianceProfile::class,
        ],
        'default' => 'generic',
    ],

    'audit' => [
        'application_version' => env('ACCOUNTING_RELEASE_VERSION'),
        'application_commit' => env('ACCOUNTING_RELEASE_COMMIT'),
        'configuration_snapshot_id' => env('ACCOUNTING_CONFIGURATION_SNAPSHOT_ID'),
        'anchor' => [
            'store' => FilesystemAuditAnchorStore::class,
            'disk' => env('ACCOUNTING_AUDIT_ANCHOR_DISK', 'local'),
            'prefix' => env('ACCOUNTING_AUDIT_ANCHOR_PREFIX', 'accounting/audit-anchors'),
            'required' => env('ACCOUNTING_AUDIT_ANCHOR_REQUIRED', false),

            // This is an explicit host assertion. The package cannot infer object lock,
            // retention, versioning, or independent permissions from Laravel's API.
            'immutable_storage_attested' => env('ACCOUNTING_AUDIT_ANCHOR_STORAGE_ATTESTED', false),
        ],
    ],

    'banking' => [
        'fints' => [
            'institutes' => [
                'url' => env('FINTS_INSTITUTES_URL', 'https://raw.githubusercontent.com/hbci4j/hbci4java/master/src/main/resources/blz.properties'),
                'timeout' => (int) env('FINTS_INSTITUTES_TIMEOUT', 30),
            ],
            'product' => [
                'id' => env('FINTS_PRODUCT_ID', ''),
                'version' => env('FINTS_PRODUCT_VERSION'),
                'derive_version_from_package' => true,
                'user_agent' => env('FINTS_USER_AGENT', 'filament-accounting'),
            ],
            'sync' => [
                'initial_lookback_days' => (int) env('FINTS_SYNC_LOOKBACK_DAYS', 90),
                'incremental_overlap_days' => (int) env('FINTS_SYNC_OVERLAP_DAYS', 3),
                'max_range_days' => (int) env('FINTS_SYNC_MAX_RANGE_DAYS', 90),
                // Keep calling sync for the same account until catch_up_from is
                // cleared or this chunk budget is exhausted (SCA still aborts).
                'auto_drain' => (bool) env('FINTS_SYNC_AUTO_DRAIN', true),
                'max_drain_chunks' => (int) env('FINTS_SYNC_MAX_DRAIN_CHUNKS', 20),
                'use_queue' => (bool) env('FINTS_SYNC_USE_QUEUE', false),
                'queue' => env('FINTS_QUEUE', 'default'),
                'retention_days' => (int) env('FINTS_RETENTION_DAYS', 30),
            ],
            'features' => [
                'accounts' => true,
                'balances' => true,
                'transactions' => true,
                'transfers' => true,
                'direct_debits' => true,
                'realtime_transfers' => true,
                'international_transfers' => false,
                'statement_xml' => false,
                'holdings' => false,
            ],
            'security' => [
                'https_only' => true,
                'allow_private_endpoints' => (bool) env('FINTS_ALLOW_PRIVATE_ENDPOINTS', false),
                'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('FINTS_ALLOWED_HOSTS', ''))))),
                'sca_ttl_minutes' => (int) env('FINTS_SCA_TTL_MINUTES', 30),
                'min_poll_seconds' => 2,
                'sensitive_logging' => false,
                'protocol_debug' => (bool) env('FINTS_PROTOCOL_DEBUG', false),
            ],
            'logging' => [
                'channel' => env('FINTS_LOG_CHANNEL'),
            ],
        ],
    ],

    'e_invoice' => [
        'default_profile' => 'en16931',
        'generate_on_issue' => true,
    ],
];
