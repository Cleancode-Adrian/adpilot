<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audyt zbiorczy: lista stron (wklejona albo z arkusza Google) audytowana po kolei, z jednym wspólnym raportem. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_batches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('status', 10)->default('draft'); // draft | running | paused | done
            $table->string('source', 500)->nullable();      // link do arkusza / dokumentu, z którego wczytano listę
            $table->json('options')->nullable();            // ai: przegląd treści przez AI
            $table->string('token', 64)->unique();          // tajny link do raportu zbiorczego
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('web_audits', function (Blueprint $table) {
            $table->foreignId('audit_batch_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
            $table->json('options')->nullable()->after('areas'); // pages: wskazane podstrony, ai: czy robić przegląd AI
        });

        Schema::create('audit_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 190);                   // domena, tak jak ją widzi człowiek
            $table->string('url', 500);                     // strona główna
            $table->json('pages')->nullable();              // wskazane najważniejsze podstrony
            $table->string('note', 500)->nullable();        // dopisek z listy, np. „czekamy za nową stroną”
            $table->boolean('enabled')->default(true);      // odznaczone = pomijamy
            $table->foreignId('web_audit_id')->nullable()->constrained()->nullOnDelete();
            $table->json('summary')->nullable();            // skrót wyniku (ocena, liczby uwag, lista uwag) – bez ładowania pełnych raportów
            $table->timestamps();
            $table->index(['audit_batch_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_batch_items');
        Schema::table('web_audits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audit_batch_id');
            $table->dropColumn('options');
        });
        Schema::dropIfExists('audit_batches');
    }
};
