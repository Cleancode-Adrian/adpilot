<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Planowany budżet dzienny (łącznie Google + Meta) – do pilnowania tempa wydatków
            $table->decimal('daily_budget', 10, 2)->nullable()->after('monthly_budget');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('daily_budget'));
    }
};
