<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Szczegółowy raport audytu: podstrony, uwagi wg ważności (pilne / średnie / drobne), propozycje rozbudowy, checklista. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_audits', function (Blueprint $table) {
            $table->json('report')->nullable()->after('areas');
        });
    }

    public function down(): void
    {
        Schema::table('web_audits', fn (Blueprint $t) => $t->dropColumn('report'));
    }
};
