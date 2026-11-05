<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bez integracji z Fakturownią: faktury wystawiasz poza panelem, w panelu zostaje ręczne „Zafakturowano / Rozliczono”. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('care_tasks', 'invoice_id')) {
            Schema::table('care_tasks', fn (Blueprint $t) => $t->dropColumn('invoice_id'));
        }
        Schema::dropIfExists('invoices');

        // zapisane ustawienia (w tym zaszyfrowany kod API) nie są już potrzebne
        DB::table('settings')->whereIn('key', ['fakturownia_domain', 'fakturownia_token', 'invoice_kind', 'invoice_exempt_basis', 'invoice_payment_days'])->delete();
        Cache::forget('adpilot.settings');
    }

    public function down(): void
    {
        Schema::table('care_tasks', fn (Blueprint $t) => $t->foreignId('invoice_id')->nullable()->after('invoiced_at'));
    }
};
