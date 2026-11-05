<?php

namespace App\Services\Billing;

use App\Models\Agency;
use App\Models\BillingItem;
use App\Models\CareTask;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Reports\WorkReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pozycje do faktury i wystawienie jej w Fakturowni.
 * Klient: niezafakturowane prace z kwotą + abonamenty z terminem do końca miesiąca.
 * Agencja: niezafakturowane prace na jej stronach (kwota albo czas × stawka) + abonamenty jej stron.
 */
class InvoiceService
{
    public function __construct(private Fakturownia $api) {}

    /** @return list<array{key:string, type:string, id:int, name:string, quantity:float, unit_price:float, site?:string}> */
    public function lines(Client|Agency $buyer): array
    {
        $clientIds = $buyer instanceof Agency ? $buyer->clients()->pluck('id')->all() : [$buyer->id];
        $rate = $buyer instanceof Agency ? $buyer->hourly_rate : null;
        $multi = count($clientIds) > 1 || $buyer instanceof Agency;
        $endOfMonth = CarbonImmutable::today()->endOfMonth()->toDateString();

        $lines = [];
        $tasks = CareTask::whereIn('client_id', $clientIds)->where('status', 'done')->whereNull('invoiced_at')
            ->where(fn ($q) => $q->where('amount', '>', 0)->when($rate, fn ($q) => $q->orWhere('minutes', '>', 0)))
            ->with('client:id,name')->orderBy('done_at')->get();
        foreach ($tasks as $t) {
            $value = $t->amount > 0 ? (float) $t->amount : round($t->minutes / 60 * $rate, 2);
            if ($value <= 0) {
                continue;
            }
            $byTime = ! ($t->amount > 0);
            $lines[] = [
                'key' => 'task-'.$t->id, 'type' => 'task', 'id' => $t->id,
                'name' => ($multi ? $t->client->name.': ' : '').$t->title.($byTime ? ' ('.$this->hours($t->minutes).' h)' : ''),
                'quantity' => 1, 'unit_price' => $value, 'site' => $t->client->name,
            ];
        }

        $subs = BillingItem::whereIn('client_id', $clientIds)->where('active', true)->where('type', 'subscription')
            ->whereNotNull('next_date')->where('next_date', '<=', $endOfMonth)->where('amount', '>', 0)->with('client:id,name')->orderBy('next_date')->get();
        foreach ($subs as $b) {
            $lines[] = [
                'key' => 'sub-'.$b->id, 'type' => 'subscription', 'id' => $b->id,
                'name' => ($multi ? $b->client->name.': ' : '').$b->name.' – '.$this->periodLabel($b),
                'quantity' => 1, 'unit_price' => (float) $b->amount, 'site' => $b->client->name,
            ];
        }

        return $lines;
    }

    /** Dane nabywcy do faktury; null = brakuje danych (nazwa). */
    public function buyer(Client|Agency $b): array
    {
        return [
            'name' => $b->billing_name ?: $b->name,
            'tax_no' => $b->tax_id,
            'street' => $b->billing_street,
            'post_code' => $b->billing_post_code,
            'city' => $b->billing_city,
            'email' => $b->billing_email ?: $b->contact_email,
        ];
    }

    /**
     * Wystawia fakturę z wybranych pozycji (klucze z lines()).
     * Prace → zafakturowane, abonamenty → termin przesunięty o okres.
     */
    public function issue(Client|Agency $buyer, array $keys, bool $send, ?int $userId = null): Invoice
    {
        $lines = array_values(array_filter($this->lines($buyer), fn ($l) => in_array($l['key'], $keys, true)));
        if (! $lines) {
            throw new RuntimeException('Wybierz co najmniej jedną pozycję.');
        }
        $currency = $buyer instanceof Agency ? ($buyer->currency ?: 'PLN') : ($buyer->currency ?: 'PLN');
        $data = $this->buyer($buyer);

        $created = $this->api->createInvoice($data, array_map(fn ($l) => ['name' => $l['name'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']], $lines), $currency);

        $invoice = DB::transaction(function () use ($buyer, $lines, $created, $data, $currency, $userId) {
            $invoice = Invoice::create([
                'client_id' => $buyer instanceof Client ? $buyer->id : null,
                'agency_id' => $buyer instanceof Agency ? $buyer->id : null,
                'external_id' => $created['id'], 'number' => $created['number'], 'kind' => Setting::get('invoice_kind', 'vat'),
                'buyer_name' => $data['name'], 'total' => round(array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines)), 2),
                'currency' => $currency, 'issue_date' => now()->toDateString(),
                'payment_to' => now()->addDays((int) Setting::get('invoice_payment_days', 7))->toDateString(),
                'view_url' => $created['view_url'], 'status' => 'issued',
                'items' => array_map(fn ($l) => array_intersect_key($l, array_flip(['name', 'quantity', 'unit_price'])), $lines),
                'created_by' => $userId,
            ]);
            $taskIds = array_column(array_filter($lines, fn ($l) => $l['type'] === 'task'), 'id');
            CareTask::whereIn('id', $taskIds)->update(['invoiced_at' => now(), 'invoice_id' => $invoice->id]);
            BillingItem::whereIn('id', array_column(array_filter($lines, fn ($l) => $l['type'] === 'subscription'), 'id'))->get()->each->advance();

            return $invoice;
        });

        if ($send) {
            $this->send($invoice);
        }

        return $invoice;
    }

    public function send(Invoice $invoice): void
    {
        $this->api->sendByEmail($invoice->external_id);
        $invoice->update(['status' => $invoice->status === 'paid' ? 'paid' : 'sent', 'sent_at' => now()]);
    }

    private function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, ',', ''), '0'), ',');
    }

    private function periodLabel(BillingItem $b): string
    {
        $d = CarbonImmutable::parse($b->next_date);

        return match ($b->period) {
            'monthly' => WorkReportBuilder::monthLabel($d),
            'quarterly' => 'kwartał od '.$d->format('m.Y'),
            'yearly' => 'rok od '.$d->format('m.Y'),
            default => $d->format('d.m.Y'),
        };
    }
}
