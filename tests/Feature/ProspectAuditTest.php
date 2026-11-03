<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\WebAudit;
use App\Services\WebAudit\SecurityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Audyt dowolnej strony (potencjalny klient) + kwoty przy zadaniach. */
class ProspectAuditTest extends TestCase
{
    use RefreshDatabase;

    /** Strona na WordPressie z typowymi „furtkami” i brakami. */
    private function fakeWordPressSite(): void
    {
        config(['adpilot.pagespeed.enabled' => false]);
        $html = '<!doctype html><html lang="pl"><head><title>Hydraulik Kraków – szybkie naprawy 24h</title><meta name="generator" content="WordPress 6.1.1">'
            .'<meta name="description" content="Hydraulik w Krakowie"><link rel="canonical" href="https://hydraulik.example/"><meta name="viewport" content="width=device-width">'
            .'<link rel="stylesheet" href="/wp-content/themes/x/style.css"></head><body><h1>Hydraulik</h1><p>'.str_repeat('tekst ', 250).'</p><a href="/kontakt">Kontakt</a></body></html>';

        Http::fake(function (Request $r) use ($html) {
            $u = $r->url();

            return match (true) {
                $u === 'https://hydraulik.example/' => Http::response($html, 200, ['Content-Type' => 'text/html', 'X-Powered-By' => 'PHP/7.4.33', 'Server' => 'Apache/2.4.41 (Ubuntu)']),
                $u === 'https://hydraulik.example/kontakt' => Http::response(str_replace('Hydraulik Kraków – szybkie naprawy 24h', 'Kontakt – Hydraulik Kraków', $html), 200, ['Content-Type' => 'text/html']),
                $u === 'https://hydraulik.example/xmlrpc.php' => Http::response('XML-RPC server accepts POST requests only.', 405),
                $u === 'https://hydraulik.example/wp-json/wp/v2/users' => Http::response([['id' => 1, 'slug' => 'admin'], ['id' => 2, 'slug' => 'jan']]),
                $u === 'https://hydraulik.example/wp-content/uploads/' => Http::response('<html><title>Index of /wp-content/uploads</title>Index of /wp-content/uploads</html>', 200),
                $u === 'http://hydraulik.example/' => Http::response('', 301, ['Location' => 'https://hydraulik.example/']),
                default => Http::response('', 404),
            };
        });
    }

    public function test_security_scanner_finds_public_weak_spots(): void
    {
        $this->fakeWordPressSite();
        $scanner = new class extends SecurityScanner
        {
            protected function fetchCertificate(string $host): ?array
            {
                return ['validTo_time_t' => now()->addDays(60)->timestamp];
            }
        };
        $r = $scanner->scan('https://hydraulik.example/');
        $c = collect($r['checks'])->keyBy('key');

        $this->assertTrue($r['wordpress']);
        $this->assertSame('ok', $c['https']['status']);
        $this->assertSame('ok', $c['ssl_expiry']['status']);
        $this->assertSame('fail', $c['php_version']['status'], 'PHP 7.4 bez wsparcia');
        $this->assertSame('warn', $c['wp_version_public']['status']);
        $this->assertSame('warn', $c['xmlrpc']['status']);
        $this->assertSame('warn', $c['wp_users']['status']);
        $this->assertStringContainsString('2 kont', $c['wp_users']['details']);
        $this->assertSame('fail', $c['dir_listing']['status']);
        $this->assertSame('warn', $c['hsts']['status']);
        $this->assertSame('warn', $c['server_version']['status']);
        $this->assertLessThan(60, $r['score']);
    }

    public function test_prospect_audit_flow_report_and_convert_to_client(): void
    {
        $this->fakeWordPressSite();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->post('/audits', ['url' => 'nie adres'])->assertSessionHasErrors('url');
        $this->post('/audits', ['url' => 'hydraulik.example', 'name' => 'Hydraulik Kraków', 'email' => 'biuro@hydraulik.example'])->assertRedirect();
        $audit = WebAudit::first();
        $this->assertSame('done', $audit->status, (string) $audit->error); // kolejka „sync” w testach
        $this->assertSame(['seo', 'tracking', 'speed', 'security'], array_keys($audit->areas));
        $this->assertNotNull($audit->areas['seo']['score']);
        $this->assertNotNull($audit->areas['security']['score']);
        $this->assertNotNull($audit->score);

        $this->get("/audits/{$audit->id}")->assertOk()->assertInertia(fn ($p) => $p->component('audits/show')->where('audit.id', $audit->id));
        $this->get('/audits')->assertOk()->assertInertia(fn ($p) => $p->component('audits/index')->where('audits.0.area_scores.security', $audit->areas['security']['score']));

        // raport dla potencjalnego klienta – bez logowania
        auth()->logout();
        $token = WebAudit::find($audit->id)->getRawOriginal('token');
        $this->get("/audyt/{$token}")->assertOk()->assertSee('Hydraulik Kraków')->assertSee('Lista użytkowników przez REST API')->assertSee('Chcesz, żebyśmy to poprawili?');
        $this->get("/audyt/{$token}/pdf")->assertOk();
        $this->get('/audyt/zly-token')->assertNotFound();

        // potencjalny klient → klient
        $this->actingAs(User::first());
        $this->post("/audits/{$audit->id}/convert")->assertRedirect();
        $client = Client::where('website_url', 'https://hydraulik.example')->first();
        $this->assertSame('Hydraulik Kraków', $client->name);
        $this->assertSame('biuro@hydraulik.example', $client->contact_email);
        $this->assertTrue($client->has_general);
        $this->assertSame($client->id, $audit->fresh()->client_id);
    }

    public function test_task_amount_extra_work_to_invoice(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $client = Client::create(['name' => 'Firma', 'website_url' => 'https://firma.pl', 'currency' => 'PLN', 'has_general' => true]);
        $this->post("/clients/{$client->id}/care/tasks", ['title' => 'Nowa podstrona', 'due_date' => now()->toDateString()]);
        $task = $client->careTasks()->first();

        $this->patch("/care/tasks/{$task->id}", ['status' => 'done', 'amount' => 450.5])->assertRedirect();
        $this->assertSame(450.5, $task->fresh()->amount);
        $summary = app(\App\Services\Care\CarePlanner::class)->summary($client);
        $this->assertSame(450.5, $summary['amount']);
        $this->assertSame(450.5, $summary['amount_open']);

        $this->get('/billing')->assertInertia(fn ($p) => $p->where('extraWork.0.total', 450.5)->where('extraWork.0.tasks.0.title', 'Nowa podstrona'));
        $this->post("/clients/{$client->id}/billing/extra-work-invoiced")->assertSessionHas('success');
        $this->assertNotNull($task->fresh()->invoiced_at);
        $this->get('/billing')->assertInertia(fn ($p) => $p->where('extraWork', []));

        $this->patch("/care/tasks/{$task->id}", ['amount' => -1])->assertSessionHasErrors('amount');
    }
}
