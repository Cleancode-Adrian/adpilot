<?php

namespace App\Services\Billing;

use App\Models\Agency;
use App\Models\BillingItem;
use App\Models\CareTask;
use App\Services\Reports\WorkReportBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rozliczenie miesięczne z agencją: wszystkie prace wykonane w danym miesiącu na jej stronach
 * + abonamenty / odnowienia przypadające w tym miesiącu.
 * Wartość pracy: kwota wpisana przy zadaniu, a gdy jej brak – czas × stawka godzinowa agencji.
 */
class AgencySettlement
{
    public static function month(?string $month): CarbonImmutable
    {
        return $month && preg_match('/^\d{4}-\d{2}$/', $month)
            ? CarbonImmutable::createFromFormat('!Y-m', $month)
            : CarbonImmutable::now()->startOfMonth();
    }

    /** Zadania wykonane w miesiącu na stronach agencji. */
    public function tasksQuery(Agency $agency, CarbonImmutable $month): Builder
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        return CareTask::query()
            ->whereIn('client_id', $agency->clients()->select('id'))
            ->where('status', 'done')
            ->where(fn ($q) => $q->whereBetween('done_at', [$from, $to])
                ->orWhere(fn ($q) => $q->whereNull('done_at')->where('period', $month->format('Y-m'))));
    }

    public function build(Agency $agency, CarbonImmutable $month): array
    {
        $rate = $agency->hourly_rate;
        $tasks = $this->tasksQuery($agency, $month)->with('client:id,name,website_url')->orderBy('done_at')->get();

        $rows = $tasks->map(function (CareTask $t) use ($rate) {
            $minutes = (int) $t->minutes;
            $value = $t->amount !== null && $t->amount > 0 ? $t->amount : ($rate && $minutes ? round($minutes / 60 * $rate, 2) : null);

            return [
                'id' => $t->id,
                'client_id' => $t->client_id,
                'client' => $t->client?->name,
                'title' => $t->title,
                'note' => $t->note,
                'done_at' => $t->done_at?->toDateString() ?? $t->due_date,
                'minutes' => $minutes,
                'amount' => $t->amount,
                'value' => $value,
                'source' => $t->amount !== null && $t->amount > 0 ? 'amount' : ($value !== null ? 'time' : null),
                'invoiced' => $t->invoiced_at !== null,
            ];
        });

        $subscriptions = BillingItem::query()
            ->whereIn('client_id', $agency->clients()->select('id'))
            ->where('active', true)
            ->whereBetween('next_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->with('client:id,name')->orderBy('next_date')->get()
            ->map(fn (BillingItem $b) => [
                'id' => $b->id,
                'client' => $b->client?->name,
                'type' => BillingItem::TYPES[$b->type] ?? $b->type,
                'name' => $b->name,
                'period' => BillingItem::PERIOD_LABELS[$b->period] ?? $b->period,
                'next_date' => $b->next_date,
                'amount' => $b->amount,
                'currency' => $b->currency,
            ]);

        $sites = $rows->groupBy('client_id')->map(fn ($g) => [
            'client_id' => $g->first()['client_id'],
            'client' => $g->first()['client'],
            'tasks' => $g->count(),
            'minutes' => $g->sum('minutes'),
            'value' => round($g->sum(fn ($r) => $r['value'] ?? 0), 2),
        ])->sortBy('client')->values();

        $open = $rows->where('invoiced', false);

        return [
            'month' => $month->format('Y-m'),
            'month_label' => WorkReportBuilder::monthLabel($month),
            'rate' => $rate,
            'currency' => $agency->currency ?: 'PLN',
            'tasks' => $rows->values(),
            'sites' => $sites,
            'subscriptions' => $subscriptions->values(),
            'totals' => [
                'tasks' => $rows->count(),
                'minutes' => $rows->sum('minutes'),
                'work' => round($rows->sum(fn ($r) => $r['value'] ?? 0), 2),
                'subscriptions' => round($subscriptions->sum('amount'), 2),
                'open' => round($open->sum(fn ($r) => $r['value'] ?? 0), 2),
                'open_tasks' => $open->filter(fn ($r) => $r['value'] !== null)->count(),
                'unpriced' => $rows->whereNull('value')->count(), // prace bez kwoty i bez czasu/stawki
            ],
        ];
    }

    /** Oznacza prace z miesiąca jako zafakturowane (te, które mają wartość). */
    public function markInvoiced(Agency $agency, CarbonImmutable $month): int
    {
        return $this->tasksQuery($agency, $month)
            ->whereNull('invoiced_at')
            ->where(fn ($q) => $q->where('amount', '>', 0)->orWhere('minutes', '>', 0))
            ->update(['invoiced_at' => now()]);
    }
}
