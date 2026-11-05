<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Agencja: marka do raportów white label (logo, kolor, stopka) i stawka godzinowa do rozliczeń. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('white_label');          // ścieżka w storage/app/public
            $table->string('brand_color', 7)->nullable()->after('logo');
            $table->string('report_footer', 500)->nullable()->after('brand_color');
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('report_footer'); // netto za godzinę pracy
            $table->char('currency', 3)->default('PLN')->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['logo', 'brand_color', 'report_footer', 'hourly_rate', 'currency']);
        });
    }
};
