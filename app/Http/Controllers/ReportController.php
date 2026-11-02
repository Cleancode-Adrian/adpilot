<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportJob;
use App\Mail\ReportReadyMail;
use App\Models\Client;
use App\Models\Report;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Client $client)
    {
        return Inertia::render('clients/reports', [
            'client' => app(ClientController::class)->clientProps($client),
            'reports' => $client->reports()->latest()->get(['id', 'type', 'period_start', 'period_end', 'ai_model', 'sent_at', 'created_at'])
                ->map(fn ($r) => [...$r->toArray(), 'public_url' => route('public.report', [$client->report_token, $r]), 'pdf_url' => route('public.report.pdf', [$client->report_token, $r])]),
            'defaults' => [
                'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'ai_enabled' => filled(config('adpilot.ai.api_key')),
            // miesiące z wykonanymi zadaniami (do raportu z prac), najnowsze pierwsze
            'workMonths' => collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'))
                ->map(fn ($p) => ['period' => $p, 'done' => $client->careTasks()->where('status', 'done')->where('period', $p)->count()])
                ->values(),
        ]);
    }

    /** Raport z prac za miesiąc – tworzy szkic z wykonanych zadań i przechodzi do edycji. */
    public function storeWork(Request $request, Client $client)
    {
        $period = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['month'];
        $report = app(\App\Services\Reports\WorkReportBuilder::class)->build($client, $period);
        $n = count($report->data['items']);

        return redirect()->route('reports.show', $report)->with('success', $n
            ? "Utworzono raport z prac ($n wykonanych zadań). Uzupełnij opis i sprawdź pozycje, potem wyślij klientowi."
            : 'Utworzono raport – w tym miesiącu nie ma jeszcze wykonanych zadań. Dodaj pozycje ręcznie albo odhacz zadania i kliknij „Dodaj nowe wykonane zadania”.');
    }

    public function updateWork(Request $request, Report $report)
    {
        abort_unless($report->type === 'work', 404);
        $v = $request->validate([
            'note' => 'nullable|string|max:20000',
            'items' => 'array|max:300',
            'items.*.title' => 'required|string|max:255',
            'items.*.description' => 'nullable|string|max:10000',
            'items.*.date' => 'nullable|date',
            'items.*.task_id' => 'nullable|integer',
        ]);
        $data = $report->data;
        $data['items'] = collect($v['items'] ?? [])->map(fn ($i) => [
            'task_id' => $i['task_id'] ?? null,
            'title' => $i['title'],
            'description' => (string) ($i['description'] ?? ''),
            'date' => isset($i['date']) ? substr($i['date'], 0, 10) : null,
        ])->values()->all();
        $report->update(['note' => $v['note'] ?? null, 'data' => $data]);

        return back()->with('success', 'Zapisano raport.');
    }

    public function refreshWork(Report $report)
    {
        abort_unless($report->type === 'work', 404);
        $n = app(\App\Services\Reports\WorkReportBuilder::class)->refresh($report);

        return back()->with('success', $n ? "Dodano $n nowych wykonanych zadań." : 'Brak nowych wykonanych zadań w tym miesiącu.');
    }

    public function store(Request $request, Client $client)
    {
        $data = $request->validate([
            'type' => 'required|in:client,internal',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'send' => 'boolean',
        ]);
        GenerateReportJob::dispatch($client, $data['type'], $data['from'], $data['to'], (bool) ($data['send'] ?? false));

        return back()->with('success', 'Raport jest generowany (z komentarzem AI może to potrwać do minuty). Odśwież listę za chwilę.');
    }

    public function show(Report $report)
    {
        $client = $report->client;

        if ($report->type === 'work') {
            return Inertia::render('reports/work', [
                'client' => app(ClientController::class)->clientProps($client),
                'report' => $report,
                'public_url' => route('public.report', [$client->report_token, $report]),
                'pdf_url' => route('public.report.pdf', [$client->report_token, $report]),
            ]);
        }

        return Inertia::render('reports/show', [
            'client' => app(ClientController::class)->clientProps($client),
            'report' => $report,
            'summary_html' => $report->ai_summary ? Str::markdown($report->ai_summary, ['html_input' => 'strip']) : null,
            'public_url' => route('public.report', [$client->report_token, $report]),
            'pdf_url' => route('public.report.pdf', [$client->report_token, $report]),
        ]);
    }

    public function updateNote(Request $request, Report $report)
    {
        $report->update($request->validate(['note' => 'nullable|string|max:5000']));

        return back()->with('success', 'Zapisano notatkę – pojawi się w raporcie dla klienta.');
    }

    public function send(Report $report)
    {
        $client = $report->client;
        if (! $client->contact_email) {
            return back()->with('error', 'Uzupełnij e-mail kontaktowy klienta.');
        }
        Mail::to($client->contact_email)->send(new ReportReadyMail($report));
        $report->update(['sent_at' => now()]);

        return back()->with('success', "Raport wysłany na {$client->contact_email}.");
    }

    public function destroy(Report $report)
    {
        $client = $report->client;
        $report->delete();

        return redirect()->route('clients.reports', $client)->with('success', 'Raport usunięty.');
    }

    // ---------------------------------------------------------------- publiczny raport dla klienta

    public function public(string $token, Report $report)
    {
        $this->authorizePublic($token, $report);

        return view($report->type === 'work' ? 'reports.work' : 'reports.client', $this->viewData($report, false));
    }

    public function pdf(string $token, Report $report)
    {
        $this->authorizePublic($token, $report);
        $name = Str::slug($report->client->name).($report->type === 'work' ? '-raport-z-prac-' : '-raport-').$report->period_start->format('Y-m').'.pdf';

        return Pdf::loadView($report->type === 'work' ? 'reports.work' : 'reports.client', $this->viewData($report, true))->setPaper('a4')->download($name);
    }

    private function authorizePublic(string $token, Report $report): void
    {
        abort_unless(hash_equals($report->client->report_token, $token) && in_array($report->type, ['client', 'work'], true), 404);
    }

    private function viewData(Report $report, bool $pdf): array
    {
        return [
            'report' => $report,
            'd' => $report->data,
            'summary' => $report->ai_summary ? Str::markdown($report->ai_summary, ['html_input' => 'strip']) : null,
            'noteHtml' => $report->note ? Str::markdown($report->note, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null,
            'pdf' => $pdf,
            'agency' => Setting::brand()['name'],
            'brand' => Setting::brand(),
            'pdfUrl' => $pdf ? null : route('public.report.pdf', [$report->client->report_token, $report]),
        ];
    }
}
