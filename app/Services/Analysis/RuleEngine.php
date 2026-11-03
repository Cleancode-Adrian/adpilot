<?php

namespace App\Services\Analysis;

use App\Models\Client;
use App\Models\Recommendation;
use App\Services\Analysis\Rules;
use Illuminate\Support\Facades\DB;

/**
 * Uruchamia wszystkie reguły i synchronizuje tabelę rekomendacji:
 * - nowe ustalenia → status open,
 * - otwarte, które przestały występować → resolved (problem zniknął),
 * - oznaczone jako zrobione/odrzucone nie wracają przez 14 dni.
 */
class RuleEngine
{
    /** @return array<class-string<Rules\Rule>> */
    public static function rules(): array
    {
        return [
            Rules\WastedSpendCampaign::class,
            Rules\HighCpa::class,
            Rules\LowRoas::class,
            Rules\ScaleWinner::class,
            Rules\CtrDrop::class,
            Rules\BudgetPacing::class,
            Rules\AdUnderperformer::class,
            Rules\AdTopPerformer::class,
            Rules\CreativeFatigue::class,
            Rules\SearchTermWaste::class,
            Rules\TrackingDiscrepancy::class,
            Rules\TrackingAuditIssues::class,
            Rules\IntegrationHealth::class,
            Rules\LowLandingConversion::class,
            Rules\MetaFunnelLeaks::class,
            Rules\MetaAccountHealth::class,
        ];
    }

    public function run(Client $client): array
    {
        $ctx = new RuleContext($client);
        $findings = [];
        foreach (self::rules() as $class) {
            foreach ((new $class)->evaluate($ctx) as $f) {
                $f['fingerprint'] = md5($f['rule_key'].'|'.$f['entity_type'].'|'.$f['entity_id']);
                $findings[$f['fingerprint']] = $f;
            }
        }

        $stats = ['new' => 0, 'updated' => 0, 'resolved' => 0];

        DB::transaction(function () use ($client, $findings, &$stats) {
            $existing = $client->recommendations()->get()->keyBy('fingerprint');

            foreach ($findings as $fp => $f) {
                $rec = $existing->get($fp);
                $payload = collect($f)->only(['platform', 'rule_key', 'severity', 'kind', 'entity_type', 'entity_id', 'entity_name', 'title', 'description', 'action', 'impact', 'data'])->all();

                if (! $rec) {
                    $client->recommendations()->create($payload + ['fingerprint' => $fp, 'status' => 'open', 'detected_at' => now()]);
                    $stats['new']++;
                } elseif ($rec->status === 'open') {
                    $rec->update($payload);
                    $stats['updated']++;
                } elseif ($rec->status === 'resolved' || ($rec->resolved_at && $rec->resolved_at->lt(now()->subDays(14)))) {
                    // problem wrócił
                    $rec->update($payload + ['status' => 'open', 'detected_at' => now(), 'resolved_at' => null]);
                    $stats['new']++;
                }
            }

            foreach ($existing as $fp => $rec) {
                if ($rec->status === 'open' && ! isset($findings[$fp])) {
                    $rec->update(['status' => 'resolved', 'resolved_at' => now()]);
                    $stats['resolved']++;
                }
            }
        });

        return $stats;
    }
}
