<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Tempo wydatków vs budżet dzienny i miesięczny. */
class BudgetPacing extends Rule
{
    public function key(): string { return 'budget_pacing'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        yield from $this->daily($ctx);

        $budget = $ctx->client->monthly_budget;
        // bez danych z kampanii (konta jeszcze niepodłączone) nie oceniamy tempa
        if (! $budget || $ctx->metrics->totals(...$ctx->range(30))['impressions'] == 0) {
            return;
        }
        $start = $ctx->end->startOfMonth();
        $daysPassed = $start->diffInDays($ctx->end) + 1;
        if ($daysPassed < 5) {
            return;
        }
        $spent = $ctx->metrics->totals($start, $ctx->end)['cost'];
        $projected = $spent / $daysPassed * $ctx->end->daysInMonth;
        $ratio = $projected / $budget;
        if ($ratio > 1.1 || $ratio < 0.85) {
            $over = $ratio > 1.1;
            yield $this->finding([
                'platform' => 'account',
                'severity' => $over ? 'high' : 'medium',
                'entity_type' => 'account', 'entity_id' => $start->format('Y-m'),
                'title' => $over ? 'Budżet miesięczny zostanie przekroczony' : 'Budżet miesięczny nie zostanie wykorzystany',
                'description' => 'Wydano '.$ctx->money($spent).' w '.$daysPassed.' dni. Prognoza na koniec miesiąca: '.$ctx->money($projected).' przy budżecie '.$ctx->money($budget).' ('.round($ratio * 100).'%).',
                'action' => $over
                    ? 'Obniż budżety dzienne proporcjonalnie, zaczynając od kampanii z najniższym ROAS.'
                    : 'Zwiększ budżety kampanii z najlepszym ROAS albo uruchom dodatkowe kampanie (np. remarketing, PMax).',
                'data' => ['spent' => $spent, 'projected' => round($projected, 2), 'budget' => $budget],
            ]);
        }
    }

    /** Budżet dzienny: średnia z 3 ostatnich dni vs plan. */
    private function daily(RuleContext $ctx): iterable
    {
        $daily = $ctx->client->daily_budget;
        if (! $daily || $ctx->client->is_demo) {
            return;
        }
        // tylko gdy są już jakiekolwiek dane z kampanii (świeżo podłączone konto pomijamy)
        if ($ctx->metrics->totals(...$ctx->range(30))['impressions'] == 0) {
            return;
        }
        $avg = $ctx->metrics->totals(...$ctx->range(3))['cost'] / 3;
        $ratio = $avg / $daily;
        $base = ['platform' => 'account', 'entity_type' => 'account', 'data' => ['avg' => round($avg, 2), 'daily_budget' => $daily]];

        if ($avg < 0.01) {
            yield $this->finding($base + [
                'severity' => 'critical', 'entity_id' => 'daily_zero',
                'title' => 'Reklamy nie wydają budżetu od 3 dni',
                'description' => 'Plan: '.$ctx->money($daily).' dziennie, wydatki z ostatnich 3 dni: 0. Reklamy najpewniej się nie wyświetlają.',
                'action' => 'Sprawdź w Google Ads / Meta: status kampanii (wstrzymana?), odrzucone reklamy, metodę płatności, ograniczenia konta. Przy bardzo małym budżecie sprawdź też, czy stawki nie są zbyt niskie.',
            ]);
        } elseif ($ratio > 1.3) {
            yield $this->finding($base + [
                'severity' => 'high', 'entity_id' => 'daily_over',
                'title' => 'Wydatki dzienne powyżej planu ('.round($ratio * 100).'%)',
                'description' => 'Średnio '.$ctx->money($avg).' dziennie przy planie '.$ctx->money($daily).' (ostatnie 3 dni).',
                'action' => 'Obniż budżety dzienne kampanii w Google Ads / Meta. Uwaga: Google może w pojedyncze dni wydać do 2× budżetu dziennego, ale w miesiącu nie przekroczy 30,4× budżetu dziennego.',
            ]);
        } elseif ($ratio < 0.6) {
            yield $this->finding($base + [
                'severity' => 'medium', 'entity_id' => 'daily_under',
                'title' => 'Kampanie wydają mniej niż plan ('.round($ratio * 100).'%)',
                'description' => 'Średnio '.$ctx->money($avg).' dziennie przy planie '.$ctx->money($daily).' (ostatnie 3 dni).',
                'action' => 'Za mało wyświetleń: poszerz grupę odbiorców lub listę słów kluczowych, sprawdź stawki/strategię licytacji i zatwierdzenie reklam. Jeśli to celowe – zaktualizuj budżet dzienny klienta w AdPilot.',
            ]);
        }
    }
}
