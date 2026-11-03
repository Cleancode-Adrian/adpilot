<?php

namespace App\Http\Controllers;

use App\Jobs\RunWebAuditJob;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WebAudit;
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
            'audits' => WebAudit::with('client:id,name')->latest('id')->limit(100)
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

    public function show(WebAudit $audit)
    {
        $audit->load('client:id,name');

        return Inertia::render('audits/show', [
            'audit' => $audit,
            'areas' => WebAuditor::AREAS,
            'public_url' => route('public.web-audit', $audit->token),
            'pdf_url' => route('public.web-audit.pdf', $audit->token),
        ]);
    }

    public function rerun(Request $request, WebAudit $audit)
    {
        $copy = WebAudit::create($audit->only(['url', 'name', 'email', 'client_id']) + ['token' => Str::random(40), 'created_by' => $request->user()->id]);
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
        $audit->delete();

        return redirect()->route('audits.index')->with('success', 'Usunięto audyt.');
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
        return ['audit' => $audit, 'areas' => WebAuditor::AREAS, 'pdf' => $pdf, 'brand' => Setting::brand(), 'agency' => Setting::brand()['name'],
            'pdfUrl' => $pdf ? null : route('public.web-audit.pdf', $audit->token)];
    }
}
