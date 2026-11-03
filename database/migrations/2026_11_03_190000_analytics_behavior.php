<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zachowanie ruchu z reklam (GA4): strony wejścia i reklamy (utm_content) dla ruchu z Meta i Google Ads
 * oraz zdarzenia (przewinięcie, start formularza, wysłanie, kliknięcie w telefon…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_behavior', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source_group', 20); // meta | google_ads
            $table->string('kind', 10);         // landing | ad
            $table->string('item', 300);        // ścieżka strony wejścia albo nazwa reklamy
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('engaged_sessions')->default(0);
            $table->unsignedBigInteger('engagement_seconds')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->decimal('key_events', 10, 2)->default(0);
            $table->index(['client_id', 'source_group', 'kind', 'date']);
        });

        Schema::create('analytics_source_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source_group', 20);
            $table->string('event_name', 60);
            $table->unsignedInteger('count')->default(0);
            $table->index(['client_id', 'source_group', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_source_events');
        Schema::dropIfExists('analytics_behavior');
    }
};
