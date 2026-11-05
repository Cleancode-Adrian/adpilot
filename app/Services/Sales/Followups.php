<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\WebAudit;
use Illuminate\Support\Collection;

/**
 * Przypomnienia o kontakcie z potencjalnym klientem:
 * oferta wysłana bez decyzji i audyt strony bez zamiany w klienta – po X dniach „zadzwoń / napisz”.
 * Po kontakcie przypomnienie wraca za 7 dni, aż do decyzji albo wyłączenia.
 */
class Followups
{
    public const FIRST_AFTER_DAYS = 5;

    public const NEXT_AFTER_DAYS = 7;

    /** @return Collection<int, array> */
    public function due(): Collection
    {
        $now = now();
        $quotes = Quote::where('status', 'sent')->where('followup_off', false)->whereNotNull('sent_at')->with('client:id,name,contact_name,contact_email,contact_phone')->get()
            ->filter(fn (Quote $q) => ($q->followup_at ?? $q->sent_at->copy()->addDays(self::FIRST_AFTER_DAYS))->lte($now))
            ->map(fn (Quote $q) => [
                'type' => 'quote',
                'id' => $q->id,
                'title' => "Oferta {$q->number}: {$q->title}",
                'who' => $q->client?->name,
                'contact' => array_filter(['name' => $q->client?->contact_name, 'email' => $q->client?->contact_email, 'phone' => $q->client?->contact_phone]),
                'since' => $q->sent_at,
                'hint' => $q->viewed_at ? 'Klient otworzył ofertę '.$q->viewed_at->format('d.m H:i').' – zapytaj, czy ma pytania.' : 'Klient jeszcze nie otworzył oferty – sprawdź, czy dotarła.',
                'url' => "/quotes/{$q->id}",
            ]);

        $audits = WebAudit::where('status', 'done')->whereNull('client_id')->where('followup_off', false)->whereNotNull('finished_at')
            ->where('finished_at', '>=', $now->copy()->subDays(60))->get()
            ->filter(fn (WebAudit $a) => ($a->followup_at ?? $a->finished_at->copy()->addDays(self::FIRST_AFTER_DAYS))->lte($now))
            ->map(fn (WebAudit $a) => [
                'type' => 'audit',
                'id' => $a->id,
                'title' => 'Audyt strony: '.preg_replace('#^https?://#', '', (string) $a->url),
                'who' => $a->name,
                'contact' => array_filter(['email' => $a->email]),
                'since' => $a->finished_at,
                'hint' => 'Wynik audytu: '.($a->score ?? '–').'/100 – zaproponuj rozmowę o poprawkach.',
                'url' => "/audits/{$a->id}",
            ]);

        return $quotes->concat($audits)->sortBy('since')->values();
    }

    /** contacted – przypomnij znowu za 7 dni; off – nie przypominaj. */
    public function handle(Quote|WebAudit $item, string $action): void
    {
        $item->update($action === 'off'
            ? ['followup_off' => true]
            : ['followup_at' => now()->addDays(self::NEXT_AFTER_DAYS)]);
    }
}
