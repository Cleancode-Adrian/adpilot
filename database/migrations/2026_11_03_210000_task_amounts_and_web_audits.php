<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kwoty przy zadaniach (prace dodatkowe do faktury) i audyty dowolnych stron (potencjalni klienci). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_tasks', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('minutes'); // kwota netto dla klienta
            $table->timestamp('invoiced_at')->nullable()->after('amount');
        });

        Schema::create('web_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 500);
            $table->string('name', 150)->nullable();   // firma (opcjonalnie)
            $table->string('email', 190)->nullable();  // kontakt (opcjonalnie)
            $table->string('status', 10)->default('pending'); // pending | running | done | failed
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('areas')->nullable();         // seo / tracking / speed / security – wynik i problemy
            $table->text('error')->nullable();
            $table->string('token', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_audits');
        Schema::table('care_tasks', fn (Blueprint $t) => $t->dropColumn(['amount', 'invoiced_at']));
    }
};
