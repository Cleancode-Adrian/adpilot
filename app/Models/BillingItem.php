<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pozycja rozliczeń klienta: abonament, domena, hosting, licencja – z datą kolejnej faktury / odnowienia. */
class BillingItem extends Model
{
    public const TYPES = [
        'subscription' => 'Abonament',
        'domain' => 'Domena',
        'hosting' => 'Hosting',
        'license' => 'Licencja / wtyczka',
        'other' => 'Inne',
    ];

    public const PERIODS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12, 'once' => 0];

    public const PERIOD_LABELS = ['monthly' => 'co miesiąc', 'quarterly' => 'co kwartał', 'yearly' => 'co rok', 'once' => 'jednorazowo'];

    protected $fillable = ['client_id', 'type', 'name', 'provider', 'amount', 'currency', 'period', 'next_date', 'auto_renew', 'remind_days', 'reminded_for', 'active', 'notes'];

    protected $casts = ['amount' => 'float', 'auto_renew' => 'boolean', 'active' => 'boolean', 'remind_days' => 'integer'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Kwota w przeliczeniu na miesiąc (do przychodu miesięcznego – MRR). */
    public function monthlyValue(): float
    {
        $months = self::PERIODS[$this->period] ?? 0;

        return $months && $this->amount ? round($this->amount / $months, 2) : 0.0;
    }

    /** „Rozliczono / odnowiono” – przesuwa datę o okres; jednorazowe się zamykają. */
    public function advance(): void
    {
        $months = self::PERIODS[$this->period] ?? 0;
        if (! $months || ! $this->next_date) {
            $this->update(['active' => false]);

            return;
        }
        $this->update(['next_date' => CarbonImmutable::parse($this->next_date)->addMonthsNoOverflow($months)->toDateString(), 'reminded_for' => null]);
    }

    public function daysLeft(): ?int
    {
        return $this->next_date ? (int) now()->startOfDay()->diffInDays(CarbonImmutable::parse($this->next_date), false) : null;
    }
}
