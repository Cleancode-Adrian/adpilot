<?php

namespace Tests\Feature;

use App\Mail\WpAlertMail;
use App\Models\Client;
use App\Models\User;
use App\Models\WpEvent;
use App\Models\WpSite;
use App\Services\WordPress\WpMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Wtyczka CC Connector (prawdziwy kod, zaślepki WordPressa w tests/wordpress/harness.php)
 * ↔ endpoint panelu. Sprawdza zgodność podpisów, skaner plików, zdarzenia i alerty.
 */
class WordPressMonitorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/wp-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['app.url' => 'https://panel.example']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function site(): WpSite
    {
        $client = Client::create(['name' => 'Klient WP', 'website_url' => 'https://klient.example', 'currency' => 'PLN', 'has_care' => true]);

        return WpSite::generateFor($client);
    }

    /** Uruchamia wtyczkę w uprzęży i zwraca wynik (w tym zapytania, które wysłała). */
    private function plugin(WpSite $site, string $mode, array $env = [], ?array $panelRequest = null): array
    {
        $cmd = [PHP_BINARY, base_path('tests/wordpress/harness.php'), $this->dir, $site->connectionKey()];
        if ($panelRequest) {
            $cmd[] = json_encode($panelRequest);
        }
        $p = new Process($cmd, null, ['CC_MODE' => $mode] + $env);
        $p->mustRun();
        $out = json_decode($p->getOutput(), true);
        $this->assertIsArray($out, 'Wtyczka zwróciła: '.$p->getOutput().$p->getErrorOutput());

        return $out;
    }

    /** Odtwarza zapytanie wtyczki na endpoincie panelu. */
    private function replay(array $req)
    {
        $this->assertSame('https://panel.example/api/wp/push', $req['url']);
        $server = collect($req['headers'])->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();

        return $this->call('POST', '/api/wp/push', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $req['body']);
    }

    public function test_plugin_report_scan_events_and_alerts_end_to_end(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'szef@example.com']);
        $site = $this->site();

        // 1) pierwsze połączenie: raport + wzorzec plików
        $first = $this->plugin($site, 'first');
        $this->assertTrue($first['push_ok']);
        $this->assertCount(1, $first['requests']);
        $this->replay($first['requests'][0])->assertOk();

        $site->refresh();
        $this->assertSame('connected', $site->status);
        $this->assertSame('6.8.1', $site->report['wp_version']);
        $this->assertSame('6.9', $site->report['wp_update']);
        $this->assertSame('5.3', collect($site->report['plugins'])->firstWhere('name', 'Akismet')['update']);
        $this->assertTrue($site->scan['baseline']);
        $this->assertSame(0, WpEvent::count(), 'pierwszy skan zapisuje wzorzec – bez alertów');

        // 2) „włamanie”: zmiany w plikach wykryte przy codziennym skanie
        $tamper = $this->plugin($site, 'tamper');
        $this->replay(end($tamper['requests']))->assertOk();
        $types = WpEvent::pluck('severity', 'type');
        $this->assertSame('critical', $types['scan_uploads_php']);
        $this->assertSame('critical', $types['scan_config_changed']);
        $this->assertSame('critical', $types['scan_core_modified']);
        $this->assertSame('critical', $types['scan_core_unknown']);
        $this->assertSame('warning', $types['scan_files_changed']);
        $this->assertContains('wp-content/uploads/2026/11/shell.php', WpEvent::firstWhere('type', 'scan_uploads_php')->details['files']);
        $this->assertContains('index.php', WpEvent::firstWhere('type', 'scan_core_modified')->details['files']);

        // 3) zdarzenia na żywo: nowy admin, edycja kodu, logowanie
        $events = $this->plugin($site, 'events');
        $this->assertSame(0, $events['pending']);
        $this->assertCount(1, $events['requests'], 'zdarzenia z jednego żądania idą razem');
        $this->replay($events['requests'][0])->assertOk();
        $added = WpEvent::firstWhere('type', 'admin_added');
        $this->assertSame('critical', $added->severity);
        $this->assertSame('Nowy administrator: nowy_admin', $added->title);
        $this->assertSame('203.0.113.7', $added->details['ip']);
        $this->assertSame('critical', WpEvent::firstWhere('type', 'file_edited')->severity);
        $this->assertSame('info', WpEvent::firstWhere('type', 'admin_login')->severity);

        Mail::assertSent(WpAlertMail::class, fn ($m) => $m->hasTo('szef@example.com') && $m->event->type === 'admin_added');

        // 4) panel: zakładka, liczniki, potwierdzanie
        $this->actingAs(User::first());
        $this->get("/clients/{$site->client_id}/wordpress")->assertOk()
            ->assertInertia(fn ($p) => $p->component('clients/wordpress')->where('site.status', 'connected')
                ->where('site.php.status', fn ($s) => in_array($s, ['ok', 'soon', 'eol'], true))
                ->has('site.plugin_updates', 1)->where('alertCount', fn ($n) => $n >= 7)->where('wp_alerts', fn ($n) => $n >= 7));
        $this->post("/clients/{$site->client_id}/wordpress/ack-all");
        $this->assertSame(0, WpEvent::alerts()->count());
    }

    public function test_official_plugin_update_is_not_reported_as_suspicious(): void
    {
        $site = $this->site();
        $this->replay($this->plugin($site, 'first')['requests'][0])->assertOk();

        $out = $this->plugin($site, 'update');
        $this->assertSame(0, $out['scan']['changed_count'], 'pliki zaktualizowanej wtyczki trafiają do wzorca');
        $this->replay($out['requests'][0])->assertOk();
        $this->assertSame('info', WpEvent::firstWhere('type', 'plugin_updated')->severity);
    }

    public function test_events_are_queued_when_panel_is_unreachable_and_sent_with_next_report(): void
    {
        $site = $this->site();
        $out = $this->plugin($site, 'events', ['CC_FAIL_SEND' => '1']);
        $this->assertSame(3, $out['pending']);

        $next = $this->plugin($site, 'first'); // przy raporcie najpierw zaległe zdarzenia
        $this->assertCount(2, $next['requests']);
        $this->assertStringContainsString('"kind":"events"', $next['requests'][0]['body']);
        foreach ($next['requests'] as $r) {
            $this->replay($r)->assertOk();
        }
        $this->assertTrue(WpEvent::where('type', 'admin_added')->exists());
    }

    public function test_push_rejects_bad_signature_old_timestamp_and_unknown_site(): void
    {
        $site = $this->site();
        $req = $this->plugin($site, 'first')['requests'][0];

        $bad = $req;
        $bad['body'] = str_replace('6.8.1', '9.9.9', $bad['body']); // zmienione dane, stary podpis
        $this->replay($bad)->assertForbidden();

        $old = $req;
        $old['headers']['X-CC-Timestamp'] = (string) (time() - 3600);
        $old['headers']['X-CC-Signature'] = WpMonitor::sign($site->secret, $old['headers']['X-CC-Timestamp'], $old['body']);
        $this->replay($old)->assertForbidden();

        $unknown = $req;
        $unknown['headers']['X-CC-Site'] = 'nieznana';
        $this->replay($unknown)->assertForbidden();

        $this->assertNull($site->fresh()->report);
        $this->replay($req)->assertOk();
    }

    public function test_panel_pull_signature_is_accepted_by_plugin(): void
    {
        $site = $this->site();
        $body = json_encode(['scan' => true, 'nonce' => 'abc']);
        $ts = (string) time();
        $headers = ['x_cc_site' => $site->site_key, 'x_cc_timestamp' => $ts, 'x_cc_signature' => WpMonitor::sign($site->secret, $ts, $body)];

        $ok = $this->plugin($site, 'panel', [], ['headers' => $headers, 'body' => $body]);
        $this->assertTrue($ok['verify']);
        $this->assertSame(['report', 'scan'], $ok['rest_report_keys']);
        $this->assertTrue($ok['scan_present']);

        $forged = $this->plugin($site, 'panel', [], ['headers' => ['x_cc_signature' => str_repeat('0', 64)] + $headers, 'body' => $body]);
        $this->assertSame('Nieprawidłowy podpis.', $forged['verify']);

        // panel → strona przez HTTP (Http::fake) – zapis raportu
        Http::fake(['klient.example/*' => Http::response(['report' => ['wp_version' => '6.8.1', 'php_version' => '7.4.33', 'admins' => [], 'plugins' => [], 'themes' => []], 'scan' => null])]);
        app(WpMonitor::class)->pull($site, true);
        $this->assertSame('eol', WpMonitor::phpStatus($site->fresh()->report['php_version'])['status']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-CC-Signature') && str_ends_with($r->url(), '/wp-json/cc-connector/v1/report'));
    }

    public function test_downloaded_mu_plugin_has_embedded_key_and_is_valid_php(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $client = Client::create(['name' => 'K', 'website_url' => 'https://k.example', 'currency' => 'PLN']);

        $res = $this->get("/clients/{$client->id}/wordpress/download")->assertOk();
        $php = $res->getContent();
        $site = $client->fresh()->wpSite;
        $this->assertStringContainsString("const EMBEDDED_KEY = '".$site->connectionKey()."';", $php);
        $this->assertStringContainsString('Plugin Name:       CC Connector', $php);
        $this->assertStringNotContainsString('AdPilot', $php);

        $file = $this->dir.'/cc-connector.php';
        file_put_contents($file, $php);
        (new Process([PHP_BINARY, '-l', $file]))->mustRun();

        $this->get("/clients/{$client->id}/wordpress/download?type=zip")->assertOk()->assertHeader('content-type', 'application/zip');

        // tylko administrator
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get("/clients/{$client->id}/wordpress/download")->assertForbidden();
    }

    public function test_offline_site_raises_one_warning(): void
    {
        $site = $this->site();
        $site->update(['status' => 'connected', 'last_seen_at' => now()->subHours(40)]);
        $this->assertSame(1, app(WpMonitor::class)->checkOffline());
        $this->assertSame(0, app(WpMonitor::class)->checkOffline());
        $this->assertSame('warning', WpEvent::firstWhere('type', 'site_offline')->severity);
    }
}
