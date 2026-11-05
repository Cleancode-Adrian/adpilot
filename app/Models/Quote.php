<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Oferta dla klienta – link do akceptacji online i PDF. */
class Quote extends Model
{
    public const STATUSES = ['draft' => 'Szkic', 'sent' => 'Wysłana', 'accepted' => 'Zaakceptowana', 'rejected' => 'Odrzucona'];

    protected $fillable = ['client_id', 'number', 'title', 'intro', 'terms', 'status', 'valid_until', 'currency', 'vat_rate', 'token', 'followup_at', 'followup_off',
        'sent_at', 'viewed_at', 'decided_at', 'decided_name', 'decided_ip', 'rejection_reason', 'created_by'];

    protected $hidden = ['token'];

    protected $casts = ['vat_rate' => 'float', 'followup_at' => 'datetime', 'followup_off' => 'boolean', 'sent_at' => 'datetime', 'viewed_at' => 'datetime', 'decided_at' => 'datetime'];

    public static function nextNumber(): string
    {
        $prefix = 'OF/'.now()->format('Y/m').'/';
        $last = self::where('number', 'like', $prefix.'%')->orderByDesc('id')->value('number');

        return $prefix.str_pad((string) ((int) ($last ? substr($last, strlen($prefix)) : 0) + 1), 3, '0', STR_PAD_LEFT);
    }

    public static function newFor(Client $client, array $attrs = []): self
    {
        return self::create($attrs + [
            'client_id' => $client->id,
            'number' => self::nextNumber(),
            'title' => 'Oferta',
            'currency' => $client->currency ?: 'PLN',
            'valid_until' => now()->addDays(14)->toDateString(),
            'token' => Str::random(40),
            'created_by' => auth()->id(),
            // stawka VAT z ustawień agencji (null = bez VAT – ceny są cenami końcowymi)
            'vat_rate' => Setting::vatRate(),
            'terms' => (Setting::vatRate() === null
                ? "Podane ceny są cenami końcowymi (sprzedawca nie jest płatnikiem VAT).\n"
                : "Ceny netto. Do cen należy doliczyć podatek VAT.\n")
                ."Termin realizacji ustalamy po akceptacji oferty.\nPłatność: przelew 7 dni od wystawienia faktury.",
        ]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position')->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->status === 'sent' && $this->valid_until && now()->startOfDay()->gt($this->valid_until);
    }

    /** Sumy netto/VAT/brutto osobno dla płatności jednorazowych, miesięcznych i rocznych. */
    public function totals(): array
    {
        $vat = $this->vat_rate;
        $groups = [];
        foreach ($this->items as $i) {
            $key = $i->recurring ?: 'once';
            $groups[$key] = ($groups[$key] ?? 0) + round((float) $i->quantity * (float) $i->unit_price, 2);
        }
        $out = [];
        foreach (['once' => 'Jednorazowo', 'monthly' => 'Miesięcznie', 'yearly' => 'Rocznie'] as $key => $label) {
            if (! isset($groups[$key])) {
                continue;
            }
            $net = round($groups[$key], 2);
            $tax = $vat === null ? 0.0 : round($net * $vat / 100, 2);
            $out[$key] = ['label' => $label, 'net' => $net, 'vat' => $tax, 'gross' => round($net + $tax, 2)];
        }

        return $out;
    }
}
