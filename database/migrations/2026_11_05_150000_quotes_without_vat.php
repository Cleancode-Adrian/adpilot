<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firma nie jest płatnikiem VAT: nowe oferty domyślnie bez VAT (stawkę bierze Quote::newFor z ustawień),
 * a oferty jeszcze bez decyzji klienta (szkice, wysłane) – bez VAT i bez zdania o doliczaniu VAT.
 * Oferty zaakceptowane / odrzucone zostają bez zmian (to zapis tego, co klient zobaczył).
 */
return new class extends Migration
{
    private const OLD_TERMS = 'Ceny netto. Do cen należy doliczyć podatek VAT.';

    private const NEW_TERMS = 'Podane ceny są cenami końcowymi (sprzedawca nie jest płatnikiem VAT).';

    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->default(null)->change();
        });

        $open = DB::table('quotes')->whereIn('status', ['draft', 'sent']);
        (clone $open)->update(['vat_rate' => null]);
        foreach ((clone $open)->where('terms', 'like', '%'.self::OLD_TERMS.'%')->get(['id', 'terms']) as $q) {
            DB::table('quotes')->where('id', $q->id)->update(['terms' => str_replace(self::OLD_TERMS, self::NEW_TERMS, $q->terms)]);
        }
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->default(23)->change();
        });
    }
};
