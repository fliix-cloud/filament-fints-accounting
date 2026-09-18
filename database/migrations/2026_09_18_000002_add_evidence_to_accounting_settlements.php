<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settlements', function (Blueprint $table): void {
            $table->json('evidence')->nullable()->after('reverses_id');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_settlements', function (Blueprint $table): void {
            $table->dropColumn('evidence');
        });
    }
};
