<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // sales = sprzedaż w sklepie (ROAS), leads = pozyskiwanie kontaktów (koszt leada), mixed = oba
            $table->string('goal')->default('sales')->after('industry');
            // nazwy zdarzeń GA4 liczonych jako lead (formularz, rejestracja, telefon…)
            $table->json('lead_events')->nullable()->after('goal');
        });

        Schema::table('analytics_daily', function (Blueprint $table) {
            $table->decimal('key_events', 12, 2)->default(0)->after('revenue');
        });

        // Wybrane zdarzenia GA4 dzień po dniu (generate_lead, form_submit, sign_up…)
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('event_name');
            $table->unsignedBigInteger('count')->default(0);
            $table->unique(['client_id', 'date', 'event_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::table('analytics_daily', fn (Blueprint $t) => $t->dropColumn('key_events'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['goal', 'lead_events']));
    }
};
