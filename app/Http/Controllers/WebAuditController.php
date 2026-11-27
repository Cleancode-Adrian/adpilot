<?php

namespace App\Http\Controllers;

use App\Jobs\RunWebAuditJob;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WebAudit;
use App\Services\WebAudit\AuditReportBuilder;
use App\Services\WebAudit\WebAuditor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Audyt dowolnej strony – dla potencjalnych klientów (bez zakładania klienta). */
class WebAuditController extends Controller
{
    public function index()
    {
        return Inertia::render('audits/index', [
            'audits' => WebAudit::with('client:id,name')->whereNull('audit_batch_id')->latest('id')->limit(100) // strony z audytów zbiorczych mają własną zakładkę
                ->get(['id', 'client_id', 'url', 'name', 'email', 'status', 'score', 'areas', 'created_at'])
                ->map(fn (WebAudit $a) => [...$a->only(['id', 'url', 'name', 'status', 'score', 'created_at']), 'client' => $a->client?->only(['id', 'name']),
                    'area_scores' => collect($a->areas ?? [])->map(fn ($x) => $x['score'] ?? null)]),
            'areas' => WebAuditor::AREAS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['url' => 'required|string|max:500', 'name' => 'nullable|string|max:150', 'email' => 'nullable|email|max:190']);
        $url = Client::normalizeUrl($data['url']);
        if (! $url) {
            throw ValidationException::withMessages(['url' => 'Nieprawidłowy adres – wpisz np. firma.pl']);
        }
        $audit = WebAudit::create(['url' => $url, 'name' => $data['name'] ?? null, 'email' => $data['email'] ?? null,
            'token' => Str::random(40), 'created_by' => $request->user()->id,
            'client_id' => Client::where('website_url', $url)->value('id')]);
        RunWebAuditJob::dispatch($audit);

        return redirect()->route('audits.show', $audit)->with('success', 'Audyt uruchomiony – zwykle trwa 1–2 minuty.');
    }

    /** Audyty sprzed szczegółowego raportu dostają raport złożony z samych wyników technicznych. */
    private function report(WebAudit $audit): ?array
    {
        if ($audit->status !== 'done') {
            return null;
        }

        return $audit->report ?? app(AuditReportBuilder::class)->build($audit, $audit->areas ?? [], null, null);
    }

    public function show(WebAudit $audit)
    {
        $audit->load('client:id,name');

        return Inertia::render('audits/show', [
            'batch' => $audit->batch?->only(['id', 'name']),
            'has_ai' => (bool) config('adpilot.ai.api_key'),
            'audit' => $audit->makeHidden('report'),
            'report' => $this->report($audit), 'internal' => (bool) $audit->audit_batch_id, // audyt z listy stron = raport wewnętrzny (bez rzeczy „na zielono”)
            'areas' => WebAuditor::AREAS,
            'public_url' => route('public.web-audit', $audit->token),
            'pdf_url' => route('public.web-audit.pdf', $audit->token),
        ]);
    }

    public function rerun(Request $request, WebAudit $audit)
    {
        $copy = WebAudit::create($audit->only(['url', 'name', 'email', 'client_id', 'audit_batch_id', 'options', 'followup_off']) + ['token' => Str::random(40), 'created_by' => $request->user()->id]);
        if ($request->boolean('ai_full')) { // wybrana strona z listy: pełny przegląd treści (mocny model), np. przed pokazaniem klientowi
            $copy->update(['options' => ['ai' => true, 'ai_full' => true] + ($copy->options ?? [])]);
        }
        // audyt z listy zbiorczej: nowy wynik zastępuje poprzedni w zestawieniu
        $audit->batchItem?->update(['web_audit_id' => $copy->id, 'summary' => null]);
        RunWebAuditJob::dispatch($copy);

        return redirect()->route('audits.show', $copy)->with('success', 'Uruchomiono ponowny audyt.');
    }

    /** Potencjalny klient → klient (z obsługą ogólną), audyt zostaje podpięty. */
    public function convert(WebAudit $audit)
    {
        $client = $audit->client ?? Client::create([
            'name' => $audit->name ?: $audit->host(),
            'website_url' => $audit->url,
            'contact_email' => $audit->email,
            'currency' => 'PLN',
            'has_general' => true,
        ]);
        WebAudit::where('url', $audit->url)->whereNull('client_id')->update(['client_id' => $client->id]);

        return redirect()->route('clients.show', $client)->with('success', 'Utworzono klienta z audytu. Możesz przygotować ofertę (Oferty → szablon „Audyt SEO + poprawki”).');
    }

    public function destroy(WebAudit $audit)
    {
        $batch = $audit->audit_batch_id;
        $audit->batchItem?->update(['web_audit_id' => null, 'summary' => null]);
        $audit->delete();

        return ($batch ? redirect()->route('audit-batches.show', $batch) : redirect()->route('audits.index'))->with('success', 'Usunięto audyt.');
    }

    // ------------------------------------------------------------------ raport dla klienta

    public function public(string $token)
    {
        return view('audits.public', $this->viewData(WebAudit::where('token', $token)->where('status', 'done')->firstOrFail(), false));
    }

    public function pdf(string $token)
    {
        $audit = WebAudit::where('token', $token)->where('status', 'done')->firstOrFail();

        return Pdf::loadView('audits.public', $this->viewData($audit, true))->setPaper('a4')
            ->download('audyt-'.Str::slug($audit->host()).'-'.$audit->created_at->format('Y-m-d').'.pdf');
    }

    private function viewData(WebAudit $audit, bool $pdf): array
    {
        $internal = (bool) $audit->audit_batch_id; // audyt z listy stron = raport wewnętrzny (bez rzeczy „na zielono”)
        $report = $this->report($audit);

        return ['audit' => $audit, 'areas' => WebAuditor::AREAS, 'pdf' => $pdf, 'brand' => Setting::brand(), 'agency' => Setting::brand()['name'],
            'report' => $report ? $this->renumber($report, $internal) : null, 'internal' => $internal,
            'pdfUrl' => $pdf ? null : route('public.web-audit.pdf', $audit->token)];
    }

    /**
     * Numeracja działów i uwag bez luk – po ukryciu działów, które w raporcie nie mają czego pokazać
     * (w raporcie wewnętrznym: działy bez uwag, które nie są podstronami). Numery uwag w planie i checkliście idą za nimi.
     */
    private function renumber(array $r, bool $internal): array
    {
        $pageRoles = array_column($r['pages'] ?? [], 'role');
        $map = [];
        $sections = [];
        foreach ($r['sections'] ?? [] as $s) {
            if ($internal && ! $s['groups'] && ! in_array($s['key'], $pageRoles, true)) {
                continue;
            }
            $s['num'] = count($sections) + 1;
            $i = 0;
            foreach ($s['groups'] as &$g) {
                foreach ($g['findings'] as &$f) {
                    $map[(string) $f['id']] = $s['num'].'.'.(++$i);
                    $f['id'] = $map[(string) $f['id']];
                }
                unset($f);
            }
            unset($g);
            $sections[] = $s;
        }
        $fix = function ($v) use (&$fix, $map) {
            if (is_string($v)) {
                return preg_replace_callback('/\((\d+\.\d+)\)/', fn ($m) => '('.($map[$m[1]] ?? $m[1]).')', $v);
            }
            if (! is_array($v)) {
                return $v;
            }
            foreach ($v as $k => $item) {
                $v[$k] = $k === 'id' && is_string($item) ? ($map[$item] ?? $item) : (in_array($k, ['url', 'urls', 'evidence', 'text', 'fix'], true) ? $item : $fix($item));
            }

            return $v;
        };
        foreach ($r as $k => $v) {
            if (in_array($k, ['plan', 'checklist', 'top', 'proposals'], true)) {
                $r[$k] = $fix($v);
            }
        }
        $r['sections'] = $sections;

        return $r;
    }
}
