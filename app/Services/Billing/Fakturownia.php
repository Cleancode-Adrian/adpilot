<?php

namespace App\Services\Billing;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fakturownia.pl – wystawianie faktur przez API (POST https://{konto}.fakturownia.pl/invoices.json, api_token w treści).
 * Bez VAT: pozycje ze stawką „zw” (+ opcjonalnie podstawa zwolnienia), rodzaj dokumentu z ustawień (faktura / rachunek).
 */
class Fakturownia
{
    public static function configured(): bool
    {
        return filled(Setting::get('fakturownia_domain')) && filled(Setting::get('fakturownia_token'));
    }

    public static function domain(): string
    {
        // dopuszczamy „firma”, „firma.fakturownia.pl” i pełny adres
        $d = strtolower(trim((string) Setting::get('fakturownia_domain')));
        $d = preg_replace('#^https?://#', '', $d);

        return preg_replace('#\.fakturownia\.pl.*$#', '', $d);
    }

    private function base(): string
    {
        return 'https://'.self::domain().'.fakturownia.pl';
    }

    private function token(): string
    {
        $raw = (string) Setting::get('fakturownia_token');
        try {
            return Crypt::decryptString($raw);
        } catch (Throwable) {
            return $raw;
        }
    }

    /** Sprawdza dostęp (lista faktur z bieżącego miesiąca, 1 pozycja). */
    public function test(): void
    {
        $res = Http::acceptJson()->timeout(20)->get($this->base().'/invoices.json', ['period' => 'this_month', 'page' => 1, 'per_page' => 1, 'api_token' => $this->token()]);
        if ($res->failed()) {
            throw new RuntimeException('Fakturownia: '.($res->status() === 401 ? 'nieprawidłowy kod API' : ($res->json('message') ?: 'błąd '.$res->status())));
        }
    }

    /**
     * @param  array{name:string, tax_no?:?string, street?:?string, post_code?:?string, city?:?string, email?:?string}  $buyer
     * @param  list<array{name:string, quantity:float|int, unit_price:float}>  $positions  ceny netto (bez VAT = kwoty końcowe)
     * @return array{id:string, number:?string, view_url:string}
     */
    public function createInvoice(array $buyer, array $positions, string $currency = 'PLN', ?string $description = null): array
    {
        $vat = Setting::vatRate();
        $days = (int) Setting::get('invoice_payment_days', 7);
        $invoice = array_filter([
            'kind' => Setting::get('invoice_kind', 'vat'),
            'issue_date' => now()->toDateString(),
            'sell_date' => now()->toDateString(),
            'payment_to_kind' => $days,
            'payment_type' => 'transfer',
            'currency' => $currency,
            'buyer_name' => $buyer['name'],
            'buyer_tax_no' => $buyer['tax_no'] ?? null,
            'buyer_street' => $buyer['street'] ?? null,
            'buyer_post_code' => $buyer['post_code'] ?? null,
            'buyer_city' => $buyer['city'] ?? null,
            'buyer_email' => $buyer['email'] ?? null,
            'description' => $description,
            'exempt_tax_kind' => $vat === null ? (Setting::get('invoice_exempt_basis') ?: null) : null,
            'positions' => array_map(fn ($p) => [
                'name' => mb_substr($p['name'], 0, 250),
                'quantity' => $p['quantity'],
                'tax' => $vat === null ? 'zw' : $vat,
                'total_price_gross' => round($p['quantity'] * $p['unit_price'] * (1 + ($vat ?? 0) / 100), 2),
            ], $positions),
        ], fn ($v) => $v !== null && $v !== '');

        $res = Http::acceptJson()->asJson()->timeout(30)->post($this->base().'/invoices.json', ['api_token' => $this->token(), 'invoice' => $invoice]);
        if ($res->failed() || ! $res->json('id')) {
            $msg = $res->json('message');
            $msg = is_array($msg) ? json_encode($msg, JSON_UNESCAPED_UNICODE) : $msg;
            throw new RuntimeException('Fakturownia: '.($msg ?: 'błąd '.$res->status().' '.mb_substr($res->body(), 0, 300)));
        }

        $id = (string) $res->json('id');
        $token = $res->json('token');

        return [
            'id' => $id,
            'number' => $res->json('number'),
            'view_url' => $token ? $this->base().'/invoice/'.$token : $this->base().'/invoices/'.$id,
        ];
    }

    /** Wysyła fakturę e-mailem do nabywcy (z PDF w załączniku). */
    public function sendByEmail(string $id, ?string $email = null): void
    {
        $res = Http::acceptJson()->timeout(30)->post($this->base()."/invoices/{$id}/send_by_email.json?".http_build_query(array_filter([
            'api_token' => $this->token(), 'email_pdf' => 'true', 'email_to' => $email,
        ])));
        if ($res->failed()) {
            throw new RuntimeException('Fakturownia (wysyłka): '.($res->json('message') ?: 'błąd '.$res->status()));
        }
    }
}
