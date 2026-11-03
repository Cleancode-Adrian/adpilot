<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\Analysis\Metrics;
use App\Services\Analysis\RuleEngine;
use App\Services\Connectors\MetaConnector;
use App\Services\Connectors\MetricsWriter;
use App\Services\Demo\DemoDataGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Lejek Meta: kliknięcia w link → wejścia na stronę → GA4, umiejscowienia, ostrzeżenia Meta i piksel. */
class MetaFunnelTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMeta(string $pixelLastFired): void
    {
        $day = now()->subDay()->toDateString();
        Http::fake(function (Request $r) use ($day, $pixelLastFired) {
            $url = $r->url();
            if (str_contains($url, '/act_1/adsets')) {
                return Http::response(['data' => [
                    ['id' => 's1', 'name' => 'PL 25-60', 'campaign_id' => 'c1', 'destination_type' => 'WEBSITE',
                        'issues_info' => [['level' => 'AD_SET', 'error_summary' => 'Zestaw danych nie jest połączony z witryną', 'error_message' => 'Połącz zestaw danych z domeną.']]],
                    ['id' => 's2', 'name' => 'Formularz', 'campaign_id' => 'c2', 'destination_type' => 'ON_AD'],
                ]]);
            }
            if (str_contains($url, '/act_1/campaigns')) {
                return Http::response(['data' => [
                    ['id' => 'c1', 'name' => 'Lead | strona', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => 1000],
                    ['id' => 'c2', 'name' => 'Lead Ads | formularz', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS'],
                ]]);
            }
            if (str_contains($url, '/act_1/adspixels')) {
                return Http::response(['data' => [['id' => '1128855549687710', 'name' => 'Piksel', 'last_fired_time' => $pixelLastFired]]]);
            }
            if (str_contains($url, '/act_1/insights') && str_contains($url, 'breakdowns')) {
                $row = fn ($pp, $pos, $impr, $link, $lpv, $spend) => ['campaign_id' => 'c1', 'campaign_name' => 'Lead | strona', 'date_start' => $day,
                    'publisher_platform' => $pp, 'platform_position' => $pos, 'impressions' => $impr, 'clicks' => $link, 'inline_link_clicks' => $link, 'spend' => $spend,
                    'actions' => $lpv ? [['action_type' => 'landing_page_view', 'value' => $lpv]] : []];

                return Http::response(['data' => [
                    $row('audience_network', 'an_classic', 224, 12, 0, 3.1),
                    $row('audience_network', 'rewarded_video', 8, 5, 0, 0.4),
                    $row('facebook', 'feed', 117, 4, 3, 4.0),
                ]]);
            }
            if (str_contains($url, '/act_1/insights')) {
                return Http::response(['data' => [
                    ['campaign_id' => 'c1', 'campaign_name' => 'Lead | strona', 'ad_id' => 'a1', 'ad_name' => 'Opisz projekt', 'date_start' => $day,
                        'impressions' => 349, 'clicks' => 25, 'inline_link_clicks' => 21, 'spend' => 8.99, 'reach' => 300, 'frequency' => 1.16,
                        'actions' => [['action_type' => 'landing_page_view', 'value' => 3], ['action_type' => 'link_click', 'value' => 21]]],
                    ['campaign_id' => 'c2', 'campaign_name' => 'Lead Ads | formularz', 'ad_id' => 'a2', 'ad_name' => 'Formularz', 'date_start' => $day,
                        'impressions' => 500, 'clicks' => 30, 'inline_link_clicks' => 20, 'spend' => 10, 'reach' => 400, 'frequency' => 1.25,
                        'actions' => [['action_type' => 'lead', 'value' => 2]]],
                ]]);
            }

            return Http::response(['error' => ['message' => 'nieoczekiwane '.$url]], 400);
        });
    }

    private function metaClient(): Client
    {
        $client = Client::create(['name' => 'Projekciarz', 'website_url' => 'https://projekciarz.pl', 'currency' => 'PLN', 'goal' => 'leads', 'has_ads' => true]);
        $client->integrations()->create(['provider' => 'meta', 'status' => 'connected', 'access_token' => 'token', 'settings' => ['ad_account_id' => 'act_1']]);

        return $client;
    }

    public function test_meta_sync_collects_link_clicks_lpv_placements_issues_and_pixel(): void
    {
        $this->fakeMeta(now()->subHours(2)->toIso8601String());
        $client = $this->metaClient();
        $i = $client->integration('meta');
        app(MetaConnector::class)->sync($i, new MetricsWriter($client), now()->subDays(3)->toDateString(), now()->subDay()->toDateString());

        $c1 = $client->campaigns()->where('external_id', 'c1')->first();
        $this->assertSame('WEBSITE', $c1->destination);
        $this->assertSame('Zestaw danych nie jest połączony z witryną', $c1->issues[0]['summary']);
        $this->assertSame('ON_AD', $client->campaigns()->where('external_id', 'c2')->value('destination'));

        $m = DB::table('campaign_metrics')->where('campaign_id', $c1->id)->first();
        $this->assertSame(21, (int) $m->link_clicks);
        $this->assertSame(3, (int) $m->landing_page_views);
        $this->assertSame(3, DB::table('placement_metrics')->where('campaign_id', $c1->id)->count());
        $this->assertSame('1128855549687710', $i->fresh()->setting('pixels.0.id'));

        // lejek liczy tylko kampanię prowadzącą na stronę (formularz Meta pominięty)
        $to = CarbonImmutable::yesterday();
        $f = (new Metrics($client))->funnel($to->subDays(13), $to);
        $steps = collect($f['steps'])->keyBy('key');
        $this->assertSame(21, $steps['link_clicks']['value']);
        $this->assertSame(3, $steps['lpv']['value']);
        $this->assertEquals(14.3, $steps['lpv']['rate']);

        $placements = (new Metrics($client))->placements($to->subDays(13), $to)->keyBy('position');
        $this->assertTrue($placements['an_classic']['suspicious']);
        $this->assertTrue($placements['rewarded_video']['suspicious']);
        $this->assertFalse($placements['feed']['suspicious']);
        $this->assertSame('Audience Network – wideo z nagrodą', $placements['rewarded_video']['name']);

        // reguły: jak w audycie – ruch nie dociera, wyklucz Audience Network, ostrzeżenie Meta; formularz bez alarmu
        app(RuleEngine::class)->run($client);
        $open = $client->recommendations()->open()->get();
        $this->assertTrue($open->contains(fn ($r) => $r->rule_key === 'meta_funnel' && str_contains($r->title, 'nie dociera') && $r->severity === 'critical'));
        $this->assertTrue($open->contains(fn ($r) => $r->entity_id === 'audience_network'));
        $this->assertFalse($open->contains(fn ($r) => str_contains($r->title, 'Lead Ads | formularz')), 'formularz Meta nie prowadzi na stronę – brak wejść jest normalny');
        $this->assertTrue($open->contains(fn ($r) => $r->rule_key === 'meta_account_health' && str_contains($r->description, 'nie jest połączony z witryną')));
        $this->assertFalse($open->contains(fn ($r) => $r->entity_id === 'meta_pixel_silent'), 'piksel działa');
    }

    public function test_silent_pixel_raises_critical(): void
    {
        $this->fakeMeta(now()->subDays(6)->toIso8601String());
        $client = $this->metaClient();
        app(MetaConnector::class)->sync($client->integration('meta'), new MetricsWriter($client), now()->subDays(3)->toDateString(), now()->subDay()->toDateString());
        app(RuleEngine::class)->run($client);

        $r = $client->recommendations()->open()->where('entity_id', 'meta_pixel_silent')->first();
        $this->assertNotNull($r);
        $this->assertSame('critical', $r->severity);
    }

    public function test_ads_page_shows_funnel_and_placements_for_demo(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $client = DemoDataGenerator::createLeadsClient();

        $this->get("/clients/{$client->id}/ads")->assertOk()->assertInertia(fn ($p) => $p
            ->where('funnel.steps.0.key', 'impressions')
            ->where('funnel.steps.2.key', 'lpv')
            ->where('placements', fn ($rows) => collect($rows)->contains(fn ($r) => $r['platform'] === 'audience_network' && $r['suspicious'])));
    }
}
