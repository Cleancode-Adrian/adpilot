<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website_url');
            $table->string('industry')->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->decimal('target_roas', 8, 2)->nullable();   // np. 4.00 = 400%
            $table->decimal('target_cpa', 10, 2)->nullable();
            $table->decimal('monthly_budget', 12, 2)->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('auto_send_reports')->default(false);
            $table->string('report_token', 64)->unique();
            $table->text('notes')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // google | meta
            $table->string('status')->default('pending'); // pending | connected | error
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('settings')->nullable();            // wybrane konta: customer_id, ga4_property_id, ad_account_id...
            $table->json('available_accounts')->nullable();  // lista kont do wyboru po OAuth
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'provider']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // google_ads | meta_ads
            $table->string('external_id');
            $table->string('name');
            $table->string('status')->nullable();
            $table->string('channel_type')->nullable();
            $table->decimal('daily_budget', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'platform', 'external_id']);
        });

        Schema::create('campaign_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('conversions', 12, 2)->default(0);
            $table->decimal('conversion_value', 14, 2)->default(0);
            $table->unsignedBigInteger('reach')->nullable();
            $table->decimal('frequency', 8, 2)->nullable();
            $table->decimal('budget_lost_is', 6, 4)->nullable(); // Search budget lost impression share 0..1
            $table->unique(['campaign_id', 'date']);
            $table->index('date');
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('external_id');
            $table->string('name');
            $table->string('status')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'platform', 'external_id']);
        });

        Schema::create('ad_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('conversions', 12, 2)->default(0);
            $table->decimal('conversion_value', 14, 2)->default(0);
            $table->decimal('frequency', 8, 2)->nullable();
            $table->unique(['ad_id', 'date']);
            $table->index('date');
        });

        Schema::create('search_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('term');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('conversions', 12, 2)->default(0);
            $table->decimal('conversion_value', 14, 2)->default(0);
            $table->timestamps();
            $table->index(['client_id', 'period_end']);
        });

        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('channel');
            $table->unsignedBigInteger('sessions')->default(0);
            $table->unsignedBigInteger('users')->default(0);
            $table->unsignedBigInteger('new_users')->default(0);
            $table->unsignedBigInteger('engaged_sessions')->default(0);
            $table->decimal('transactions', 12, 2)->default(0);
            $table->decimal('revenue', 14, 2)->default(0);
            $table->unique(['client_id', 'date', 'channel']);
        });

        Schema::create('tracking_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('status')->default('pending'); // pending | running | done | failed
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('platform');      // google_ads | meta_ads | analytics | tracking | account
            $table->string('rule_key');
            $table->string('fingerprint', 64);
            $table->string('severity');      // critical | high | medium | low
            $table->string('kind')->default('problem'); // problem | opportunity
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('entity_name')->nullable();
            $table->string('title');
            $table->text('description');
            $table->text('action');
            $table->string('impact')->nullable();
            $table->json('data')->nullable();
            $table->string('status')->default('open'); // open | done | dismissed | resolved
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'fingerprint']);
            $table->index(['status', 'severity']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // client | internal
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data');
            $table->longText('ai_summary')->nullable();
            $table->string('ai_model')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['reports', 'recommendations', 'tracking_audits', 'analytics_daily', 'search_terms', 'ad_metrics', 'ads', 'campaign_metrics', 'campaigns', 'integrations', 'clients'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
