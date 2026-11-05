<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Praca agencji jednoosobowej:
 * stoper przy zadaniach, zgłoszenia od klientów / agencji, przypomnienia o kontakcie (oferty, audyty),
 * faktury (Fakturownia), Google Search Console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_tasks', function (Blueprint $table) {
            $table->timestamp('timer_started_at')->nullable()->after('minutes');   // stoper: kiedy włączono
            $table->string('source', 20)->nullable()->after('care_template_id');    // request | followup | null
            $table->json('requester')->nullable()->after('description');           // zgłaszający: imię, e-mail, telefon
            $table->foreignId('invoice_id')->nullable()->after('invoiced_at');
        });

        // Link do formularza zgłoszeń (osobny od linku do raportów)
        Schema::table('clients', function (Blueprint $table) {
            $table->string('request_token', 48)->nullable()->unique()->after('report_token');
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('request_token', 48)->nullable()->unique()->after('name');
        });

        // Przypomnienia o kontakcie: kiedy następny raz przypomnieć, czy wyłączone
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('followup_at')->nullable()->after('decided_at');
            $table->boolean('followup_off')->default(false)->after('followup_at');
        });
        Schema::table('web_audits', function (Blueprint $table) {
            $table->timestamp('followup_at')->nullable()->after('finished_at');
            $table->boolean('followup_off')->default(false)->after('followup_at');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20)->default('fakturownia');
            $table->string('external_id', 40)->nullable();
            $table->string('number', 60)->nullable();
            $table->string('kind', 20)->nullable();
            $table->string('buyer_name')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->char('currency', 3)->default('PLN');
            $table->date('issue_date')->nullable();
            $table->date('payment_to')->nullable();
            $table->string('view_url', 500)->nullable();
            $table->string('status', 12)->default('issued'); // issued | sent | paid
            $table->json('items')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('search_console_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('position', 6, 2)->nullable();
            $table->unique(['client_id', 'date']);
        });
        // Najlepsze frazy i strony z ostatnich 28 dni (zastępowane przy każdej synchronizacji)
        Schema::create('search_console_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 5);   // query | page
            $table->string('key', 500);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('position', 6, 2)->nullable();
            $table->unsignedInteger('prev_clicks')->nullable();       // poprzednie 28 dni
            $table->decimal('prev_position', 6, 2)->nullable();
            $table->index(['client_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_console_rows');
        Schema::dropIfExists('search_console_daily');
        Schema::dropIfExists('invoices');
        Schema::table('web_audits', fn (Blueprint $t) => $t->dropColumn(['followup_at', 'followup_off']));
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn(['followup_at', 'followup_off']));
        Schema::table('agencies', fn (Blueprint $t) => $t->dropColumn('request_token'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('request_token'));
        Schema::table('care_tasks', fn (Blueprint $t) => $t->dropColumn(['timer_started_at', 'source', 'requester', 'invoice_id']));
    }
};
