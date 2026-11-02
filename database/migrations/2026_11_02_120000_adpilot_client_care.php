<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Obsługa klienta: stały plan opieki (zadania cykliczne), zadania na dany miesiąc
 * i zaszyfrowany sejf dostępów (hasła) z dziennikiem odczytów.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Plan opieki – szablon zadania powtarzanego co miesiąc / kwartał / pół roku / rok
        Schema::create('care_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('frequency', 20)->default('monthly'); // monthly|quarterly|semiannual|yearly
            $table->unsignedTinyInteger('start_month')->default(1); // od którego miesiąca liczony cykl
            $table->unsignedTinyInteger('due_day')->default(10);    // termin: dzień miesiąca
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Konkretne zadanie w danym miesiącu (z planu albo jednorazowe)
        Schema::create('care_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('care_template_id')->nullable()->constrained()->nullOnDelete();
            $table->char('period', 7); // RRRR-MM
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->string('status', 10)->default('todo'); // todo|done|skipped
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['care_template_id', 'period']);
            $table->index(['client_id', 'period']);
            $table->index(['status', 'due_date']);
        });

        // Sejf dostępów – hasło i notatki szyfrowane kluczem aplikacji (APP_KEY)
        Schema::create('client_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30)->default('other');
            $table->string('label');
            $table->string('url', 500)->nullable();
            $table->string('username')->nullable();
            $table->text('secret')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('secret_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credential_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20); // view|create|update|delete
            $table->string('label');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_logs');
        Schema::dropIfExists('client_credentials');
        Schema::dropIfExists('care_tasks');
        Schema::dropIfExists('care_templates');
    }
};
