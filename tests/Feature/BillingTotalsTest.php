<?php

namespace Tests\Feature;

use App\Models\BillingItem;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Rozliczenia: prace dodatkowe w przychodzie miesiąca i do zafakturowania; VAT (domyślnie brak – jedna kwota). */
class BillingTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_extra_work_counts_in_month_revenue_and_to_invoice(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $client = Client::create(['name' => 'Dentus', 'website_url' => 'https://dentus.pl', 'currency' => 'PLN', 'has_care' => true]);
        BillingItem::create(['client_id' => $client->id, 'type' => 'subscription', 'name' => 'Opieka', 'amount' => 400, 'currency' => 'PLN',
            'period' => 'monthly', 'next_date' => now()->addMonth()->startOfMonth()->addDays(9)->toDateString(), 'active' => true]);
        $task = $client->careTasks()->create(['title' => 'Wdrożenie zmian z audytu SEO', 'period' => now()->format('Y-m'), 'status' => 'done',
            'done_at' => now(), 'due_date' => now()->toDateString(), 'amount' => 300]);
        $client->careTasks()->create(['title' => 'Zeszły miesiąc', 'period' => now()->subMonth()->format('Y-m'), 'status' => 'done',
            'done_at' => now()->subMonthNoOverflow()->startOfMonth(), 'due_date' => now()->toDateString(), 'amount' => 50, 'invoiced_at' => now()]);

        $this->get('/billing')->assertInertia(fn (Assert $p) => $p
            ->where('mrrSubscriptions', 400)
            ->where('extraThisMonth', 300)   // tylko prace z tego miesiąca
            ->where('extraOpen', 300)        // niezafakturowane
            ->where('vat_rate', null));      // domyślnie bez VAT – jedna kwota

        $task->update(['invoiced_at' => now()]);
        $this->get('/billing')->assertInertia(fn (Assert $p) => $p->where('extraThisMonth', 300)->where('extraOpen', 0));
    }

    public function test_default_is_no_vat_single_amount(): void
    {
        $this->assertNull(Setting::vatRate());
        $this->assertSame('', Setting::netSuffix());
        $this->assertSame(400.0, Setting::gross(400));
    }

    public function test_vat_payer_setting_adds_vat(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Setting::put(['vat_rate' => '23']);
        $this->assertSame(23.0, Setting::vatRate());
        $this->assertSame(' netto', Setting::netSuffix());
        $this->assertSame(492.0, Setting::gross(400));

        $quote = Quote::newFor(Client::create(['name' => 'Firma', 'website_url' => 'https://firma.pl', 'currency' => 'PLN']));
        $this->assertSame(23.0, $quote->fresh()->vat_rate);
        $this->assertStringContainsString('doliczyć podatek VAT', $quote->terms);

        Setting::put(['vat_rate' => '8']);
        $this->assertSame(432.0, Setting::gross(400));
    }

    public function test_public_quote_without_vat_shows_single_amount(): void
    {
        $quote = Quote::newFor(Client::create(['name' => 'Firma', 'website_url' => 'https://firma.pl', 'currency' => 'PLN']));
        $quote->items()->create(['name' => 'Strona www', 'quantity' => 1, 'unit' => 'szt.', 'unit_price' => 3000, 'position' => 1]);

        $this->get("/o/{$quote->token}")->assertOk()
            ->assertSee('do zapłaty')
            ->assertDontSee('netto')
            ->assertDontSee('brutto')
            ->assertDontSee('VAT 23');
    }
}
