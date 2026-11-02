<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klient najpierw, usługi potem: klient może mieć reklamy, opiekę nad stroną albo oba.
 * Do tego dane kontaktowe i adres panelu logowania strony.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('has_ads')->default(false)->after('website_url');
            $table->boolean('has_care')->default(false)->after('has_ads');
            $table->string('admin_url', 500)->nullable()->after('has_care');
            $table->string('contact_name', 150)->nullable()->after('contact_email');
            $table->string('contact_phone', 50)->nullable()->after('contact_name');
        });

        // Dotychczasowi klienci byli klientami reklamowymi; opieka – jeśli mają już plan
        DB::table('clients')->update(['has_ads' => true]);
        DB::table('clients')->whereIn('id', DB::table('care_templates')->select('client_id'))->update(['has_care' => true]);
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['has_ads', 'has_care', 'admin_url', 'contact_name', 'contact_phone']));
    }
};
