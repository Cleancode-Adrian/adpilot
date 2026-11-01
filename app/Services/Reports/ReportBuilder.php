<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Report;
use App\Services\Analysis\Metrics;
use App\Services\Analysis\RuleContext;
use Carbon\CarbonImmutable;

/** Zbiera dane do raportu (klient / wewnętrzny) i zapisuje je jako migawkę. */
class ReportBuilder
{
    public function __construct(private AiReportWriter $writer) {}

    public function build(Client $client, string $type, CarbonImmutable $from, CarbonImmutable $to, bool $withAi = true): Report
    {
        $data = $this->collect($client, $type, $from, $to);
        [$summary, $model] = $withAi ? $this->writer->write($client, $type, $data) : [null, null];

        return $client->reports()->create([
            'type' => $type,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'data' => $data,
            'ai_summary' => $summary,
            'ai_model' => $model,
        ]);
    }

    public function collect(Client $client, string $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $m = new Metrics($client);
        $campaigns = $m->campaigns($from, $to);
        $ads = $m->ads($from, $to)->filter(fn ($a) => $a['cost'] > 0);

        $recs = $client->recommendations();
        $done = (clone $recs)->whereIn('status', ['done', 'resolved'])->whereBetween('resolved_at', [$from->startOfDay(), $to->endOfDay()->addDays(5)])->bySeverity()->get();
        $open = (clone $recs)->open()->bySeverity()->get();

        $audit = $client->audits()->where('status', 'done')->latest()->first();

        $slim = fn ($r) => collect($r)->only(['id', 'title', 'description', 'action', 'severity', 'kind', 'platform', 'impact', 'entity_name', 'data'])->all();

        return [
            'client' => ['name' => $client->name, 'website' => $client->website_url, 'goal' => $client->goal ?? 'sales', 'goal_label' => \App\Models\Client::GOALS[$client->goal ?? 'sales'], 'currency' => $client->currency, 'target_roas' => $client->target_roas, 'target_cpa' => $client->target_cpa, 'monthly_budget' => $client->monthly_budget],
            'kpis' => $m->kpis($from, $to),
            'daily' => $m->daily($from, $to),
            'channels' => $m->channels($from, $to)->values()->all(),
            'campaigns' => $campaigns->values()->all(),
            'top_ads' => ($client->goal === 'leads'
                ? $ads->filter(fn ($a) => $a['conversions'] >= 1)->sortBy('cpa')
                : $ads->filter(fn ($a) => $a['conversions'] > 0)->sortByDesc(fn ($a) => $a['roas'] ?? 0))->take(5)->values()->all(),
            'worst_ads' => $ads->filter(fn ($a) => $a['cost'] >= 50)->sortBy(fn ($a) => $a['roas'] ?? -1)->take(5)->values()->all(),
            'done' => $done->map($slim)->values()->all(),
            'open' => $open->map($slim)->values()->all(),
            'tracking' => $audit ? ['score' => $audit->score, 'date' => $audit->created_at->toDateString(), 'issues' => collect($audit->results['checks'] ?? [])->whereIn('status', ['fail', 'warn'])->pluck('label')->values()->all()] : null,
            'platform_names' => ['google_ads' => RuleContext::platformName('google_ads'), 'meta_ads' => RuleContext::platformName('meta_ads')],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
