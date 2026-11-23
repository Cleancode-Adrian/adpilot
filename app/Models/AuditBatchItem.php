<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jedna strona z listy audytu zbiorczego: adres, wskazane podstrony i skrót wyniku. */
class AuditBatchItem extends Model
{
    protected $fillable = ['audit_batch_id', 'position', 'label', 'url', 'pages', 'note', 'enabled', 'web_audit_id', 'summary'];

    protected $casts = ['pages' => 'array', 'summary' => 'array', 'enabled' => 'boolean'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AuditBatch::class, 'audit_batch_id');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(WebAudit::class, 'web_audit_id');
    }

    /** Stan pozycji: skipped | waiting | pending | running | done | failed. */
    public function state(): string
    {
        if (! $this->enabled) {
            return 'skipped';
        }

        return $this->web_audit_id ? ($this->audit?->status ?? 'waiting') : 'waiting';
    }

    /**
     * Skrót wyniku zapisany przy pozycji – strona audytu zbiorczego i zestawienia nie muszą ładować pełnych raportów
     * (każdy to kilkaset KB). Uwagi w skrócie: k = klucz problemu (do liczenia powtórzeń), s = ważność, t = tytuł, w = sekcja.
     */
    public function refreshSummary(): void
    {
        $audit = $this->web_audit_id ? WebAudit::find($this->web_audit_id) : null; // pełny model – relacja bywa wczytana bez raportu
        if (! $audit || ! in_array($audit->status, ['done', 'failed'], true)) {
            return;
        }
        $report = $audit->report ?? [];
        $findings = [];
        foreach ($report['sections'] ?? [] as $s) {
            foreach ($s['groups'] as $g) {
                foreach ($g['findings'] as $f) {
                    $findings[] = ['k' => self::problemKey($f['title']), 's' => $f['sev'], 't' => mb_strimwidth($f['title'], 0, 170, '…'), 'w' => $s['key'], 'id' => $f['id'],
                        'ai' => ($f['source'] ?? '') === 'ai', 'v' => (bool) $f['verify']];
                }
            }
        }
        $this->update(['summary' => [
            'status' => $audit->status,
            'score' => $audit->score,
            'areas' => collect($audit->areas ?? [])->map(fn ($a) => $a['score'] ?? null)->all(),
            'counts' => $report['counts'] ?? null,
            'verify' => $report['verify_count'] ?? 0,
            'blocked' => $report['blocked'] ?? collect($audit->areas ?? [])->pluck('blocked')->filter()->first(),
            'error' => $audit->status === 'failed' ? mb_strimwidth((string) $audit->error, 0, 300, '…') : null,
            'company' => $report['company'] ?? null,
            'industry' => $report['industry'] ?? null,
            'pages' => count($report['pages'] ?? []),
            'seconds' => $audit->finished_at ? max(0, (int) $audit->created_at->diffInSeconds($audit->finished_at)) : null,
            'findings' => $findings,
        ]]);
    }

    /**
     * Klucz problemu niezależny od szczegółów konkretnej strony: bez liczb, cytatów i dopisków w nawiasach.
     * „Ciężkie zdjęcia (3 pliki powyżej 250 KB)” i „Ciężkie zdjęcia (1 plik powyżej 250 KB)” to ten sam problem.
     */
    public static function problemKey(string $title): string
    {
        $k = mb_strtolower($title);
        $k = preg_replace('/\([^)]*\)|„[^”]*”|"[^"]*"/u', ' ', $k);
        $k = preg_replace('/(?<![\p{L}\d])\d+(?:[.,]\d+)?(?![\p{L}\d])/u', ' ', (string) $k);
        $k = preg_replace('/\s+(?:kb|mb|ms|px|s)(?![\p{L}\d])/u', ' ', (string) $k);

        return trim(preg_replace('/[\s–—:;,.-]+/u', ' ', (string) $k));
    }
}
