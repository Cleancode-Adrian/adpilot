<?php

namespace Tests\Feature;

use App\Mail\ClientAlertMail;
use App\Models\Client;
use App\Models\SiteMonitor;
use App\Models\User;
use App\Services\Monitoring\UptimeMonitor;
use App\Services\SiteAudit\SiteAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SiteMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create(['name' => 'Firma', 'website_url' => 'https://firma.example', 'currency' => 'PLN', 'has_care' => true]);
    }

    /** Mała strona z typowymi błędami. */
    private function fakeSite(): void
    {
        $page = fn (string $title, string $body, string $head = '') => "<!doctype html><html lang=\"pl\"><head><title>$title</title>$head</head><body>$body</body></html>";
        $goodHead = '<meta name="description" content="Opis strony głównej firmy"><meta name="viewport" content="width=device-width"><link rel="canonical" href="https://firma.example/"><meta property="og:title" content="Firma"><script type="application/ld+json">{"@type":"Organization"}</script>';
        $words = str_repeat('słowo ', 250);

        Http::fake(function (Request $r) use ($page, $goodHead, $words) {
            $u = $r->url();

            return match (true) {
                $u === 'http://firma.example/' => Http::response('', 301, ['Location' => 'https://firma.example/']),
                $u === 'https://firma.example/robots.txt' => Http::response("User-agent: *\nDisallow: /wp-admin/\nSitemap: https://firma.example/sitemap.xml", 200, ['Content-Type' => 'text/plain']),
                $u === 'https://firma.example/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset><url><loc>https://firma.example/o-nas</loc></url><url><loc>https://firma.example/oferta</loc></url></urlset>', 200, ['Content-Type' => 'application/xml']),
                $u === 'https://firma.example/' => Http::response($page('Firma – strony internetowe Warszawa', "<h1>Firma</h1><p>$words</p><img src=\"/a.jpg\"><a href=\"/o-nas\">O nas</a><a href=\"/stara\">Stara</a><a href=\"/kontakt\">Kontakt</a><a href=\"https://zewn.example/nie-ma\">Zewn</a><a href=\"https://www.linkedin.com/company/x\">LI</a><a href=\"mailto:a@b.pl\">mail</a><a href=\"/plik.pdf\">PDF</a>", $goodHead), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
                $u === 'https://firma.example/o-nas' => Http::response($page('Firma – strony internetowe Warszawa', '<p>Krótko</p><img src="http://firma.example/b.jpg" alt="b">', '<meta name="robots" content="noindex">'), 200, ['Content-Type' => 'text/html']),
                $u === 'https://firma.example/oferta' => Http::response('Błąd', 500, ['Content-Type' => 'text/html']),
                $u === 'https://firma.example/stara' => Http::response('Nie ma', 404, ['Content-Type' => 'text/html']),
                $u === 'https://firma.example/kontakt' => Http::response('', 301, ['Location' => 'https://firma.example/kontakt/']),
                $u === 'https://firma.example/kontakt/' => Http::response($page('Kontakt – Firma', "<h1>Kontakt</h1><p>$words</p>", $goodHead), 200, ['Content-Type' => 'text/html']),
                str_contains($u, 'zewn.example') => Http::response('', 404),
                str_contains($u, 'linkedin.com') => Http::response('', 999),
                default => Http::response('', 404),
            };
        });
    }

    public function test_site_audit_finds_typical_problems(): void
    {
        $this->fakeSite();
        $auditor = app(SiteAuditor::class);
        $audit = $auditor->start($this->client());
        $auditor->run($audit);
        $audit->refresh();

        $this->assertSame('done', $audit->status, (string) $audit->error);
        $issues = collect($audit->issues)->keyBy('code');

        $this->assertSame('critical', $issues['broken_internal']['severity']);
        $this->assertSame(['https://firma.example/stara'], $issues['broken_internal']['urls']);
        $this->assertSame(['https://firma.example/'], $issues['broken_internal']['details']['https://firma.example/stara']);
        $this->assertContains('https://firma.example/oferta', $issues['server_errors']['urls']);
        $this->assertContains('https://firma.example/o-nas', $issues['duplicate_title']['urls']);
        $this->assertContains('https://firma.example/o-nas', $issues['missing_h1']['urls']);
        $this->assertContains('https://firma.example/o-nas', $issues['mixed_content']['urls']);
        $this->assertContains('https://firma.example/o-nas', $issues['sitemap_noindex']['urls']);
        $this->assertContains('https://firma.example/', $issues['img_no_alt']['urls']);
        $this->assertContains('https://firma.example/kontakt', $issues['links_to_redirects']['urls']);
        $this->assertSame(['https://zewn.example/nie-ma'], $issues['broken_external']['urls'], 'LinkedIn (999) to blokada robotów, nie błąd');

        $this->assertFalse($issues->has('robots_block_all'));
        $this->assertFalse($issues->has('no_sitemap'));
        $this->assertFalse($issues->has('http_no_redirect'));
        $this->assertFalse($issues->has('home_noindex'));
        $this->assertFalse(collect($audit->pages)->contains('url', 'https://firma.example/plik.pdf'), 'pliki PDF nie są stronami');

        $this->assertLessThan(80, $audit->score);
        $this->assertTrue($audit->summary['https']);
        $this->assertSame(2, $audit->summary['sitemap_urls']);
    }

    public function test_robots_blocking_everything_is_critical(): void
    {
        Http::fake([
            'https://firma.example/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200, ['Content-Type' => 'text/plain']),
            'https://firma.example/' => Http::response('<html><head><title>Strona testowa firmy</title></head><body><h1>x</h1></body></html>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $auditor = app(SiteAuditor::class);
        $audit = $auditor->start($this->client());
        $auditor->run($audit);

        $this->assertSame('critical', collect($audit->fresh()->issues)->firstWhere('code', 'robots_block_all')['severity']);
    }

    public function test_site_page_public_report_and_pdf(): void
    {
        $this->fakeSite();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $client = $this->client();

        $this->post("/clients/{$client->id}/site/audit")->assertSessionHas('success'); // kolejka „sync” w testach – audyt wykonany od razu
        $audit = $client->siteAudits()->first();
        $this->assertSame('done', $audit->status);

        $this->get("/clients/{$client->id}/site")->assertOk()->assertInertia(fn ($p) => $p->component('clients/site')->where('audit.id', $audit->id)->whereNot('public_url', null));
        $this->get("/a/{$client->report_token}/{$audit->id}")->assertOk()->assertSee('Audyt strony')->assertSee('Niedziałające linki wewnętrzne');
        $this->get("/a/{$client->report_token}/{$audit->id}/pdf")->assertOk();
        $this->get("/a/zly/{$audit->id}")->assertNotFound();
    }

    public function test_uptime_detects_outage_and_recovery_with_alerts(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true]);
        $client = $this->client();
        $monitor = app(UptimeMonitor::class);

        $status = 500;
        Http::fake(function () use (&$status) {
            return Http::response($status === 500 ? 'Błąd bazy danych' : 'OK', $status);
        });
        $monitor->checkAll();
        $m = $client->fresh()->siteMonitor;
        $this->assertSame('down', $m->status);
        $this->assertNotNull($m->down_since);
        $this->assertSame(1, DB::table('uptime_incidents')->whereNull('ended_at')->count());
        Mail::assertSent(ClientAlertMail::class, fn ($mail) => str_contains($mail->title, 'Strona nie działa'));

        // druga awaria z rzędu – bez kolejnego maila
        $monitor->checkAll();
        Mail::assertSent(ClientAlertMail::class, 1);

        $status = 200;
        $monitor->checkAll();
        $this->assertSame('up', $m->fresh()->status);
        $this->assertSame(0, DB::table('uptime_incidents')->whereNull('ended_at')->count());
        Mail::assertSent(ClientAlertMail::class, fn ($mail) => str_contains($mail->title, 'znów działa'));
        $this->assertEqualsWithDelta(33.33, UptimeMonitor::uptimePercent($client->id), 0.01);

        // klient bez opieki/obsługi – bez monitoringu
        Client::create(['name' => 'Tylko reklamy', 'website_url' => 'https://ads.example', 'currency' => 'PLN', 'has_ads' => true]);
        $monitor->ensureMonitors();
        $this->assertSame(1, SiteMonitor::count());
    }

    public function test_ssl_expiry_alert(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true]);
        $client = $this->client();
        $m = $client->siteMonitor()->create(['url' => 'https://firma.example']);

        $monitor = new class extends UptimeMonitor
        {
            protected function fetchCertificate(string $host): ?array
            {
                return ['validTo_time_t' => now()->addDays(5)->timestamp, 'issuer' => ['O' => "Let's Encrypt"]];
            }
        };
        $monitor->checkSsl($m);
        $this->assertSame(4, $m->fresh()->sslDaysLeft() >= 4 ? 4 : $m->fresh()->sslDaysLeft());
        $this->assertSame("Let's Encrypt", $m->fresh()->ssl_issuer);
        Mail::assertSent(ClientAlertMail::class, fn ($mail) => str_contains($mail->title, 'Certyfikat SSL wygasa'));

        $monitor->checkSsl($m->fresh()); // drugi raz tego samego dnia – bez maila
        Mail::assertSent(ClientAlertMail::class, 1);
    }
}
