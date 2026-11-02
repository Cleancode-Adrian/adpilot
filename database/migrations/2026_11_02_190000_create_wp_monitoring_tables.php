<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Monitoring WordPressa przez wtyczkę CC Connector: stan strony + zdarzenia (nowy admin, zmiany plików…). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('site_key', 40)->unique();      // jawny identyfikator strony
            $table->text('secret');                         // sekret HMAC (szyfrowany)
            $table->string('status', 20)->default('pending'); // pending | connected | error
            $table->string('rest_url', 500)->nullable();
            $table->json('report')->nullable();             // ostatni stan strony
            $table->json('scan')->nullable();               // ostatni skan plików
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->timestamp('last_pull_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('offline_alerted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wp_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wp_site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('severity', 10); // critical | warning | info
            $table->string('title');
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'acknowledged_at']);
            $table->index(['severity', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_events');
        Schema::dropIfExists('wp_sites');
    }
};
