<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dane do faktury (nabywca) – klient i agencja. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('billing_name')->nullable()->after('contact_phone');    // pełna nazwa firmy na fakturze
            $table->string('tax_id', 20)->nullable()->after('billing_name');       // NIP
            $table->string('billing_street')->nullable()->after('tax_id');
            $table->string('billing_post_code', 12)->nullable()->after('billing_street');
            $table->string('billing_city', 100)->nullable()->after('billing_post_code');
            $table->string('billing_email')->nullable()->after('billing_city');     // e-mail do faktur (gdy inny niż kontaktowy)
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('billing_name')->nullable()->after('tax_id');
            $table->string('billing_street')->nullable()->after('billing_name');
            $table->string('billing_post_code', 12)->nullable()->after('billing_street');
            $table->string('billing_city', 100)->nullable()->after('billing_post_code');
            $table->string('billing_email')->nullable()->after('billing_city');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['billing_name', 'tax_id', 'billing_street', 'billing_post_code', 'billing_city', 'billing_email']));
        Schema::table('agencies', fn (Blueprint $t) => $t->dropColumn(['billing_name', 'billing_street', 'billing_post_code', 'billing_city', 'billing_email']));
    }
};
