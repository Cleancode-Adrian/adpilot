<?php

namespace Tests\Feature;

use App\Mail\ClientAlertMail;
use App\Mail\QuoteMail;
use App\Models\BillingItem;
use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Care\CarePlanner;
use App\Services\Reports\WorkReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Czas pracy, rozliczenia i odnowienia, oferty z akceptacją online. */
class AgencyToolsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function client(): Client
    {
        return Client::create(['name' => 'Firma', 'website_url' => 'https://firma.pl', 'currency' => 'PLN', 'has_care' => true, 'contact_email' => 'jan@firma.pl']);
    }

    public function test_time_tracking_on_tasks_and_in_work_report(): void
    {
        $this->actingAs($this->admin());
        $client = $this->client();
        $this->post("/clients/{$client->id}/care/tasks", ['title' => 'Aktualizacja', 'due_date' => now()->toDateString()]);
        $task = $client->careTasks()->first();

        $this->patch("/care/tasks/{$task->id}", ['status' => 'done', 'minutes' => 90, 'note' => 'Zrobione'])->assertRedirect();
        $this->assertSame(90, $task->fresh()->minutes);
        $this->assertSame(90, app(CarePlanner::class)->summary($client)['minutes']);

        $report = app(WorkReportBuilder::class)->build($client, now()->format('Y-m'));
        $this->assertSame(90, $report->data['items'][0]['minutes']);

        $this->put("/reports/{$report->id}/work", ['note' => null, 'show_time' => true, 'items' => [
            ['task_id' => $task->id, 'title' => 'Aktualizacja', 'description' => '', 'date' => now()->toDateString(), 'minutes' => 90],
            ['title' => 'Konsultacja', 'description' => '', 'date' => null, 'minutes' => 30],
        ]]);
        $this->get("/r/{$client->report_token}/{$report->id}")->assertOk()->assertSee('Czas pracy')->assertSee('2 h');

        $this->patch("/care/tasks/{$task->id}", ['minutes' => -5])->assertSessionHasErrors('minutes');
    }

    public function test_billing_items_mrr_advance_and_reminders(): void
    {
        Mail::fake();
        $this->actingAs($this->admin());
        $client = $this->client();

        $this->post("/clients/{$client->id}/billing", ['type' => 'subscription', 'name' => 'Opieka Standard', 'amount' => 300, 'period' => 'monthly', 'next_date' => now()->addDays(3)->toDateString(), 'remind_days' => 7])->assertRedirect();
        $this->post("/clients/{$client->id}/billing", ['type' => 'domain', 'name' => 'firma.pl', 'provider' => 'home.pl', 'amount' => 120, 'period' => 'yearly', 'next_date' => now()->addDays(40)->toDateString(), 'remind_days' => 30]);
        $this->post("/clients/{$client->id}/billing", ['type' => 'hosting', 'name' => 'Hosting', 'amount' => 600, 'period' => 'yearly', 'next_date' => now()->addMonths(6)->toDateString()]);

        $this->get('/billing')->assertOk()->assertInertia(fn ($p) => $p->component('billing')
            ->where('mrr', fn ($v) => abs($v - (300 + 10 + 50)) < 0.01)
            ->has('toInvoice', 1)->has('renewals', 1)); // domena za 40 dni mieści się w oknie 60 dni

        // przypomnienie: abonament (3 dni ≤ 7) – tak; domena (40 dni > 30) – nie; hosting – nie
        $this->assertSame(1, app(BillingService::class)->remind());
        $this->assertSame(1, $client->careTasks()->where('title', 'like', 'Faktura: Opieka Standard%')->count());
        Mail::assertSent(ClientAlertMail::class, fn ($m) => str_contains($m->title, 'Faktury i odnowienia: 1'));
        $this->assertSame(0, app(BillingService::class)->remind(), 'drugi raz bez duplikatów');

        // rozliczono → kolejny miesiąc, przypomnienie znów możliwe
        $sub = BillingItem::where('type', 'subscription')->first();
        $this->post("/billing/{$sub->id}/advance");
        $this->assertSame(now()->addDays(3)->addMonthNoOverflow()->toDateString(), $sub->fresh()->next_date);
        $this->assertNull($sub->fresh()->reminded_for);

        $this->get("/clients/{$client->id}/billing")->assertOk()->assertInertia(fn ($p) => $p->component('clients/billing')->has('items', 3));

        // tylko administrator
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/billing')->assertForbidden();
    }

    public function test_domain_expiry_from_rdap(): void
    {
        $this->actingAs($this->admin());
        $client = $this->client();
        $item = $client->billingItems()->create(['type' => 'domain', 'name' => 'https://www.firma.pl/', 'period' => 'yearly']);
        Http::fake(['rdap.dns.pl/domain/firma.pl' => Http::response(['events' => [['eventAction' => 'registration', 'eventDate' => '2015-01-10T00:00:00Z'], ['eventAction' => 'expiration', 'eventDate' => '2027-01-10T00:00:00Z']]])]);

        $this->post("/billing/{$item->id}/lookup")->assertSessionHas('success');
        $this->assertSame('2027-01-10', $item->fresh()->next_date);
    }

    public function test_quote_from_template_send_and_accept_online(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin);
        $client = $this->client();

        $this->post('/quotes', ['client_id' => $client->id, 'template' => 'website'])->assertRedirect();
        $quote = Quote::first();
        $this->assertSame('OF/'.now()->format('Y/m').'/001', $quote->number);
        $this->assertCount(5, $quote->items);
        $t = $quote->totals();
        $this->assertSame(7400.0, $t['once']['net']);
        $this->assertSame(1702.0, $t['once']['vat']);
        $this->assertSame(250.0, $t['monthly']['net']);

        // edycja pozycji
        $this->put("/quotes/{$quote->id}", ['title' => 'Strona Firma', 'intro' => 'Wstęp', 'terms' => 'Warunki', 'valid_until' => now()->addDays(14)->toDateString(), 'currency' => 'pln', 'vat_rate' => 23,
            'items' => [['name' => 'Strona', 'description' => '', 'quantity' => 1, 'unit' => 'szt.', 'unit_price' => 5000, 'recurring' => null],
                ['name' => 'Opieka', 'description' => '', 'quantity' => 1, 'unit' => 'mies.', 'unit_price' => 300, 'recurring' => 'monthly']]])->assertSessionHas('success');
        $quote->refresh();
        $this->assertSame(['once' => 5000.0, 'monthly' => 300.0], collect($quote->totals())->map->net->all());

        // wysyłka
        $this->post("/quotes/{$quote->id}/send", ['email' => 'jan@firma.pl'])->assertSessionHas('success');
        Mail::assertSent(QuoteMail::class, fn ($m) => $m->hasTo('jan@firma.pl'));
        $this->assertSame('sent', $quote->fresh()->status);

        // klient: podgląd (bez logowania), PDF, akceptacja
        auth()->logout();
        $this->get("/o/{$quote->token}")->assertOk()->assertSee('Strona Firma')->assertSee('Akceptuję ofertę')->assertSee('6 150,00 PLN');
        $this->assertNotNull($quote->fresh()->viewed_at);
        $this->get("/o/{$quote->token}/pdf")->assertOk();
        $this->post("/o/{$quote->token}", ['decision' => 'accept', 'name' => ''])->assertSessionHasErrors('name');
        $this->post("/o/{$quote->token}", ['decision' => 'accept', 'name' => 'Jan Kowalski', 'agree' => '1'])->assertSessionHas('quote_success');
        $quote->refresh();
        $this->assertSame('accepted', $quote->status);
        $this->assertSame('Jan Kowalski', $quote->decided_name);
        Mail::assertSent(ClientAlertMail::class, fn ($m) => str_contains($m->title, 'Oferta zaakceptowana'));

        // po decyzji: ponowna decyzja niemożliwa, edycja zablokowana, duplikat działa
        $this->post("/o/{$quote->token}", ['decision' => 'reject'])->assertSessionHas('quote_error');
        $this->actingAs($admin);
        $this->put("/quotes/{$quote->id}", ['title' => 'x', 'currency' => 'PLN', 'items' => []])->assertForbidden();
        $this->post("/quotes/{$quote->id}/duplicate")->assertRedirect();
        $this->assertSame('OF/'.now()->format('Y/m').'/002', Quote::latest('id')->value('number'));

        $this->get('/quotes')->assertOk()->assertInertia(fn ($p) => $p->component('quotes/index')->where('stats.accepted_month', 1)
            ->where('stats.accepted_value_month', fn ($v) => abs($v - (5000 + 12 * 300)) < 0.01));
        $this->get('/o/zly-token')->assertNotFound();
    }

    public function test_expired_quote_cannot_be_accepted(): void
    {
        $this->actingAs($this->admin());
        $quote = Quote::newFor($this->client(), ['status' => 'sent', 'valid_until' => now()->subDay()->toDateString()]);
        auth()->logout();
        $this->get("/o/{$quote->token}")->assertSee('Termin ważności oferty minął')->assertDontSee('Akceptuję ofertę');
        $this->post("/o/{$quote->token}", ['decision' => 'accept', 'name' => 'X', 'agree' => '1'])->assertSessionHas('quote_error');
        $this->assertSame('sent', $quote->fresh()->status);
    }
}
