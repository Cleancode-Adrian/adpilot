<?php

namespace App\Models;

use App\Jobs\RunWebAuditJob;
use App\Mail\ClientAlertMail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Audyt zbiorczy – wiele stron z jednej listy (np. wszystkie serwisy agencji), każda z pełnym audytem i jednym wspólnym raportem.
 *
 * Strony idą po kolei: w kolejce jest zawsze najwyżej tyle audytów z tej listy, ile wynosi „concurrency” (domyślnie 1),
 * a następny startuje dopiero po zakończeniu poprzedniego. Dzięki temu pojedynczy audyt uruchomiony ręcznie
 * nie czeka za kilkudziesięcioma stronami z listy.
 */
class AuditBatch extends Model
{
    protected $fillable = ['previous_id', 'name', 'status', 'source', 'options', 'token', 'created_by', 'started_at', 'finished_at', 'notified_at'];

    protected $hidden = ['token'];

    protected $casts = ['options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'notified_at' => 'datetime'];

    /** Poprzedni audyt tej samej listy – do porównania „przed / po”. */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuditBatchItem::class)->orderBy('position');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(WebAudit::class);
    }

    /**
     * Popycha listę: domyka skróty zakończonych audytów, zamyka audyty przerwane (np. restart serwera) i uruchamia kolejne strony.
     * Bezpieczne do wielokrotnego wywołania – z zadania po każdym audycie, z harmonogramu i z przycisku „Wznów”.
     */
    public function advance(): void
    {
        $dispatch = DB::transaction(function () {
            $batch = self::whereKey($this->id)->lockForUpdate()->first();
            if (! $batch || $batch->status !== 'running') {
                return [];
            }
            $items = $batch->items()->with('audit:id,status,updated_at')->get(); // bez raportów – każdy to kilkaset KB
            $active = 0;
            foreach ($items as $item) {
                $audit = $item->audit;
                if (! $audit) {
                    continue;
                }
                $staleAfter = $audit->status === 'running' ? 25 : 90; // minut: audyt trwa najwyżej 15 min, oczekujący może stać za innymi zadaniami
                if (in_array($audit->status, ['pending', 'running'], true) && $audit->updated_at->lt(now()->subMinutes($staleAfter))) {
                    $audit->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'Audyt został przerwany (np. restart serwera albo przekroczony czas). Użyj „Ponów nieudane”.']);
                }
                if (in_array($audit->status, ['pending', 'running'], true)) {
                    $active++;
                } elseif (($item->summary['status'] ?? null) !== $audit->status) {
                    $item->refreshSummary();
                }
            }

            $slots = max(1, (int) config('adpilot.audit_batch.concurrency', 1)) - $active;
            $next = $slots > 0 ? $items->filter(fn (AuditBatchItem $i) => $i->enabled && ! $i->web_audit_id)->take($slots) : collect();
            $audits = [];
            foreach ($next as $item) {
                $audit = WebAudit::create([
                    'url' => $item->url,
                    'token' => Str::random(40),
                    'created_by' => $batch->created_by,
                    'audit_batch_id' => $batch->id,
                    'client_id' => Client::where('website_url', $item->url)->value('id'),
                    'options' => ['pages' => $item->pages ?? [], 'ai' => (bool) ($batch->options['ai'] ?? true)],
                    'followup_off' => true, // strony z listy to nie potencjalni klienci – bez przypomnień o kontakcie
                ]);
                $item->update(['web_audit_id' => $audit->id, 'summary' => null]);
                $audits[] = $audit;
            }
            if (! $active && ! $audits) {
                $batch->update(['status' => 'done', 'finished_at' => now()]);
            }

            return $audits;
        });

        foreach ($dispatch as $audit) {
            RunWebAuditJob::dispatch($audit);
        }
        $this->refresh();
        $this->notifyFinished();
    }

    /** E-mail do osoby, która uruchomiła listę – audyt kilkudziesięciu stron trwa godzinami, nie trzeba zaglądać do panelu. */
    private function notifyFinished(): void
    {
        if ($this->status !== 'done' || $this->notified_at || ! ($email = $this->creator?->email)) {
            return;
        }
        $this->update(['notified_at' => now()]);
        $p = $this->progress(false);
        try {
            Mail::to($email)->send(new ClientAlertMail(
                'Audyt zbiorczy gotowy: '.$this->name,
                array_values(array_filter([
                    'Sprawdzone strony: '.$p['done'].' z '.$p['total'].'.',
                    $p['failed'] ? 'Nieudane: '.$p['failed'].' – w panelu możesz je ponowić jednym kliknięciem.' : null,
                    'W panelu: zestawienie stron, najczęstsze problemy, arkusz wszystkich uwag i raport zbiorczy.',
                ])),
                route('audit-batches.show', $this),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Ile sekund zwykle trwa audyt jednej strony – z ostatnich wykonanych (mediana), a bez historii: ostrożne założenie. */
    public static function secondsPerSite(bool $ai = true): int
    {
        $durations = WebAudit::where('status', 'done')->whereNotNull('finished_at')->latest('id')->limit(40)->get(['created_at', 'finished_at', 'audit_batch_id'])
            ->map(fn (WebAudit $a) => (int) $a->created_at->diffInSeconds($a->finished_at))
            ->filter(fn (int $s) => $s >= 20 && $s <= 1200)->sort()->values();
        if ($durations->count() >= 5) {
            return (int) $durations[(int) floor($durations->count() / 2)];
        }

        return $ai ? 300 : 150;
    }

    /** Postęp i szacowany czas do końca (do paska postępu). */
    public function progress(bool $withEta = true): array
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with('audit:id,status')->get();
        $states = $items->map(fn (AuditBatchItem $i) => $i->state())->countBy();
        $total = $items->where('enabled', true)->count();
        $finished = ($states['done'] ?? 0) + ($states['failed'] ?? 0);
        $per = 0;
        if ($withEta) {
            $seconds = $items->map(fn (AuditBatchItem $i) => $i->summary['seconds'] ?? null)->filter()->values();
            $per = $seconds->count() >= 3 ? (int) round($seconds->avg()) : self::secondsPerSite((bool) ($this->options['ai'] ?? true));
            $per = (int) ceil($per / max(1, (int) config('adpilot.audit_batch.concurrency', 1)));
        }

        return [
            'total' => $total,
            'done' => $states['done'] ?? 0,
            'failed' => $states['failed'] ?? 0,
            'running' => ($states['running'] ?? 0) + ($states['pending'] ?? 0),
            'waiting' => $states['waiting'] ?? 0,
            'skipped' => $states['skipped'] ?? 0,
            'seconds_per_site' => $per,
            'eta_seconds' => max(0, $total - $finished) * $per,
        ];
    }
}
