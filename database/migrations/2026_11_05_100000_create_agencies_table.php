<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Agencje (zleceniodawcy) – strony obsługiwane na zlecenie agencji przypisane do niej. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('website_url')->nullable();
            $table->string('tax_id', 20)->nullable();          // NIP
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->boolean('white_label')->default(false);    // klient końcowy nie może wiedzieć, kto wykonuje pracę
            $table->text('terms')->nullable();                 // warunki współpracy: stawki, terminy płatności, zakres
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
        });
        Schema::dropIfExists('agencies');
    }
};
