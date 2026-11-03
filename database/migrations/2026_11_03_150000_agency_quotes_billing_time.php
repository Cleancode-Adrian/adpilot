<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Agencja: czas pracy przy zadaniach, rozliczenia i odnowienia, oferty z akceptacją online. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_tasks', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->nullable()->after('note'); // czas pracy
        });

        // Abonamenty, domeny, hostingi, licencje – z datą kolejnej faktury / odnowienia
        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                      // subscription | domain | hosting | license | other
            $table->string('name');
            $table->string('provider', 120)->nullable();     // np. OVH, home.pl, Elementor
            $table->decimal('amount', 12, 2)->nullable();    // kwota netto za okres
            $table->char('currency', 3)->default('PLN');
            $table->string('period', 10)->default('yearly'); // monthly | quarterly | yearly | once
            $table->date('next_date')->nullable();           // kolejna faktura / odnowienie / wygaśnięcie
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('remind_days')->default(14);
            $table->date('reminded_for')->nullable();        // dla której daty wysłano już przypomnienie
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['active', 'next_date']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40)->unique();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->text('terms')->nullable();
            $table->string('status', 12)->default('draft'); // draft | sent | accepted | rejected
            $table->date('valid_until')->nullable();
            $table->char('currency', 3)->default('PLN');
            $table->decimal('vat_rate', 5, 2)->nullable()->default(23); // null = zwolniony z VAT
            $table->string('token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_name', 150)->nullable();
            $table->string('decided_ip', 45)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->default('szt.');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('recurring', 10)->nullable(); // null = jednorazowo | monthly | yearly
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('billing_items');
        Schema::table('care_tasks', fn (Blueprint $t) => $t->dropColumn('minutes'));
    }
};
