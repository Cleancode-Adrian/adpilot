<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Monitoring dostępności i SSL + audyt strony (SEO i techniczny). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->boolean('enabled')->default(true);
            $table->string('status', 10)->default('unknown'); // up | down | unknown
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('down_since')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('ssl_issuer', 190)->nullable();
            $table->string('ssl_error', 300)->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->timestamp('ssl_alerted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('uptime_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('reason', 300);
            $table->index(['client_id', 'started_at']);
        });

        Schema::create('uptime_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unique(['client_id', 'date']);
        });

        Schema::create('site_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('status', 10)->default('pending'); // pending | running | done | failed
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->json('summary')->nullable();
            $table->json('issues')->nullable();
            $table->json('pages')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_audits');
        Schema::dropIfExists('uptime_daily');
        Schema::dropIfExists('uptime_incidents');
        Schema::dropIfExists('site_monitors');
    }
};
