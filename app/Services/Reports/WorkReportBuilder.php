<?php

namespace App\Services\Reports;

use App\Models\CareTask;
use App\Models\Client;
use App\Models\Report;
use Carbon\CarbonImmutable;

/**
 * Raport z prac za miesiąc: wykonane zadania (z notatkami z wykonania) + opis od nas.
 * Dane są kopią w momencie tworzenia – można je potem dowolnie edytować bez ruszania zadań.
 */
class WorkReportBuilder
{
    private const MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /** „listopad 2026” – niezależnie od ustawień języka serwera. */
    public static function monthLabel(\DateTimeInterface $date): string
    {
        return self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');
    }

    public function build(Client $client, string $period): Report
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', "$period-01")->startOfDay();

        return $client->reports()->create([
            'type' => 'work',
            'period_start' => $start->toDateString(),
            'period_end' => $start->endOfMonth()->toDateString(),
            'data' => [
                'client' => ['name' => $client->name, 'website_url' => $client->website_url],
                'items' => $this->items($client, $start)->values()->all(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** Dokłada wykonane zadania, których jeszcze nie ma w raporcie. Zwraca liczbę dodanych. */
    public function refresh(Report $report): int
    {
        $data = $report->data;
        $have = collect($data['items'] ?? [])->pluck('task_id')->filter()->all();
        $new = $this->items($report->client, CarbonImmutable::parse($report->period_start))
            ->reject(fn ($i) => in_array($i['task_id'], $have, true));

        $data['items'] = collect($data['items'] ?? [])->concat($new)->sortBy('date')->values()->all();
        $report->update(['data' => $data]);

        return $new->count();
    }

    /** Zadania wykonane w danym miesiącu (także zaległe z wcześniejszych, zrobione teraz). */
    private function items(Client $client, CarbonImmutable $start)
    {
        $end = $start->endOfMonth();

        return $client->careTasks()->where('status', 'done')
            ->where(fn ($q) => $q->where('period', $start->format('Y-m'))
                ->orWhereBetween('done_at', [$start->toDateTimeString(), $end->toDateTimeString()]))
            ->orderBy('done_at')->orderBy('id')->get()
            ->map(fn (CareTask $t) => [
                'task_id' => $t->id,
                'title' => $t->title,
                'description' => (string) $t->note,
                'minutes' => $t->minutes,
                'date' => ($t->done_at ?? CarbonImmutable::parse($t->due_date))->format('Y-m-d'),
            ]);
    }
}
