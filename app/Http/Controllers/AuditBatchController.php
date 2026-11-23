<?php

namespace App\Http\Controllers;

use App\Models\AuditBatch;
use App\Models\AuditBatchItem;
use App\Models\Setting;
use App\Models\WebAudit;
use App\Services\WebAudit\BatchListParser;
use App\Services\WebAudit\BatchReport;
use App\Services\WebAudit\WebAuditor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Audyt zbiorczy: lista stron (wklejone adresy albo link do arkusza Google) → podgląd i wybór → audyt każdej strony po kolei
 * → jeden wspólny wynik: zestawienie, najczęstsze problemy, raport zbiorczy (link) i arkusz wszystkich uwag (CSV).
 */
class AuditBatchController extends Controller
{
    public function index()
    {
        return Inertia::render('audit-batches/index', [
            // same liczniki – bez skrótów wyników (kolumna summary) i bez szacowania czasu
            'batches' => AuditBatch::with(['items' => fn ($q) => $q->select(['id', 'audit_batch_id', 'position', 'enabled', 'web_audit_id'])->with('audit:id,status')])
                ->latest('id')->limit(50)->get()
                ->map(fn (AuditBatch $b) => [...$b->only(['id', 'name', 'status', 'created_at', 'finished_at']), 'progress' => $b->progress(false)]),
            'seconds_per_site' => AuditBatch::secondsPerSite(),
            'has_ai' => (bool) config('adpilot.ai.api_key'),
        ]);
    }

    public function store(Request $request, BatchListParser $parser)
    {
        $data = $request->validate(['name' => 'nullable|string|max:150', 'list' => 'required|string|max:300000']);
        $parsed = $parser->parse($data['list']);
        if (! $parsed['items']) {
            throw ValidationException::withMessages(['list' => 'Nie znaleźliśmy na liście żadnego adresu strony. Wklej adresy (np. firma.pl albo https://firma.pl/oferta) lub link do Arkusza Google.']);
        }
        $batch = AuditBatch::create([
            'name' => trim((string) ($data['name'] ?? '')) ?: 'Audyt zbiorczy '.now()->format('d.m.Y'),
            'source' => $parsed['source'],
            'options' => ['ai' => (bool) config('adpilot.ai.api_key')],
            'token' => Str::random(40),
            'created_by' => $request->user()->id,
        ]);
        foreach ($parsed['items'] as $n => $item) {
            $batch->items()->create($item + ['position' => $n + 1]);
        }

        return redirect()->route('audit-batches.show', $batch)->with('success', 'Wczytano '.count($parsed['items']).' stron. Sprawdź listę i uruchom audyt.');
    }

    public function show(AuditBatch $batch, BatchReport $report)
    {
        $items = $batch->items()->with('audit:id,status,error')->get();
        $batch->setRelation('items', $items);

        return Inertia::render('audit-batches/show', [
            'batch' => $batch->only(['id', 'name', 'status', 'source', 'options', 'created_at', 'started_at', 'finished_at']),
            'progress' => $batch->progress(),
            'items' => $report->items($items),
            'stats' => $report->stats($items),
            'common' => $report->common($items),
            'areas' => WebAuditor::AREAS,
            'has_ai' => (bool) config('adpilot.ai.api_key'),
            'public_url' => route('public.audit-batch', $batch->token),
            'csv_url' => route('audit-batches.export', $batch),
        ]);
    }

    public function update(Request $request, AuditBatch $batch)
    {
        $data = $request->validate(['name' => 'sometimes|required|string|max:150', 'ai' => 'sometimes|boolean', 'enabled' => 'sometimes|boolean']);
        if (isset($data['name'])) {
            $batch->update(['name' => $data['name']]);
        }
        if (array_key_exists('ai', $data)) {
            $batch->update(['options' => ['ai' => $data['ai']] + ($batch->options ?? [])]);
        }
        if (array_key_exists('enabled', $data)) { // zaznacz / odznacz wszystkie, których audyt jeszcze nie ruszył
            $batch->items()->whereNull('web_audit_id')->update(['enabled' => $data['enabled']]);
        }

        return back();
    }

    public function updateItem(Request $request, AuditBatch $batch, AuditBatchItem $item)
    {
        abort_unless($item->audit_batch_id === $batch->id, 404);
        $data = $request->validate(['enabled' => 'required|boolean']);
        abort_if($item->web_audit_id !== null, 422, 'Audyt tej strony już ruszył.');
        $item->update($data);
        if ($batch->status === 'done' && $data['enabled']) {
            $batch->update(['status' => 'paused']); // doszła strona do zrobienia – „Wznów” ją uruchomi
        }

        return back();
    }

    public function start(Request $request, AuditBatch $batch)
    {
        $data = $request->validate(['ai' => 'sometimes|boolean']);
        if (! $batch->items()->where('enabled', true)->exists()) {
            return back()->with('error', 'Zaznacz co najmniej jedną stronę.');
        }
        $batch->update([
            'status' => 'running',
            'started_at' => $batch->started_at ?? now(),
            'finished_at' => null,
            'options' => ['ai' => (bool) ($data['ai'] ?? $batch->options['ai'] ?? true)] + ($batch->options ?? []),
        ]);
        $batch->advance();

        return back()->with('success', 'Audyt zbiorczy ruszył – strony są sprawdzane po kolei w tle. Możesz zamknąć tę kartę.');
    }

    public function pause(AuditBatch $batch)
    {
        if ($batch->status === 'running') {
            $batch->update(['status' => 'paused']);
        }

        return back()->with('success', 'Wstrzymano. Trwający audyt strony dokończy się, kolejne nie ruszą.');
    }

    /** Ponawia nieudane audyty (np. strona chwilowo niedostępna albo blokada hostingu) – reszta wyników zostaje. */
    public function retryFailed(AuditBatch $batch)
    {
        $failed = $batch->items()->whereHas('audit', fn ($q) => $q->where('status', 'failed'))->get();
        foreach ($failed as $item) {
            $auditId = $item->web_audit_id;
            $item->update(['web_audit_id' => null, 'summary' => null]);
            WebAudit::whereKey($auditId)->delete();
        }
        if ($failed->isEmpty()) {
            return back()->with('error', 'Nie ma nieudanych audytów do ponowienia.');
        }
        $batch->update(['status' => 'running', 'finished_at' => null]);
        $batch->advance();

        return back()->with('success', 'Ponawiam nieudane audyty: '.$failed->count().'.');
    }

    public function destroy(AuditBatch $batch)
    {
        $batch->update(['status' => 'paused']); // trwający audyt nie uruchomi już następnej strony
        WebAudit::where('audit_batch_id', $batch->id)->delete();
        $batch->delete();

        return redirect()->route('audit-batches.index')->with('success', 'Usunięto audyt zbiorczy.');
    }

    /** Wszystkie uwagi w jednym arkuszu (CSV dla Excela: średnik, UTF-8 z BOM). */
    public function export(AuditBatch $batch, BatchReport $report)
    {
        return response()->streamDownload(function () use ($batch, $report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($report->rows($batch) as $row) {
                // tekst zaczynający się od = + - @ Excel potraktowałby jak formułę
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row), ';', '"', '');
            }
            fclose($out);
        }, 'audyt-zbiorczy-'.Str::slug($batch->name).'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Raport zbiorczy pod tajnym linkiem – do wglądu i druku (Ctrl+P → PDF). */
    public function public(string $token, BatchReport $report)
    {
        $batch = AuditBatch::where('token', $token)->firstOrFail();
        $items = $batch->items()->with('audit:id,status,error')->get();

        return view('audits.batch', [
            'batch' => $batch,
            'sites' => $report->sites($batch),
            'stats' => $report->stats($items),
            'common' => $report->common($items),
            'progress' => $batch->setRelation('items', $items)->progress(),
            'areas' => WebAuditor::AREAS,
            'brand' => Setting::brand(),
            'csvUrl' => route('public.audit-batch.csv', $batch->token),
        ]);
    }

    public function publicCsv(string $token, BatchReport $report)
    {
        return $this->export(AuditBatch::where('token', $token)->firstOrFail(), $report);
    }
}
