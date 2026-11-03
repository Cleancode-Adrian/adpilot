<?php

namespace App\Services\Billing;

use App\Mail\ClientAlertMail;
use App\Models\BillingItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Przypomnienia o fakturach i odnowieniach + data wygaśnięcia domeny z rejestru (RDAP).
 * Przypomnienie = jednorazowe zadanie u klienta (widoczne w Zadaniach i porannym e-mailu) + e-mail zbiorczy.
 */
class BillingService
{
    public function remind(): int
    {
        $today = CarbonImmutable::today();
        $due = BillingItem::where('active', true)->whereNotNull('next_date')
            ->whereHas('client', fn ($q) => $q->active())->with('client')->get()
            ->filter(fn (BillingItem $b) => CarbonImmutable::parse($b->next_date)->lte($today->addDays($b->remind_days))
                && $b->reminded_for !== CarbonImmutable::parse($b->next_date)->toDateString());

        $lines = [];
        foreach ($due as $b) {
            $date = CarbonImmutable::parse($b->next_date);
            $title = ($b->type === 'subscription' ? 'Faktura: ' : 'Odnów: ').$b->name.(isset(BillingItem::TYPES[$b->type]) && $b->type !== 'subscription' ? ' ('.mb_strtolower(BillingItem::TYPES[$b->type]).')' : '');
            $b->client->careTasks()->create([
                'title' => $title,
                'description' => collect([
                    $b->provider ? 'Dostawca: '.$b->provider : null,
                    $b->amount ? 'Kwota: '.number_format($b->amount, 2, ',', ' ').' '.$b->currency.' netto ('.BillingItem::PERIOD_LABELS[$b->period].')' : null,
                    $b->auto_renew ? 'Odnawia się automatycznie – sprawdź, czy płatność przeszła.' : null,
                    'Po wykonaniu kliknij „Rozliczono / odnowiono” w zakładce Rozliczenia klienta.',
                ])->filter()->implode("\n"),
                'due_date' => $date->lt($today) ? $today->toDateString() : $date->toDateString(),
                'period' => ($date->lt($today) ? $today : $date)->format('Y-m'),
            ]);
            $b->update(['reminded_for' => $date->toDateString()]);
            $lines[] = '- **'.$b->client->name.'** – '.$title.' – '.$date->format('d.m.Y').($b->amount ? ' – '.number_format($b->amount, 2, ',', ' ').' '.$b->currency : '');
        }

        if ($lines) {
            try {
                foreach (User::where('is_admin', true)->pluck('email') as $email) {
                    Mail::to($email)->send(new ClientAlertMail('🧾 Faktury i odnowienia: '.count($lines), $lines, route('billing.index'),
                        'Zadania zostały dodane do klientów – odhacz je po wystawieniu faktury / odnowieniu.'));
                }
            } catch (Throwable $e) {
                Log::warning('Billing reminder mail failed: '.$e->getMessage());
            }
        }

        return count($lines);
    }

    /** Data wygaśnięcia domeny z publicznego rejestru RDAP (.pl – NASK, pozostałe – przez rdap.org). */
    public function domainExpiry(string $domain): ?CarbonImmutable
    {
        $domain = strtolower(trim(preg_replace('#^https?://|/.*$#i', '', $domain)));
        $domain = preg_replace('/^www\./', '', $domain);
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return null;
        }
        $url = str_ends_with($domain, '.pl') ? "https://rdap.dns.pl/domain/{$domain}" : "https://rdap.org/domain/{$domain}";
        try {
            $res = Http::timeout(15)->accept('application/rdap+json')->get($url);
        } catch (Throwable) {
            return null;
        }
        foreach ((array) $res->json('events', []) as $e) {
            if (($e['eventAction'] ?? null) === 'expiration' && ! empty($e['eventDate'])) {
                return CarbonImmutable::parse($e['eventDate']);
            }
        }

        return null;
    }
}
