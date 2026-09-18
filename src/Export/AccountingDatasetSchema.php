<?php

namespace FilamentAccounting\Export;

use FilamentAccounting\Exceptions\AuditEvidenceException;

/** Explicit column allowlist; schema changes require a reviewed export update. */
final class AccountingDatasetSchema
{
    public const REVISION = 2;

    private const ADDED_COLUMNS = [
        'accounting_legal_entities' => 'invoice_subtitle invoice_contact_name',
        'accounting_catalog_items' => 'ean purchase_price_minor',
        'accounting_documents' => 'invoice_version payment_method direct_debit_mandate_id payment_snapshot',
        'accounting_document_lines' => 'catalog_sku',
    ];

    /** @return array<string, string> */
    public static function columns(int $revision = self::REVISION): array
    {
        if (! in_array($revision, [1, self::REVISION], true)) {
            throw new AuditEvidenceException('Unsupported dataset schema revision.');
        }
        $columns = self::COLUMNS;
        if ($revision === 1) {
            foreach (self::ADDED_COLUMNS as $table => $added) {
                $columns[$table] = implode(' ', array_diff(explode(' ', $columns[$table]), explode(' ', $added)));
            }
        }

        return $columns;
    }

    public const POLYMORPHIC = [
        'accounting_journal_entries' => ['source_type', 'source_id', ['document' => 'accounting_documents', 'reconciliation' => 'accounting_reconciliations', 'reversal' => 'accounting_journal_entries']],
        'accounting_reconciliation_learning_rules' => ['target_type', 'target_id', ['party' => 'accounting_parties', 'posting_rule' => 'accounting_posting_rules', 'ledger_account' => 'accounting_ledger_accounts']],
    ];

    /** @return array<string, string> */
    public static function references(int $revision = self::REVISION): array
    {
        self::columns($revision);

        return ($revision === 1 ? [] : ['accounting_documents.direct_debit_mandate_id' => 'fints_direct_debit_mandates']) + self::REFERENCES + [
            'accounting_document_lines.tax_rule_version_id' => 'accounting_tax_rule_versions',
            'accounting_document_lines.ledger_account_id' => 'accounting_ledger_accounts',
            'accounting_document_lines.catalog_item_id' => 'accounting_catalog_items',
            'accounting_journal_entries.posting_rule_version_id' => 'accounting_posting_rule_versions',
            'accounting_journal_entries.reverses_id' => 'accounting_journal_entries',
            'accounting_journal_lines.tax_rule_version_id' => 'accounting_tax_rule_versions',
            'accounting_settlements.reverses_id' => 'accounting_settlements',
            'accounting_reconciliations.reverses_id' => 'accounting_reconciliations',
            'accounting_reconciliation_splits.open_item_id' => 'accounting_open_items',
            'accounting_reconciliation_splits.posting_rule_version_id' => 'accounting_posting_rule_versions',
            'accounting_reconciliation_splits.ledger_account_id' => 'accounting_ledger_accounts',
            'accounting_reconciliation_splits.tax_rule_version_id' => 'accounting_tax_rule_versions',
        ];
    }

    public const CHILDREN = [
        'accounting_party_addresses' => ['party_id', 'accounting_parties'],
        'accounting_party_tax_ids' => ['party_id', 'accounting_parties'],
        'accounting_tax_rule_versions' => ['tax_code_id', 'accounting_tax_codes'],
        'accounting_posting_rule_versions' => ['posting_rule_id', 'accounting_posting_rules'],
        'accounting_document_lines' => ['document_id', 'accounting_documents'],
        'accounting_journal_lines' => ['journal_entry_id', 'accounting_journal_entries'],
        'accounting_reconciliation_splits' => ['reconciliation_id', 'accounting_reconciliations'],
    ];

    public const COLUMNS = [
        'accounting_legal_entities' => 'id uuid legal_name trading_name address_line1 address_line2 postal_code city region country_code tax_number vat_id email phone website base_currency locale timezone fiscal_year_start_month accounting_basis vat_method compliance_profile_key invoice_bank_name invoice_iban invoice_bic default_payment_terms_days invoice_logo_path invoice_template_key invoice_template_version state created_at updated_at invoice_subtitle invoice_contact_name',
        'accounting_parties' => 'id uuid legal_entity_id kind is_customer is_supplier legal_name display_name country_code email phone payment_terms_days default_currency external_reference is_active created_at updated_at invoice_email',
        'accounting_party_addresses' => 'id party_id line1 line2 postal_code city region country_code is_primary created_at updated_at address_role',
        'accounting_party_tax_ids' => 'id party_id type number country_code created_at updated_at',
        'accounting_catalog_items' => 'id uuid legal_entity_id sku type name description unit default_quantity default_unit_price_minor currency default_account_role default_tax_code is_active created_at updated_at ean purchase_price_minor',
        'accounting_ledger_accounts' => 'id uuid legal_entity_id code name type normal_balance currency parent_id is_active valid_from valid_to created_at updated_at',
        'accounting_account_role_assignments' => 'id legal_entity_id role ledger_account_id created_at updated_at',
        'accounting_tax_codes' => 'id uuid legal_entity_id code name direction is_active created_at updated_at',
        'accounting_tax_rule_versions' => 'id uuid tax_code_id valid_from valid_to rate_bp recoverable category reason export_mapping created_at updated_at',
        'accounting_posting_rules' => 'id uuid legal_entity_id code label explanation compliance_profile_key is_active created_at updated_at',
        'accounting_posting_rule_versions' => 'id uuid posting_rule_id version valid_from valid_to direction requires_receipt tax_code account_mappings line_templates created_at updated_at',
        'accounting_periods' => 'id uuid legal_entity_id fiscal_year period_number starts_on ends_on state closed_at closed_by_type closed_by_id reopened_at reopened_by_type reopened_by_id reopen_reason created_at updated_at',
        'accounting_document_sequences' => 'id legal_entity_id document_type fiscal_year next_number prefix created_at updated_at',
        'accounting_documents' => 'id uuid legal_entity_id type direction number supplier_invoice_number document_status posting_status party_id party_snapshot legal_entity_snapshot issue_date receipt_date supply_date due_date payment_terms_days currency exchange_rate net_minor tax_minor gross_minor e_invoice_meta corrected_document_id idempotency_key created_by_type created_by_id issued_by_type issued_by_id issued_at posted_at created_at updated_at invoice_version payment_method direct_debit_mandate_id payment_snapshot',
        'accounting_document_lines' => 'id document_id position description quantity unit unit_price_minor discount net_minor tax_code tax_rule_version_id tax_rate_bp tax_category tax_reason tax_recoverable tax_export_mapping tax_minor gross_minor account_role ledger_account_id catalog_item_id classification_code classification_confirmed tax_confirmed imported_tax_code source_line_hash source_line_index service_from service_to created_at updated_at catalog_sku',
        'accounting_journal_entries' => 'id uuid legal_entity_id sequence period_id period_snapshot posted_on status source_type source_id description currency base_currency exchange_rate posting_rule_version_id reverses_id idempotency_key posted_by_type posted_by_id posted_at created_at updated_at',
        'accounting_journal_lines' => 'id journal_entry_id ledger_account_id account_snapshot position debit_minor credit_minor currency base_debit_minor base_credit_minor description tax_code tax_rule_version_id created_at updated_at',
        'accounting_open_items' => 'id uuid legal_entity_id document_id party_id kind currency original_minor due_on is_reversed created_at updated_at',
        'accounting_settlements' => 'id uuid legal_entity_id open_item_id journal_entry_id amount_minor currency is_reversed reverses_id created_at updated_at',
        'accounting_attachments' => 'id uuid legal_entity_id attachable_type attachable_id original_filename mime_type size sha256 disk path source_type structured_payload meta uploaded_by_type uploaded_by_id created_at updated_at',
        'accounting_invoice_artifact_sets' => 'id legal_entity_id document_id disk manifest snapshot meta evidence_sha256 pdf_base64 xml preserved_roles completed_at created_at updated_at',
        'accounting_purchase_invoice_intakes' => 'id uuid legal_entity_id identity disk files preserved_files status last_error document_id created_by_type created_by_id preserved_at created_at updated_at',
        'accounting_party_bank_accounts' => 'id uuid legal_entity_id party_id holder_name iban bic is_primary created_at updated_at',
        'fints_bank_connections' => 'id uuid legal_entity_id display_name bank_code status created_at updated_at',
        'accounting_bank_accounts' => 'id uuid legal_entity_id bank_connection_id ledger_account_id display_name iban bic currency source external_account_id fingerprint account_number sub_account bank_code product_name account_holder_name is_available is_enabled is_active booked_balance_minor pending_balance_minor credit_line_minor available_amount_minor balance_at last_balance_sync_at last_transaction_sync_at catch_up_from created_at updated_at',
        'accounting_bank_import_runs' => 'id uuid legal_entity_id bank_account_id source upserted_count cursor meta created_at updated_at',
        'accounting_bank_statement_lines' => 'id uuid legal_entity_id bank_account_id source external_id source_account_external_id amount_minor currency booking_date value_date source_status counterparty_name counterparty_iban counterparty_account purpose end_to_end_id payment_reference source_payload source_hash source_created_at source_updated_at first_imported_at last_imported_at needs_review review_reason created_at updated_at',
        'fints_direct_debit_creditor_profiles' => 'id uuid legal_entity_id name creditor_identifier creditor_identifier_normalized street building_number postal_code city country is_default created_at updated_at',
        'fints_direct_debit_mandates' => 'id uuid legal_entity_id party_id party_bank_account_id creditor_profile_id reference reference_normalized scheme mandate_type debtor_name debtor_iban debtor_bic debtor_street debtor_building_number debtor_postal_code debtor_city debtor_country signed_on status debtor_bank_confirmed_at first_used_at last_used_at created_at updated_at',
        'fints_bank_transfers' => 'id uuid legal_entity_id bank_connection_id accounting_bank_account_id idempotency_key recipient_name recipient_iban recipient_bic amount_minor currency purpose requested_execution_date end_to_end_id type status bank_status_text error_code error_message submitted_at initiated_by_type initiated_by_id created_at updated_at',
        'fints_bank_direct_debits' => 'id uuid legal_entity_id bank_connection_id accounting_bank_account_id creditor_profile_id direct_debit_mandate_id idempotency_key sepa_message_id payment_information_id creditor_name creditor_identifier creditor_street creditor_building_number creditor_postal_code creditor_city creditor_country debtor_name debtor_iban debtor_bic debtor_street debtor_building_number debtor_postal_code debtor_city debtor_country amount_minor currency purpose mandate_id mandate_signed_on sequence_type scheme requested_collection_date end_to_end_id status bank_status_text error_code error_message submitted_at initiated_by_type initiated_by_id created_at updated_at',
        'fints_sync_runs' => 'id uuid legal_entity_id bank_connection_id accounting_bank_account_id type status from_date to_date requested_from_date item_count error_code error_message reconciliation_evidence started_at finished_at created_at updated_at',
        'accounting_bank_transaction_source_versions' => 'id legal_entity_id bank_transaction_id import_run_id version source_id source_fingerprint source_status normalized_payload raw_payload source_hash recorded_at created_at updated_at',
        'accounting_reconciliations' => 'id uuid legal_entity_id statement_line_id status journal_entry_id version reverses_id idempotency_key actor_type actor_id finalized_at reason match_meta created_at updated_at',
        'accounting_reconciliation_splits' => 'id reconciliation_id purpose amount_minor currency open_item_id posting_rule_version_id ledger_account_id reason created_at updated_at tax_rule_version_id',
        'accounting_reconciliation_learning_rules' => 'id uuid legal_entity_id direction match_type match_value target_type target_id target_label confirmed_count last_confirmed_at is_active created_at updated_at',
    ];

    public const REFERENCES = [
        'accounting_parties.legal_entity_id' => 'accounting_legal_entities',
        'accounting_party_addresses.party_id' => 'accounting_parties',
        'accounting_party_tax_ids.party_id' => 'accounting_parties',
        'accounting_catalog_items.legal_entity_id' => 'accounting_legal_entities',
        'accounting_ledger_accounts.legal_entity_id' => 'accounting_legal_entities',
        'accounting_ledger_accounts.parent_id' => 'accounting_ledger_accounts',
        'accounting_account_role_assignments.legal_entity_id' => 'accounting_legal_entities',
        'accounting_account_role_assignments.ledger_account_id' => 'accounting_ledger_accounts',
        'accounting_tax_codes.legal_entity_id' => 'accounting_legal_entities',
        'accounting_tax_rule_versions.tax_code_id' => 'accounting_tax_codes',
        'accounting_posting_rules.legal_entity_id' => 'accounting_legal_entities',
        'accounting_posting_rule_versions.posting_rule_id' => 'accounting_posting_rules',
        'accounting_periods.legal_entity_id' => 'accounting_legal_entities',
        'accounting_document_sequences.legal_entity_id' => 'accounting_legal_entities',
        'accounting_documents.legal_entity_id' => 'accounting_legal_entities',
        'accounting_documents.party_id' => 'accounting_parties',
        'accounting_documents.corrected_document_id' => 'accounting_documents',
        'accounting_document_lines.document_id' => 'accounting_documents',
        'accounting_journal_entries.legal_entity_id' => 'accounting_legal_entities',
        'accounting_journal_entries.period_id' => 'accounting_periods',
        'accounting_journal_lines.journal_entry_id' => 'accounting_journal_entries',
        'accounting_journal_lines.ledger_account_id' => 'accounting_ledger_accounts',
        'accounting_open_items.legal_entity_id' => 'accounting_legal_entities',
        'accounting_open_items.document_id' => 'accounting_documents',
        'accounting_open_items.party_id' => 'accounting_parties',
        'accounting_settlements.legal_entity_id' => 'accounting_legal_entities',
        'accounting_settlements.open_item_id' => 'accounting_open_items',
        'accounting_settlements.journal_entry_id' => 'accounting_journal_entries',
        'accounting_attachments.legal_entity_id' => 'accounting_legal_entities',
        'accounting_invoice_artifact_sets.legal_entity_id' => 'accounting_legal_entities',
        'accounting_invoice_artifact_sets.document_id' => 'accounting_documents',
        'accounting_purchase_invoice_intakes.legal_entity_id' => 'accounting_legal_entities',
        'accounting_purchase_invoice_intakes.document_id' => 'accounting_documents',
        'accounting_party_bank_accounts.legal_entity_id' => 'accounting_legal_entities',
        'accounting_party_bank_accounts.party_id' => 'accounting_parties',
        'fints_bank_connections.legal_entity_id' => 'accounting_legal_entities',
        'accounting_bank_accounts.legal_entity_id' => 'accounting_legal_entities',
        'accounting_bank_accounts.bank_connection_id' => 'fints_bank_connections',
        'accounting_bank_accounts.ledger_account_id' => 'accounting_ledger_accounts',
        'accounting_bank_import_runs.legal_entity_id' => 'accounting_legal_entities',
        'accounting_bank_import_runs.bank_account_id' => 'accounting_bank_accounts',
        'accounting_bank_statement_lines.legal_entity_id' => 'accounting_legal_entities',
        'accounting_bank_statement_lines.bank_account_id' => 'accounting_bank_accounts',
        'fints_direct_debit_creditor_profiles.legal_entity_id' => 'accounting_legal_entities',
        'fints_direct_debit_mandates.legal_entity_id' => 'accounting_legal_entities',
        'fints_direct_debit_mandates.party_id' => 'accounting_parties',
        'fints_direct_debit_mandates.party_bank_account_id' => 'accounting_party_bank_accounts',
        'fints_direct_debit_mandates.creditor_profile_id' => 'fints_direct_debit_creditor_profiles',
        'fints_bank_transfers.legal_entity_id' => 'accounting_legal_entities',
        'fints_bank_transfers.bank_connection_id' => 'fints_bank_connections',
        'fints_bank_transfers.accounting_bank_account_id' => 'accounting_bank_accounts',
        'fints_bank_direct_debits.legal_entity_id' => 'accounting_legal_entities',
        'fints_bank_direct_debits.bank_connection_id' => 'fints_bank_connections',
        'fints_bank_direct_debits.accounting_bank_account_id' => 'accounting_bank_accounts',
        'fints_bank_direct_debits.creditor_profile_id' => 'fints_direct_debit_creditor_profiles',
        'fints_bank_direct_debits.direct_debit_mandate_id' => 'fints_direct_debit_mandates',
        'fints_sync_runs.legal_entity_id' => 'accounting_legal_entities',
        'fints_sync_runs.bank_connection_id' => 'fints_bank_connections',
        'fints_sync_runs.accounting_bank_account_id' => 'accounting_bank_accounts',
        'accounting_bank_transaction_source_versions.legal_entity_id' => 'accounting_legal_entities',
        'accounting_bank_transaction_source_versions.bank_transaction_id' => 'accounting_bank_statement_lines',
        'accounting_bank_transaction_source_versions.import_run_id' => 'accounting_bank_import_runs',
        'accounting_reconciliations.legal_entity_id' => 'accounting_legal_entities',
        'accounting_reconciliations.statement_line_id' => 'accounting_bank_statement_lines',
        'accounting_reconciliations.journal_entry_id' => 'accounting_journal_entries',
        'accounting_reconciliation_splits.reconciliation_id' => 'accounting_reconciliations',
        'accounting_reconciliation_learning_rules.legal_entity_id' => 'accounting_legal_entities',
    ];
}
