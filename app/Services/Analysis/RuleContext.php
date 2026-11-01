<?php

namespace App\Services\Analysis;

use App\Models\Client;
use App\Models\TrackingAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Dane wejściowe dla reguł – liczone raz, współdzielone przez wszystkie reguły. */
class RuleContext
{
    public readonly CarbonImmutable $end;
    public readonly Metrics $metrics;

    private array $cache = [];

    public function __construct(public readonly Client $client, ?CarbonImmutable $end = null)
    {
        // Wczorajszy dzień – dzisiejsze dane są niepełne
        $this->end = $end ?? CarbonImmutable::yesterday();
        $this->metrics = new Metrics($client);
    }

    public function range(int $days, int $offsetDays = 0): array
    {
        $to = $this->end->subDays($offsetDays);

        return [$to->subDays($days - 1), $to];
    }

    public function campaigns(int $days = 14, int $offset = 0): Collection
    {
        return $this->cache["c$days.$offset"] ??= $this->metrics->campaigns(...$this->range($days, $offset));
    }

    public function ads(int $days = 14, int $offset = 0): Collection
    {
        return $this->cache["a$days.$offset"] ??= $this->metrics->ads(...$this->range($days, $offset));
    }

    public function searchTerms(): Collection
    {
        return $this->cache['terms'] ??= $this->client->searchTerms()->with('campaign:id,name')->get();
    }

    public function audit(): ?TrackingAudit
    {
        return $this->cache['audit'] ??= $this->client->audits()->where('status', 'done')->latest()->first();
    }

    /** Docelowy CPA: z ustawień klienta albo 1,2× średni CPA konta (fallback). */
    public function targetCpa(?string $platform = null): ?float
    {
        if ($this->client->target_cpa) {
            return $this->client->target_cpa;
        }
        $t = $this->metrics->totals(...[...$this->range(30), $platform]);

        return $t['cpa'] ? round($t['cpa'] * 1.2, 2) : null;
    }

    public function targetRoas(): ?float
    {
        return $this->client->target_roas ?: null;
    }

    public function money(float|int|null $v): string
    {
        return number_format((float) $v, 2, ',', ' ').' '.$this->client->currency;
    }

    public static function platformName(string $p): string
    {
        return ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'analytics' => 'GA4', 'tracking' => 'Pomiar', 'account' => 'Konto'][$p] ?? $p;
    }
}
