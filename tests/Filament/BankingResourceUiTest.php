<?php

namespace FilamentAccounting\Tests\Filament;

use Filament\Actions\Action;
use Filament\Tables\Table;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankDirectDebitResource\Pages\ListBankDirectDebits;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource\Pages\ListBankTransfers;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource;
use FilamentAccounting\Filament\Resources\AccountingBankAccountResource\Pages\ListAccountingBankAccounts;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource\Pages\ListBankStatementLines;
use FilamentAccounting\Models\AccountingBankAccount;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class BankingResourceUiTest extends TestCase
{
    #[Test]
    public function bank_account_resource_has_translated_labels_and_sync_actions(): void
    {
        app()->setLocale('de');
        filament()->setCurrentPanel(filament()->getPanel('admin'));

        $table = AccountingBankAccountResource::table(Table::make(new ListAccountingBankAccounts));
        $actions = collect($table->getRecordActions())->keyBy(
            fn (Action $action): string => $action->getName(),
        );

        $this->assertSame('Bankkonto', AccountingBankAccountResource::getModelLabel());
        $this->assertSame('Bankkonten', AccountingBankAccountResource::getPluralModelLabel());
        $this->assertSame(['continueCatchUp', 'syncTransactions', 'syncBalance'], $actions->keys()->all());
        $this->assertSame('Catch-up fortsetzen', $actions->get('continueCatchUp')?->getLabel());
        $this->assertSame('Umsätze abrufen', $actions->get('syncTransactions')?->getLabel());
        $this->assertSame('Saldo abrufen', $actions->get('syncBalance')?->getLabel());
        $this->assertSame('Catch-up ab', $table->getColumn('catch_up_from')?->getLabel());
    }

    #[Test]
    public function transaction_page_has_a_combined_sync_action_and_a_resolved_empty_heading(): void
    {
        app()->setLocale('de');
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $account = $this->makeBankAccount($entity);

        $page = new ListBankStatementLines;
        $page->accountId = $account->getKey();
        $actions = collect((new ReflectionMethod($page, 'getHeaderActions'))->invoke($page))->keyBy(
            fn (Action $action): string => $action->getName(),
        );
        $table = BankStatementLineResource::table(Table::make($page));

        $this->assertSame(
            'Umsätze und Saldo abrufen',
            $actions->get('syncTransactionsAndBalance')?->getLabel(),
        );
        $this->assertSame('Keine Bankumsätze', $table->getEmptyStateHeading());
    }

    #[Test]
    public function bank_account_picker_does_not_repeat_an_iban_used_as_display_name(): void
    {
        $account = new AccountingBankAccount([
            'display_name' => 'DE89370400440532013000',
            'iban' => 'DE89 3704 0044 0532 0130 00',
        ]);

        $this->assertSame('DE89 3704 0044 0532 0130 00', $account->pickerLabel());

        $account->display_name = 'Geschäftskonto';
        $this->assertSame('DE89 3704 0044 0532 0130 00 (Geschäftskonto)', $account->pickerLabel());
    }

    #[Test]
    public function direct_debit_created_at_column_is_translated(): void
    {
        app()->setLocale('de');

        $table = BankDirectDebitResource::table(Table::make(new ListBankDirectDebits));

        $this->assertSame('Erstellt am', $table->getColumn('created_at')?->getLabel());
    }

    #[Test]
    public function transfers_cannot_resume_an_abandoned_authentication_from_the_list(): void
    {
        $table = BankTransferResource::table(Table::make(new ListBankTransfers));

        $this->assertSame(
            ['view', 'delete'],
            collect($table->getRecordActions())->map(fn (Action $action): string => $action->getName())->values()->all(),
        );
    }
}
