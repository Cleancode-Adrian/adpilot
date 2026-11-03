<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\Analysis\Metrics;
use App\Services\Connectors\Ga4Connector;
use App\Services\Connectors\MetricsWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Zachowanie ruchu z reklam z GA4: strony wejścia, reklamy (utm_content), zaangażowanie, zdarzenia. */
class AdsBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_groups(): void
    {
        $this->assertSame('meta', MetricsWriter::sourceGroup('facebook / paid'));
        $this->assertSame('meta', MetricsWriter::sourceGroup('m.facebook.com / referral'));
        $this->assertSame('meta', MetricsWriter::sourceGroup('ig / social'));
        $this->assertSame('google_ads', MetricsWriter::sourceGroup('google / cpc'));
        $this->assertNull(MetricsWriter::sourceGroup('google / organic'));
        $this->assertNull(MetricsWriter::sourceGroup('(direct) / (none)'));
        $this->assertNull(MetricsWriter::sourceGroup('metabase.com / referral'));
    }

    public function test_ga4_behavior_sync_and_ads_page(): void
    {
        $day = now()->subDay()->format('Ymd');
        $row = fn (array $dims, array $mets) => ['dimensionValues' => array_map(fn ($v) => ['value' => $v], $dims), 'metricValues' => array_map(fn ($v) => ['value' => (string) $v], $mets)];
        Http::fake(function (Request $r) use ($day, $row) {
            $dims = collect($r->data()['dimensions'] ?? [])->pluck('name')->all();

            return match ($dims) {
                ['date', 'sessionSourceMedium', 'landingPage'] => Http::response(['rows' => [
                    $row([$day, 'facebook / paid', '/'], [3, 1, 95, 4, 0]),
                    $row([$day, 'fb / paid', '/'], [1, 1, 40, 2, 1]),
                    $row([$day, 'google / cpc', '/oferta'], [5, 4, 300, 9, 1]),
                    $row([$day, 'google / organic', '/blog'], [50, 30, 2000, 80, 0]),
                ]]),
                ['date', 'sessionSourceMedium', 'sessionManualAdContent'] => Http::response(['rows' => [
                    $row([$day, 'facebook / paid', 'Opisz projekt – grafiki A/B'], [4, 2, 135, 6, 1]),
                    $row([$day, 'google / cpc', '(not set)'], [5, 4, 300, 9, 1]),
                ]]),
                ['date', 'sessionSourceMedium', 'eventName'] => Http::response(['rows' => [
                    $row([$day, 'facebook / paid', 'scroll'], [2]),
                    $row([$day, 'facebook / paid', 'form_start'], [1]),
                    $row([$day, 'facebook / paid', 'generate_lead'], [1]),
                ]]),
                default => Http::response(['rows' => []]),
            };
        });

        $client = Client::create(['name' => 'Projekciarz', 'website_url' => 'https://projekciarz.pl', 'currency' => 'PLN', 'goal' => 'leads', 'has_ads' => true]);
        $i = $client->integrations()->create(['provider' => 'google', 'status' => 'connected', 'access_token' => 't', 'token_expires_at' => now()->addHour(), 'settings' => ['ga4_property_id' => '123']]);
        app(Ga4Connector::class)->sync($i, new MetricsWriter($client), now()->subDays(3)->toDateString(), now()->toDateString());

        $to = CarbonImmutable::today();
        $b = (new Metrics($client))->behavior($to->subDays(13), $to);
        $meta = $b['meta'];
        $this->assertSame(4, $meta['totals']['sessions'], 'facebook / paid + fb / paid');
        $this->assertSame(50, $meta['totals']['engaged_rate']);
        $this->assertSame(34, $meta['totals']['avg_seconds']); // (95+40)/4
        $this->assertSame('Opisz projekt – grafiki A/B', $meta['ads'][0]['item']);
        $this->assertEquals(['form_start' => 1, 'generate_lead' => 1, 'scroll' => 2], $meta['events']);
        $this->assertSame(5, $b['google_ads']['totals']['sessions']);
        $this->assertSame([], $b['google_ads']['ads'], '(not set) pomijamy');
        $this->assertArrayNotHasKey('other', $b, 'ruch organiczny nie jest tu liczony');

        // ponowna synchronizacja tego samego okresu nie dubluje danych
        app(Ga4Connector::class)->sync($i, new MetricsWriter($client), now()->subDays(3)->toDateString(), now()->toDateString());
        $this->assertSame(4, (new Metrics($client))->behavior($to->subDays(13), $to)['meta']['totals']['sessions']);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get("/clients/{$client->id}/ads")->assertOk()->assertInertia(fn ($p) => $p
            ->where('behavior.meta.totals.sessions', 4)->where('clarityDetected', false));
    }
}
