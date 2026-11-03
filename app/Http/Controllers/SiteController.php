<?php

namespace App\Http\Controllers;

use App\Jobs\RunSiteAuditJob;
use App\Models\Client;
use App\Models\Setting;
use App\Models\SiteAudit;
use App\Services\Monitoring\UptimeMonitor;
use App\Services\SiteAudit\SiteAuditor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** Zakładka „Strona”: dostępność + SSL i audyt SEO/techniczny. */
class SiteController extends Controller
{
    public function index(Client $client)
    {
        $monitor = $client->siteMonitor;
        $audit = $client->siteAudits()->latest('id')->first();
        $previous = $audit ? $client->siteAudits()->where('status', 'done')->where('id', '<', $audit->id)->latest('id')->first() : null;
        $codesNow = collect($audit?->issues ?? [])->pluck('code');
        $codesPrev = collect($previous?->issues ?? [])->pluck('code');

        return Inertia::render('clients/site', [
            'client' => app(ClientController::class)->clientProps($client),
            'monitor' => $monitor ? [
                ...$monitor->only(['enabled', 'url', 'status', 'last_checked_at', 'last_status_code', 'last_response_ms', 'last_error', 'down_since', 'ssl_expires_at', 'ssl_issuer', 'ssl_error']),
                'ssl_days' => $monitor->sslDaysLeft(),
                'uptime_7' => UptimeMonitor::uptimePercent($client->id, 7),
                'uptime_30' => UptimeMonitor::uptimePercent($client->id, 30),
                'avg_ms_7' => (int) (DB::table('uptime_daily')->where('client_id', $client->id)->where('date', '>=', now()->subDays(6)->toDateString())
                    ->selectRaw('SUM(total_ms) / NULLIF(SUM(checks - failures), 0) as v')->value('v') ?? 0) ?: null,
            ] : null,
            'incidents' => DB::table('uptime_incidents')->where('client_id', $client->id)->orderByDesc('started_at')->limit(10)->get(),
            'audit' => $audit,
            'comparison' => $audit && $previous ? [
                'previous_score' => $previous->score,
                'previous_date' => $previous->created_at,
                'fixed' => collect($previous->issues)->whereNotIn('code', $codesNow)->pluck('title')->values(),
                'new' => collect($audit->issues ?? [])->whereNotIn('code', $codesPrev)->pluck('title')->values(),
            ] : null,
            'history' => $client->siteAudits()->latest('id')->limit(12)->get(['id', 'status', 'score', 'pages_crawled', 'created_at']),
            'public_url' => $audit?->status === 'done' ? route('public.site-audit', [$client->report_token, $audit]) : null,
            'pdf_url' => $audit?->status === 'done' ? route('public.site-audit.pdf', [$client->report_token, $audit]) : null,
        ]);
    }

    public function runAudit(Client $client, SiteAuditor $auditor)
    {
        if ($client->siteAudits()->whereIn('status', ['pending', 'running'])->where('created_at', '>', now()->subMinutes(20))->exists()) {
            return back()->with('error', 'Audyt już trwa – odśwież stronę za chwilę.');
        }
        RunSiteAuditJob::dispatch($auditor->start($client));

        return back()->with('success', 'Audyt uruchomiony – sprawdzenie do 60 podstron trwa zwykle 1–3 minuty. Odśwież stronę.');
    }

    public function updateMonitor(Request $request, Client $client)
    {
        $data = $request->validate(['enabled' => 'boolean', 'url' => 'nullable|string|max:500']);
        $url = filled($data['url'] ?? null) ? Client::normalizeUrl($data['url']) : $client->website_url;
        if (! $url) {
            return back()->with('error', 'Nieprawidłowy adres.');
        }
        $client->siteMonitor()->updateOrCreate([], ['url' => $url, 'enabled' => $data['enabled'] ?? true]);

        return back()->with('success', 'Zapisano monitoring.');
    }

    public function checkNow(Client $client, UptimeMonitor $uptime)
    {
        $monitor = $client->siteMonitor()->firstOrCreate([], ['url' => $client->website_url]);
        $uptime->checkMonitors(collect([$monitor]));
        $uptime->checkSsl($monitor->fresh());

        return back()->with('success', 'Sprawdzono stronę i certyfikat SSL.');
    }

    // ------------------------------------------------------------------ raport dla klienta

    public function public(string $token, SiteAudit $audit)
    {
        $this->authorizePublic($token, $audit);

        return view('reports.site-audit', $this->viewData($audit, false));
    }

    public function pdf(string $token, SiteAudit $audit)
    {
        $this->authorizePublic($token, $audit);

        return Pdf::loadView('reports.site-audit', $this->viewData($audit, true))->setPaper('a4')
            ->download(Str::slug($audit->client->name).'-audyt-strony-'.$audit->created_at->format('Y-m-d').'.pdf');
    }

    private function authorizePublic(string $token, SiteAudit $audit): void
    {
        abort_unless(hash_equals($audit->client->report_token, $token) && $audit->status === 'done', 404);
    }

    private function viewData(SiteAudit $audit, bool $pdf): array
    {
        return [
            'audit' => $audit,
            'client' => $audit->client,
            'pdf' => $pdf,
            'brand' => Setting::brand(),
            'agency' => Setting::brand()['name'],
            'pdfUrl' => $pdf ? null : route('public.site-audit.pdf', [$audit->client->report_token, $audit]),
            'uptime' => UptimeMonitor::uptimePercent($audit->client_id, 30),
        ];
    }
}
