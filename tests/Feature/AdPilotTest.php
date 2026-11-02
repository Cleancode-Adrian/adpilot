<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Analysis\RuleEngine;
use App\Services\Demo\DemoDataGenerator;
use App\Services\Reports\ReportBuilder;
use App\Services\Tracking\TrackingAuditor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdPilotTest extends TestCase
{
    use RefreshDatabase;

    private function demo(): Client
    {
        $client = DemoDataGenerator::createClient();
        app(RuleEngine::class)->run($client);

        return $client;
    }

    public function test_demo_data_produces_expected_recommendations(): void
    {
        $client = $this->demo();
        $keys = $client->recommendations()->open()->pluck('rule_key')->unique();

        foreach (['wasted_spend_campaign', 'low_roas', 'scale_winner', 'creative_fatigue', 'search_term_waste', 'tracking_audit'] as $rule) {
            $this->assertContains($rule, $keys, "Brak rekomendacji: $rule");
        }
    }

    public function test_rule_engine_is_idempotent_and_respects_done_status(): void
    {
        $client = $this->demo();
        $count = $client->recommendations()->open()->count();

        $rec = $client->recommendations()->open()->first();
        $rec->update(['status' => 'done', 'resolved_at' => now()]);

        $stats = app(RuleEngine::class)->run($client);
        $this->assertSame(0, $stats['new']);
        $this->assertSame($count - 1, $client->recommendations()->open()->count());
        $this->assertSame('done', $rec->fresh()->status);
    }

    public function test_all_panel_pages_render(): void
    {
        $client = $this->demo();
        $report = app(ReportBuilder::class)->build($client, 'client', CarbonImmutable::yesterday()->subDays(29), CarbonImmutable::yesterday());
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        foreach ([
            '/admin/status', '/admin/settings', '/admin/team', '/dashboard?archived=1',
            '/dashboard', '/tasks', '/clients/create', "/clients/{$client->id}", "/clients/{$client->id}?days=7",
            "/clients/{$client->id}/edit", "/clients/{$client->id}/recommendations", "/clients/{$client->id}/recommendations?status=done",
            "/clients/{$client->id}/audit", "/clients/{$client->id}/integrations", "/clients/{$client->id}/reports", "/reports/{$report->id}",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_public_report_requires_valid_token_and_renders_pdf(): void
    {
        $client = $this->demo();
        $report = app(ReportBuilder::class)->build($client, 'client', CarbonImmutable::yesterday()->subDays(29), CarbonImmutable::yesterday());

        $this->get("/r/wrong-token/{$report->id}")->assertNotFound();
        $this->get("/r/{$client->report_token}/{$report->id}")->assertOk()->assertSee('Najważniejsze wyniki reklam');
        $this->get("/r/{$client->report_token}/{$report->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_create_client_and_mark_recommendation_done(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());

        $this->post('/clients', ['name' => 'Sklep Test', 'website_url' => 'sklep-test.pl', 'currency' => 'pln'])
            ->assertRedirect();
        $client = Client::where('name', 'Sklep Test')->firstOrFail();
        $this->assertSame('https://sklep-test.pl', $client->website_url);
        $this->assertSame('PLN', $client->currency);
        $this->assertSame(1, $client->audits()->count());

        $rec = $this->demo()->recommendations()->open()->first();
        $this->patch("/recommendations/{$rec->id}", ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', $rec->fresh()->status);
    }

    public function test_tracking_auditor_detects_tags_from_html_and_gtm(): void
    {
        config(['adpilot.pagespeed.enabled' => false]);
        Http::fake([
            'https://sklep.pl' => Http::response('<html><head><title>Sklep z meblami ogrodowymi</title><meta name="viewport" content="width=device-width">
                <script src="https://consent.cookiebot.com/uc.js"></script>
                <script>gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied"});</script>
                <script>(function(w,d,s,l,i){})(window,document,"script","dataLayer","GTM-ABC1234");</script>
                <link rel="stylesheet" href="/wp-content/plugins/woocommerce/assets/css/woocommerce.css"></head><body><p>Piszemy też o Shopify i PrestaShop.</p></body></html>'),
            'www.googletagmanager.com/*' => Http::response('{"vtp_measurementId":"G-TEST12345","vtp_conversionId":"987654321","function":"__awct","x":"__gclidw","e":"purchase"}'),
            '*' => Http::response('', 404),
        ]);

        $r = app(TrackingAuditor::class)->audit('sklep.pl');
        $checks = collect($r['checks'])->keyBy('key');

        $this->assertSame(['GTM-ABC1234'], $r['detected']['gtm']);
        $this->assertSame('WooCommerce', $r['platform']);
        $this->assertSame('ok', $checks['ga4']['status']);
        $this->assertSame('ok', $checks['google_ads_tag']['status']);
        $this->assertSame('ok', $checks['consent_mode']['status']);
        $this->assertSame('ok', $checks['purchase_event']['status']);
        $this->assertSame('warn', $checks['meta_pixel']['status']);
        $this->assertGreaterThan(40, $r['score']);
    }

    public function test_leads_demo_client_renders_and_tracks_leads(): void
    {
        $client = DemoDataGenerator::createLeadsClient();
        app(RuleEngine::class)->run($client);
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get("/clients/{$client->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('kpis.goal', 'leads')->where('kpis.analytics.leads.value', fn ($v) => $v > 0));

        $report = app(ReportBuilder::class)->build($client, 'client', CarbonImmutable::yesterday()->subDays(29), CarbonImmutable::yesterday());
        $this->get("/r/{$client->report_token}/{$report->id}")->assertOk()
            ->assertSee('Pozyskane kontakty (leady)')->assertSee('Koszt pozyskania kontaktu')->assertDontSee('ROAS (zwrot z reklam)');
        $this->get("/r/{$client->report_token}/{$report->id}/pdf")->assertOk();
        $this->get("/reports/{$report->id}")->assertOk();
    }

    public function test_non_admin_cannot_open_admin_pages_and_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/')->assertRedirect('/login');

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/admin/status')->assertForbidden();
        $this->get('/admin/team')->assertForbidden();
    }

    public function test_archived_client_is_hidden_and_restorable(): void
    {
        $client = $this->demo();
        $this->actingAs(User::factory()->create());

        $this->post("/clients/{$client->id}/archive")->assertRedirect('/dashboard');
        $this->assertNotNull($client->fresh()->archived_at);
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->has('clients', 0)->where('archivedCount', 1));

        $this->post("/clients/{$client->id}/archive");
        $this->assertNull($client->fresh()->archived_at);
    }

    public function test_daily_budget_without_spend_raises_critical_alert(): void
    {
        $client = $this->demo();
        $client->update(['is_demo' => false, 'daily_budget' => 10]);
        // ostatnie 3 dni bez wydatków
        \App\Models\CampaignMetric::whereIn('campaign_id', $client->campaigns()->pluck('id'))
            ->where('date', '>=', now()->subDays(3)->toDateString())->update(['cost' => 0]);

        app(RuleEngine::class)->run($client);
        $this->assertTrue($client->recommendations()->open()->where('entity_id', 'daily_zero')->where('severity', 'critical')->exists());
    }

    public function test_create_admin_command_and_team_invite(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->artisan('adpilot:create-admin', ['email' => 'Szef@Example.com', 'name' => 'Szef'])->assertSuccessful();
        $admin = User::where('email', 'szef@example.com')->firstOrFail();
        $this->assertTrue($admin->is_admin);

        $this->actingAs($admin)->post('/admin/team', ['name' => 'Ania', 'email' => 'ania@example.com'])->assertRedirect();
        \Illuminate\Support\Facades\Notification::assertSentTo(User::where('email', 'ania@example.com')->first(), \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    public function test_tracking_auditor_lead_checks(): void
    {
        config(['adpilot.pagespeed.enabled' => false]);
        Http::fake([
            'https://biuro.pl' => Http::response('<html><head><title>Biuro</title></head><body class="wp-content"><form action="/kontakt"></form><a href="tel:+48500100200">Zadzwoń</a>
                <script>(function(w,d,s,l,i){})(window,document,"script","dataLayer","GTM-LEAD123");</script></body></html>'),
            'www.googletagmanager.com/*' => Http::response('{"vtp_measurementId":"G-LEAD12345","e":"generate_lead","t":"gtm.formSubmit"}'),
            '*' => Http::response('', 404),
        ]);

        $r = app(TrackingAuditor::class)->audit('https://biuro.pl');
        $checks = collect($r['checks'])->keyBy('key');

        $this->assertSame('leads', $r['goal']);
        $this->assertSame('ok', $checks['lead_event']['status']);
        $this->assertSame('ok', $checks['tel_links']['status']);
        $this->assertSame('ok', $checks['forms']['status']);
        $this->assertFalse($checks->has('purchase_event'));
    }

    public function test_agency_settings_are_saved(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/settings', ['agency_name' => 'CleanCode AS', 'agency_email' => 'biuro@cleancodeas.pl', 'brand_color' => '#123456'])
            ->assertRedirect();
        $this->assertSame('CleanCode AS', \App\Models\Setting::brand()['name']);
        $this->assertSame('#123456', \App\Models\Setting::brand()['color']);
    }

    public function test_bots_are_blocked_and_pages_are_noindex(): void
    {
        foreach (['Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'ClaudeBot/1.0', 'PerplexityBot/1.0', 'Mozilla/5.0 (compatible; Googlebot/2.1)'] as $ua) {
            $this->withHeader('User-Agent', $ua)->get('/login')->assertForbidden();
        }
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', \App\Http\Middleware\BlockCrawlers::ROBOTS_HEADER)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('noindex', false);

        $client = $this->demo();
        $report = app(ReportBuilder::class)->build($client, 'client', CarbonImmutable::yesterday()->subDays(6), CarbonImmutable::yesterday(), false);
        $this->withHeader('User-Agent', 'GPTBot')->get("/r/{$client->report_token}/{$report->id}")->assertForbidden();
        $this->withHeader('User-Agent', 'Chrome')->get("/r/{$client->report_token}/{$report->id}/pdf")->assertOk()->assertHeader('X-Robots-Tag');
    }

    public function test_website_url_typos_are_fixed_or_rejected(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create());

        $this->post('/clients', ['name' => 'A', 'website_url' => 'https//projekciarz.pl', 'currency' => 'PLN'])->assertRedirect();
        $this->assertSame('https://projekciarz.pl', Client::where('name', 'A')->value('website_url'));

        $this->post('/clients', ['name' => 'B', 'website_url' => 'sklep', 'currency' => 'PLN'])->assertSessionHasErrors('website_url');
        $this->assertSame('https://projekciarz.pl', Client::normalizeUrl('https://https/projekciarz.pl'));
        $this->assertSame('https://httpbin.org', Client::normalizeUrl('httpbin.org'));
    }

    public function test_heartbeats_survive_cache_clear_and_show_in_status(): void
    {
        \App\Services\SystemStatus::beat(\App\Services\SystemStatus::HEARTBEAT_KEY);
        (new \App\Jobs\HeartbeatJob)->handle();
        \Illuminate\Support\Facades\Cache::flush();

        $checks = collect(app(\App\Services\SystemStatus::class)->checks())->keyBy('label');
        $this->assertSame('ok', $checks['Harmonogram (cron)']['status']);
        $this->assertContains($checks['Kolejka (worker)']['status'], ['ok', 'warn']);
    }

    public function test_platform_is_not_detected_from_page_text(): void
    {
        config(['adpilot.pagespeed.enabled' => false]);
        Http::fake([
            'https://zlecenia.pl' => Http::response('<html><head><title>Zlecenia</title></head><body><h2>Sklepy WooCommerce, Shopify, PrestaShop</h2><p>woocommerce wordpress magento</p><div id="app" data-page="{&quot;component&quot;:&quot;Home&quot;}"></div></body></html>', 200, ['Set-Cookie' => 'laravel_session=abc; path=/']),
            '*' => Http::response('', 404),
        ]);
        $r = app(TrackingAuditor::class)->audit('https://zlecenia.pl');
        $this->assertSame('Laravel (Inertia)', $r['platform']);
        $this->assertSame('leads', $r['goal']);
    }

    public function test_invite_shows_link_when_mail_fails(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

        $this->post('/admin/team', ['name' => 'Jan Test', 'email' => 'jan@example.com'])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('invite_link', fn ($link) => str_contains($link, '/reset-password/'));
        $this->assertDatabaseHas('users', ['email' => 'jan@example.com']);
    }

    public function test_invite_sends_mail(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->post('/admin/team', ['name' => 'Ola Test', 'email' => 'ola@example.com'])->assertSessionHas('success');
        $user = User::where('email', 'ola@example.com')->first();
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
        $this->post("/admin/team/{$user->id}/invite")->assertSessionHas('success');
    }
}
