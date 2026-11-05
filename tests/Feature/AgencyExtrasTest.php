<?php

namespace Tests\Feature;

use App\Mail\ReportReadyMail;
use App\Models\Agency;
use App\Models\BillingItem;
use App\Models\Client;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Services\SiteAudit\LlmsTxtGenerator;
use App\Services\SiteAudit\SiteAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Rozliczenie z agencją, raporty white label, generator llms.txt. */
class AgencyExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function agencyWithSite(bool $whiteLabel = true): array
    {
        $agency = Agency::create(['name' => 'Studio XYZ', 'white_label' => $whiteLabel, 'contact_email' => 'biuro@studio-xyz.pl', 'website_url' => 'https://studio-xyz.pl',
            'brand_color' => '#ff0055', 'report_footer' => 'Studio XYZ – opieka nad stronami', 'hourly_rate' => 120]);
        $client = Client::create(['name' => 'Piekarnia', 'website_url' => 'https://piekarnia.pl', 'currency' => 'PLN', 'has_care' => true,
            'contact_email' => 'jan@piekarnia.pl', 'agency_id' => $agency->id]);

        return [$agency, $client];
    }

    public function test_settlement_values_work_by_amount_or_time_and_marks_invoiced(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$agency, $client] = $this->agencyWithSite(false);
        $done = fn (array $a) => $client->careTasks()->create($a + ['period' => '2026-10', 'status' => 'done', 'done_at' => '2026-10-15 10:00:00', 'due_date' => '2026-10-15']);
        $done(['title' => 'Aktualizacja wtyczek', 'minutes' => 90]);                 // 1,5 h × 120 = 180
        $done(['title' => 'Nowy formularz', 'minutes' => 60, 'amount' => 400]);       // kwota wygrywa
        $done(['title' => 'Bez wyceny']);                                            // brak wartości
        $client->careTasks()->create(['title' => 'Listopad', 'period' => '2026-11', 'status' => 'done', 'done_at' => '2026-11-02 10:00:00', 'minutes' => 30, 'due_date' => '2026-11-02']);
        $client->careTasks()->create(['title' => 'Niezrobione', 'period' => '2026-10', 'status' => 'todo', 'minutes' => 30, 'due_date' => '2026-10-20']);
        BillingItem::create(['client_id' => $client->id, 'type' => 'subscription', 'name' => 'Opieka', 'amount' => 300, 'currency' => 'PLN', 'period' => 'monthly', 'next_date' => '2026-10-10', 'active' => true]);

        $this->get("/agencies/{$agency->id}/settlement?month=2026-10")->assertInertia(fn (Assert $p) => $p->component('agencies/settlement')
            ->where('data.totals.tasks', 3)
            ->where('data.totals.minutes', 150)
            ->where('data.totals.work', 580)
            ->where('data.totals.subscriptions', 300)
            ->where('data.totals.unpriced', 1)
            ->where('data.totals.open_tasks', 2));

        $this->get("/agencies/{$agency->id}/settlement/pdf?month=2026-10")->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->post("/agencies/{$agency->id}/settlement/invoiced", ['month' => '2026-10']);
        $this->assertSame(2, $client->careTasks()->whereNotNull('invoiced_at')->count(), 'tylko prace z wartością, tylko z października');
    }

    public function test_settlement_and_rate_are_admin_only(): void
    {
        [$agency] = $this->agencyWithSite();
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get("/agencies/{$agency->id}/settlement")->assertForbidden();
        $this->get("/agencies/{$agency->id}")->assertInertia(fn (Assert $p) => $p->where('agency.hourly_rate', null));
        $this->get("/agencies/{$agency->id}/edit")->assertInertia(fn (Assert $p) => $p->where('agency.hourly_rate', null));

        $this->put("/agencies/{$agency->id}", ['name' => 'Studio XYZ', 'hourly_rate' => 1]);
        $this->assertSame(120.0, $agency->fresh()->hourly_rate);
    }

    public function test_agency_logo_upload(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$agency] = $this->agencyWithSite();

        $this->post("/agencies/{$agency->id}", ['_method' => 'put', 'name' => 'Studio XYZ', 'white_label' => '1', 'logo' => UploadedFile::fake()->image('logo.png')]);
        $logo = $agency->fresh()->logo;
        $this->assertNotNull($logo);
        Storage::disk('public')->assertExists($logo);

        $this->post("/agencies/{$agency->id}", ['_method' => 'put', 'name' => 'Studio XYZ', 'remove_logo' => '1']);
        $this->assertNull($agency->fresh()->logo);
        Storage::disk('public')->assertMissing($logo);
    }

    public function test_white_label_reports_use_agency_brand(): void
    {
        Setting::put(['agency_name' => 'Moja Firma Wykonawca', 'agency_email' => 'ja@moja-firma.pl']);
        [$agency, $client] = $this->agencyWithSite();
        $audit = $client->siteAudits()->create(['url' => $client->website_url, 'status' => 'done', 'score' => 80, 'pages_crawled' => 1,
            'summary' => ['pages' => 1, 'html_pages' => 1, 'sitemap_urls' => 0, 'counts' => []], 'issues' => [], 'pages' => [], 'finished_at' => now()]);

        $this->get("/a/{$client->report_token}/{$audit->id}")->assertOk()
            ->assertSee('Studio XYZ')->assertSee('#ff0055', false)->assertSee('Studio XYZ – opieka nad stronami')
            ->assertDontSee('Moja Firma Wykonawca')->assertDontSee('ja@moja-firma.pl');

        $report = Report::create(['client_id' => $client->id, 'type' => 'work', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'data' => ['items' => []]]);
        $mail = new ReportReadyMail($report);
        $html = $mail->render();
        $this->assertStringContainsString('Studio XYZ', $html);
        $this->assertStringNotContainsString('Moja Firma Wykonawca', $html);
        $this->assertStringNotContainsString(config('app.name'), $html);
        $this->assertTrue($mail->hasReplyTo('biuro@studio-xyz.pl'));
        $this->assertSame('Studio XYZ', $mail->envelope()->from->name);
    }

    public function test_regular_agency_keeps_own_brand(): void
    {
        Setting::put(['agency_name' => 'Moja Firma Wykonawca']);
        [, $client] = $this->agencyWithSite(false);

        $this->assertSame('Moja Firma Wykonawca', Setting::brandFor($client)['name']);
        $this->assertFalse(Setting::brandFor($client)['white_label']);
        $this->assertSame('Moja Firma Wykonawca', Setting::brandFor(null)['name']);
    }

    public function test_llms_txt_generator_groups_pages(): void
    {
        $client = Client::create(['name' => 'Piekarnia Złoty Kłos', 'website_url' => 'https://piekarnia.pl', 'currency' => 'PLN', 'industry' => 'piekarnia rzemieślnicza']);
        $p = fn ($url, $title, $desc = '', $extra = []) => ['url' => $url, 'status' => 200, 'title' => $title, 'description' => $desc] + $extra;
        $text = app(LlmsTxtGenerator::class)->generate($client, [
            $p('https://piekarnia.pl/', 'Piekarnia Złoty Kłos – chleb na zakwasie', 'Rzemieślnicza piekarnia w Krakowie.'),
            $p('https://piekarnia.pl/oferta/chleby', 'Chleby | Piekarnia Złoty Kłos', 'Chleby na naturalnym zakwasie.'),
            $p('https://piekarnia.pl/kontakt', 'Kontakt | Piekarnia Złoty Kłos'),
            $p('https://piekarnia.pl/o-nas', 'O nas | Piekarnia Złoty Kłos'),
            $p('https://piekarnia.pl/polityka-prywatnosci', 'Polityka prywatności | Piekarnia Złoty Kłos'),
            $p('https://piekarnia.pl/blog/page/2', 'Blog – strona 2 | Piekarnia Złoty Kłos'),
            $p('https://piekarnia.pl/ukryta', 'Ukryta | Piekarnia Złoty Kłos', '', ['noindex' => true]),
            ['url' => 'https://piekarnia.pl/stara', 'status' => 404, 'title' => ''],
        ]);

        $this->assertStringStartsWith("# Piekarnia Złoty Kłos\n\n> Rzemieślnicza piekarnia w Krakowie.", $text);
        $this->assertStringContainsString("## Oferta\n\n- [Chleby](https://piekarnia.pl/oferta/chleby): Chleby na naturalnym zakwasie.", $text);
        $this->assertStringContainsString("## O firmie\n\n- [O nas](https://piekarnia.pl/o-nas)", $text);
        $this->assertStringContainsString('## Kontakt', $text);
        $this->assertStringContainsString("## Optional\n\n- [Polityka prywatności]", $text);
        $this->assertStringNotContainsString('page/2', $text);
        $this->assertStringNotContainsString('ukryta', $text);
        $this->assertStringNotContainsString('stara', $text);

        // wygenerowany plik przechodzi własną walidację audytu
        $check = app(SiteAuditor::class);
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->assertSame([], $check->validateLlmsTxt($text, 'https://piekarnia.pl/')['problems']);
    }

    public function test_llms_txt_page(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::create(['name' => 'Piekarnia', 'website_url' => 'https://piekarnia.pl', 'currency' => 'PLN']);

        $this->get("/clients/{$client->id}/llms-txt")->assertInertia(fn (Assert $p) => $p->component('clients/llms-txt')->where('text', null));

        $client->siteAudits()->create(['url' => $client->website_url, 'status' => 'done', 'score' => 90, 'pages_crawled' => 1, 'finished_at' => now(),
            'summary' => ['llms_txt' => false], 'issues' => [['code' => 'no_llms_txt', 'severity' => 'low', 'title' => 'Brak pliku llms.txt', 'description' => 'x', 'action' => 'y', 'count' => 1, 'urls' => [], 'details' => null, 'scope' => 'site']],
            'pages' => [['url' => 'https://piekarnia.pl/', 'status' => 200, 'title' => 'Piekarnia', 'description' => 'Chleb.']]]);

        $this->get("/clients/{$client->id}/llms-txt")->assertInertia(fn (Assert $p) => $p
            ->where('text', fn ($t) => str_starts_with($t, '# Piekarnia'))
            ->where('audit.issues.0.code', 'no_llms_txt'));
    }
}
