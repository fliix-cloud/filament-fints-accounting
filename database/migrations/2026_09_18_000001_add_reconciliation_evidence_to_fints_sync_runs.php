<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fints_sync_runs', function (Blueprint $table): void {
            $table->json('reconciliation_evidence')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('fints_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('reconciliation_evidence');
        });
    }
};
