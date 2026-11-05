<?php

namespace Tests\Feature;

use App\Mail\ClientAlertMail;
use App\Models\Agency;
use App\Models\BillingItem;
use App\Models\CareTask;
use App\Models\Client;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebAudit;
use App\Services\Connectors\SearchConsoleConnector;
use App\Services\Sales\Followups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Pulpit „Dziś”, stoper, zgłoszenia, przypomnienia o kontakcie, rentowność, faktury, Search Console, wyszukiwarka. */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['is_admin' => true]);
        $this->actingAs($u);

        return $u;
    }

    private function client(array $attrs = []): Client
    {
        return Client::create($attrs + ['name' => 'Piekarnia', 'website_url' => 'https://piekarnia.pl', 'currency' => 'PLN', 'has_care' => true, 'contact_email' => 'jan@piekarnia.pl']);
    }

    private function task(Client $c, array $attrs = []): CareTask
    {
        return $c->careTasks()->create($attrs + ['title' => 'Aktualizacja', 'due_date' => now()->toDateString(), 'period' => now()->format('Y-m')]);
    }

    public function test_home_redirects_to_today_with_tasks_followups_and_money(): void
    {
        $this->admin();
        $c = $this->client();
        $this->task($c, ['title' => 'Zaległe', 'due_date' => now()->subDays(2)->toDateString()]);
        $this->task($c, ['title' => 'Za tydzień', 'due_date' => now()->addDays(5)->toDateString()]);
        $this->task($c, ['title' => 'Za miesiąc', 'due_date' => now()->addDays(40)->toDateString()]);
        Quote::newFor($c)->update(['status' => 'sent', 'sent_at' => now()->subDays(6)]);
        $this->task($c, ['title' => 'Praca', 'status' => 'done', 'done_at' => now(), 'amount' => 300]);

        $this->get('/')->assertRedirect('/today');
        $this->get('/today')->assertInertia(fn (Assert $p) => $p->component('today')
            ->has('tasks', 1)->where('tasks.0.title', 'Zaległe')->where('tasks.0.client.name', 'Piekarnia')
            ->has('upcoming', 1)
            ->has('followups', 1)->where('followups.0.type', 'quote')
            ->where('money.extra_open', 300));
    }

    public function test_timer_start_stop_one_at_a_time_and_done_adds_time(): void
    {
        $this->admin();
        $c = $this->client();
        $a = $this->task($c, ['title' => 'A', 'minutes' => 10]);
        $b = $this->task($c, ['title' => 'B']);

        $this->travelTo(now()->setTime(10, 0));
        $this->post("/care/tasks/{$a->id}/timer/start");
        $this->travel(25)->minutes();
        $this->post("/care/tasks/{$b->id}/timer/start"); // zatrzymuje A
        $this->assertSame(35, $a->fresh()->minutes);
        $this->assertNull($a->fresh()->timer_started_at);
        $this->assertNotNull($b->fresh()->timer_started_at);

        $this->get('/today')->assertInertia(fn (Assert $p) => $p->where('timer.id', $b->id));

        $this->travel(90)->seconds();
        $this->patch("/care/tasks/{$b->id}", ['status' => 'done']); // odhaczenie dolicza czas
        $this->assertSame(2, $b->fresh()->minutes);
        $this->assertNull($b->fresh()->timer_started_at);
    }

    public function test_client_request_form_creates_task_and_notifies(): void
    {
        Mail::fake();
        User::factory()->create(['is_admin' => true, 'email' => 'ja@agencja.pl']);
        $c = $this->client();
        $url = $c->requestUrl();

        $this->get($url)->assertOk()->assertSee('Piekarnia')->assertSee('Wyślij zgłoszenie');
        $this->post($url, ['name' => 'Jan', 'email' => 'jan@piekarnia.pl', 'title' => 'Nowy cennik', 'description' => 'Podmień ceny chleba', 'urgent' => '1'])
            ->assertOk()->assertSee('zgłoszenie przyjęte');

        $task = CareTask::where('source', 'request')->firstOrFail();
        $this->assertSame($c->id, $task->client_id);
        $this->assertSame('PILNE: Nowy cennik', $task->title);
        $this->assertSame('Jan', $task->requester['name']);
        Mail::assertSent(ClientAlertMail::class, fn ($m) => $m->hasTo('ja@agencja.pl'));

        // bot (pole-pułapka) – nic nie powstaje
        $this->post($url, ['website' => 'spam', 'name' => 'x', 'title' => 'x', 'description' => 'x'])->assertOk();
        $this->assertSame(1, CareTask::where('source', 'request')->count());

        $this->admin();
        $this->get('/requests')->assertInertia(fn (Assert $p) => $p->has('tasks', 1)->where('counts.todo', 1));
        $this->get('/zgloszenie/zly-token')->assertNotFound();
    }

    public function test_agency_request_form_only_accepts_its_sites_and_white_label_brand(): void
    {
        Setting::put(['agency_name' => 'Moja Firma']);
        $agency = Agency::create(['name' => 'Studio XYZ', 'white_label' => true]);
        $own = $this->client(['agency_id' => $agency->id]);
        $other = $this->client(['name' => 'Obcy']);

        $this->post($agency->requestUrl(), ['client_id' => $other->id, 'name' => 'A', 'title' => 'T', 'description' => 'D'])->assertSessionHasErrors('client_id');
        $this->post($agency->requestUrl(), ['client_id' => $own->id, 'name' => 'A', 'title' => 'T', 'description' => 'D'])->assertOk();
        $this->assertSame('Studio XYZ', CareTask::where('source', 'request')->first()->requester['agency']);

        // klient agencji white label widzi markę agencji, nie Twoją
        $this->get($own->requestUrl())->assertSee('Studio XYZ')->assertDontSee('Moja Firma');
    }

    public function test_followups_for_quotes_and_audits(): void
    {
        $this->admin();
        $c = $this->client();
        $q = Quote::newFor($c);
        $q->update(['status' => 'sent', 'sent_at' => now()->subDays(2)]);
        $audit = WebAudit::create(['url' => 'https://prospekt.pl', 'name' => 'Prospekt', 'email' => 'p@prospekt.pl', 'status' => 'done', 'score' => 54,
            'token' => 'x', 'finished_at' => now()->subDays(6)]);

        $due = app(Followups::class)->due();
        $this->assertSame(['audit'], $due->pluck('type')->all(), 'oferta wysłana 2 dni temu – jeszcze za wcześnie');

        $this->travel(4)->days();
        $this->assertCount(2, app(Followups::class)->due());

        $this->post("/followups/quote/{$q->id}", ['action' => 'contacted']);
        $this->post("/followups/audit/{$audit->id}", ['action' => 'off']);
        $this->assertCount(0, app(Followups::class)->due());
        $this->travel(8)->days();
        $this->assertSame(['quote'], app(Followups::class)->due()->pluck('type')->all());
    }

    public function test_profitability_effective_hourly_rate(): void
    {
        $this->admin();
        Setting::put(['target_hourly_rate' => '150']);
        $agency = Agency::create(['name' => 'Studio', 'hourly_rate' => 120]);
        $a = $this->client(['name' => 'Abonament']);
        BillingItem::create(['client_id' => $a->id, 'type' => 'subscription', 'name' => 'Opieka', 'amount' => 300, 'currency' => 'PLN', 'period' => 'monthly', 'active' => true]);
        $this->task($a, ['status' => 'done', 'done_at' => now(), 'minutes' => 240]); // 300 zł / 4 h = 75 zł/h
        $b = $this->client(['name' => 'Agencyjny', 'agency_id' => $agency->id]);
        $this->task($b, ['status' => 'done', 'done_at' => now(), 'minutes' => 60]); // 1 h × 120 zł

        $this->get('/profitability?months=1')->assertInertia(fn (Assert $p) => $p->component('finance/profitability')
            ->where('data.rows.0.name', 'Abonament')->where('data.rows.0.effective_rate', 75)->where('data.rows.0.flag', 'low')
            ->where('data.rows.1.revenue', 120)->where('data.rows.1.effective_rate', 120));
    }

    public function test_invoice_from_fakturownia_marks_work_and_advances_subscription(): void
    {
        $user = $this->admin();
        Setting::put(['fakturownia_domain' => 'mojafirma', 'fakturownia_token' => Crypt::encryptString('SEKRET')]);
        $c = $this->client(['billing_name' => 'Piekarnia Kowalski', 'tax_id' => '1234567890', 'billing_city' => 'Kraków']);
        $work = $this->task($c, ['title' => 'Nowy formularz', 'status' => 'done', 'done_at' => now(), 'amount' => 300]);
        $sub = BillingItem::create(['client_id' => $c->id, 'type' => 'subscription', 'name' => 'Opieka', 'amount' => 400, 'currency' => 'PLN',
            'period' => 'monthly', 'next_date' => now()->startOfMonth()->addDays(9)->toDateString(), 'active' => true]);

        Http::fake([
            'mojafirma.fakturownia.pl/invoices.json' => Http::response(['id' => 991, 'number' => 'FV 1/11/2026', 'token' => 'abc'], 201),
            'mojafirma.fakturownia.pl/invoices/991/send_by_email.json*' => Http::response(['status' => 'ok']),
        ]);

        $draft = $this->getJson("/invoices/draft?client_id={$c->id}")->assertOk()->json();
        $this->assertSame(['task-'.$work->id, 'sub-'.$sub->id], array_column($draft['lines'], 'key'));
        $this->assertSame('Piekarnia Kowalski', $draft['buyer']['name']);

        $this->post('/invoices', ['client_id' => $c->id, 'keys' => array_column($draft['lines'], 'key'), 'send' => true])->assertSessionHas('success');

        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/invoices.json')) {
                return false;
            }
            $inv = $r['invoice'];

            return $r['api_token'] === 'SEKRET' && $inv['buyer_tax_no'] === '1234567890' && $inv['positions'][0]['tax'] === 'zw'
                && $inv['positions'][0]['total_price_gross'] == 300 && count($inv['positions']) === 2;
        });
        $invoice = Invoice::firstOrFail();
        $this->assertSame('FV 1/11/2026', $invoice->number);
        $this->assertSame(700.0, $invoice->total);
        $this->assertSame('sent', $invoice->status);
        $this->assertSame('https://mojafirma.fakturownia.pl/invoice/abc', $invoice->view_url);
        $this->assertSame($invoice->id, $work->fresh()->invoice_id);
        $this->assertNotNull($work->fresh()->invoiced_at);
        $this->assertSame(now()->startOfMonth()->addDays(9)->addMonthNoOverflow()->toDateString(), $sub->fresh()->next_date);

        $this->post("/invoices/{$invoice->id}/paid");
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->get('/invoices')->assertInertia(fn (Assert $p) => $p->has('invoices', 1)->where('unpaid', 0));
    }

    public function test_agency_invoice_uses_hourly_rate_for_work_without_amount(): void
    {
        $this->admin();
        $agency = Agency::create(['name' => 'Studio', 'hourly_rate' => 100, 'tax_id' => '999']);
        $c = $this->client(['agency_id' => $agency->id]);
        $this->task($c, ['title' => 'Poprawki', 'status' => 'done', 'done_at' => now(), 'minutes' => 90]);

        $draft = $this->getJson("/invoices/draft?agency_id={$agency->id}")->json();
        $this->assertSame(150.0, (float) $draft['lines'][0]['unit_price']);
        $this->assertSame('Piekarnia: Poprawki (1,5 h)', $draft['lines'][0]['name']);
        $this->assertFalse($draft['configured']);
    }

    public function test_settings_never_expose_fakturownia_token(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'two_factor_confirmed_at' => now()]));
        Setting::put(['fakturownia_token' => Crypt::encryptString('SEKRET')]);

        $this->get('/admin/settings')->assertInertia(fn (Assert $p) => $p->missing('settings.fakturownia_token')->where('settings.fakturownia_token_set', true));
    }

    public function test_search_console_sync_and_seo_page(): void
    {
        $this->admin();
        $c = $this->client();
        Integration::create(['client_id' => $c->id, 'provider' => 'google', 'status' => 'connected', 'access_token' => 't', 'refresh_token' => 'r',
            'token_expires_at' => now()->addHour(), 'settings' => ['gsc_site' => 'sc-domain:piekarnia.pl']]);

        Http::fake(function (Request $r) {
            $dims = $r['dimensions'] ?? [];
            if ($dims === ['date']) {
                return Http::response(['rows' => [
                    ['keys' => [now()->subDays(3)->toDateString()], 'clicks' => 12, 'impressions' => 300, 'position' => 8.2],
                    ['keys' => [now()->subDays(40)->toDateString()], 'clicks' => 6, 'impressions' => 200, 'position' => 11],
                ]]);
            }

            return Http::response(['rows' => [['keys' => [$dims[0] === 'query' ? 'chleb na zakwasie' : 'https://piekarnia.pl/oferta'], 'clicks' => 10, 'impressions' => 150, 'position' => 4.5]]]);
        });

        app(SearchConsoleConnector::class)->sync($c);
        $this->assertSame(2, DB::table('search_console_daily')->where('client_id', $c->id)->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sites/sc-domain%3Apiekarnia.pl/searchAnalytics/query'));

        $this->get("/clients/{$c->id}/seo")->assertInertia(fn (Assert $p) => $p->component('clients/seo')
            ->where('site', 'sc-domain:piekarnia.pl')
            ->where('summary.now.clicks', 12)->where('summary.prev.clicks', 6)
            ->where('queries.0.key', 'chleb na zakwasie')->where('pages.0.key', 'https://piekarnia.pl/oferta'));
    }

    public function test_quick_search(): void
    {
        $this->admin();
        $c = $this->client();
        Agency::create(['name' => 'Piekne Strony']);
        $this->task($c, ['title' => 'Piekarnia – baner']);

        $types = collect($this->getJson('/search?q=piek')->json('results'))->pluck('type')->all();
        $this->assertContains('Klient', $types);
        $this->assertContains('Agencja', $types);
        $this->assertContains('Zadanie', $types);
        $this->assertSame([], $this->getJson('/search?q=p')->json('results'));
    }
}
