<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lejek reklamowy: kliknięcia w link i wyświetlenia strony docelowej (LPV) z Meta,
 * podział na umiejscowienia (Facebook / Instagram / Audience Network…), ostrzeżenia Meta o kampaniach
 * oraz sesje GA4 wg źródła/medium (do porównania „kliknięcia Meta → sesje z Facebooka w GA4”).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['campaign_metrics', 'ad_metrics'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedBigInteger('link_clicks')->nullable()->after('clicks');
                $table->unsignedBigInteger('landing_page_views')->nullable()->after('link_clicks');
            });
        }

        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('issues')->nullable(); // ostrzeżenia Meta (issues_info) – kampania i zestawy reklam
            $table->string('destination', 60)->nullable(); // dokąd prowadzi reklama: WEBSITE, ON_AD (formularz Meta), MESSENGER…
        });

        Schema::create('placement_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('publisher_platform', 40);  // facebook | instagram | audience_network | messenger | threads
            $table->string('platform_position', 60);   // feed | reels | rewarded_video | an_classic …
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('link_clicks')->default(0);
            $table->unsignedBigInteger('landing_page_views')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('conversions', 12, 2)->default(0);
            $table->unique(['campaign_id', 'date', 'publisher_platform', 'platform_position'], 'placement_unique');
            $table->index(['client_id', 'date']);
        });

        Schema::create('analytics_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('source_medium', 190);
            $table->unsignedBigInteger('sessions')->default(0);
            $table->unsignedBigInteger('engaged_sessions')->default(0);
            $table->decimal('key_events', 12, 2)->default(0);
            $table->unique(['client_id', 'date', 'source_medium']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sources');
        Schema::dropIfExists('placement_metrics');
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['issues', 'destination']));
        foreach (['campaign_metrics', 'ad_metrics'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['link_clicks', 'landing_page_views']));
        }
    }
};
