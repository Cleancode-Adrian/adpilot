<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

/** Zamiana błędów z audytu trackingu na zadania. */
class TrackingAuditIssues extends Rule
{
    public function key(): string { return 'tracking_audit'; }

    public function evaluate(RuleContext $ctx): iterable
    {
        $audit = $ctx->audit();
        if (! $audit) {
            yield $this->finding([
                'platform' => 'tracking', 'severity' => 'medium',
                'entity_type' => 'tracking', 'entity_id' => 'no_audit',
                'title' => 'Brak audytu pomiaru',
                'description' => 'Nie wykonano jeszcze audytu trackingu dla tej strony.',
                'action' => 'Uruchom audyt w zakładce „Audyt trackingu”, aby sprawdzić GA4, GTM, Pixel i Consent Mode.',
            ]);

            return;
        }

        foreach ($audit->results['checks'] ?? [] as $check) {
            if (! in_array($check['status'], ['fail', 'warn'], true) || $check['weight'] < 4) {
                continue;
            }
            $severity = $check['status'] === 'fail' ? ($check['weight'] >= 10 ? 'critical' : 'high') : ($check['weight'] >= 10 ? 'high' : 'medium');
            yield $this->finding([
                'platform' => 'tracking',
                'severity' => $severity,
                'entity_type' => 'tracking', 'entity_id' => $check['key'],
                'title' => 'Pomiar: '.$check['label'],
                'description' => $check['details'],
                'action' => $check['fix'] ?? 'Popraw konfigurację.',
            ]);
        }
    }
}
