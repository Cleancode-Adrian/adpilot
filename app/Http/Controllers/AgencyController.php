<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WpEvent;
use App\Services\Billing\AgencySettlement;
use App\Services\Care\CarePlanner;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Agencje (zleceniodawcy) i strony, które obsługujesz na ich zlecenie. */
class AgencyController extends Controller
{
    public function index(Request $request)
    {
        $archived = $request->boolean('archived');
        $agencies = Agency::query()
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->active())
            ->with(['clients' => fn ($q) => $q->active()->with('siteMonitor')->orderBy('name')])
            ->orderBy('name')->get();
        $planner = app(CarePlanner::class);

        return Inertia::render('agencies/index', [
            'agencies' => $agencies->map(fn (Agency $a) => [
                ...$a->only(['id', 'name', 'website_url', 'contact_name', 'contact_email', 'contact_phone', 'white_label', 'archived_at']),
                'notes_excerpt' => $a->notes ? Str::limit($a->notes, 140) : null,
                'sites' => $a->clients->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name, 'website_url' => $c->website_url])->values(),
                'sites_down' => $a->clients->filter(fn (Client $c) => $c->siteMonitor?->enabled && $c->siteMonitor->status === 'down')->count(),
                'overdue' => $a->clients->sum(fn (Client $c) => $c->has_care || $c->has_general ? ($planner->summary($c)['overdue'] ?? 0) : 0),
                'wp_alerts' => $a->clients->isEmpty() ? 0 : WpEvent::alerts()->whereIn('client_id', $a->clients->pluck('id'))->count(),
            ]),
            'showArchived' => $archived,
            'archivedCount' => Agency::whereNotNull('archived_at')->count(),
            'unassignedCount' => Client::active()->whereNull('agency_id')->count(),
        ]);
    }

    public function create()
    {
        return Inertia::render('agencies/form', ['agency' => null]);
    }

    public function store(Request $request)
    {
        $agency = Agency::create($this->validated($request));
        $this->syncLogo($request, $agency);

        return redirect()->route('agencies.show', $agency)->with('success', 'Agencja dodana. Teraz dodaj strony, które dla niej obsługujesz.');
    }

    public function show(Request $request, Agency $agency)
    {
        return Inertia::render('agencies/show', [
            'agency' => [
                ...$agency->only(['id', 'name', 'website_url', 'tax_id', 'contact_name', 'contact_email', 'contact_phone', 'white_label', 'brand_color', 'report_footer', 'terms', 'notes', 'archived_at']),
                'logo_url' => $agency->logoUrl(),
                'request_url' => $agency->requestUrl(),
                'billing' => $agency->only(['billing_name', 'billing_street', 'billing_post_code', 'billing_city', 'billing_email']),
                // stawka tylko dla administratora (ceny)
                'hourly_rate' => $request->user()->is_admin ? $agency->hourly_rate : null,
                'currency' => $agency->currency,
            ],
            'clients' => ClientController::rows($agency->clients()->active()),
            'archivedClients' => $agency->clients()->whereNotNull('archived_at')->orderBy('name')->get(['id', 'name', 'website_url']),
            // strony bez agencji – do szybkiego przypisania
            'unassigned' => Client::active()->whereNull('agency_id')->orderBy('name')->get(['id', 'name', 'website_url']),
        ]);
    }

    public function edit(Request $request, Agency $agency)
    {
        $data = [...$agency->toArray(), 'logo_url' => $agency->logoUrl()];
        if (! $request->user()->is_admin) {
            $data['hourly_rate'] = null; // stawka tylko dla administratora
        }

        return Inertia::render('agencies/form', ['agency' => $data]);
    }

    public function update(Request $request, Agency $agency)
    {
        $agency->update($this->validated($request));
        $this->syncLogo($request, $agency);

        return redirect()->route('agencies.show', $agency)->with('success', 'Zapisano zmiany.');
    }

    public function destroy(Agency $agency)
    {
        $count = $agency->clients()->count();
        $agency->logo && Storage::disk('public')->delete($agency->logo);
        $agency->delete(); // strony zostają – tracą tylko przypisanie do agencji (nullOnDelete)

        return redirect()->route('agencies.index')->with('success', $count
            ? "Agencja usunięta. Jej strony ({$count}) zostały na liście klientów, bez przypisanej agencji."
            : 'Agencja usunięta.');
    }

    public function archive(Agency $agency)
    {
        $archived = ! $agency->archived_at;
        $agency->update(['archived_at' => $archived ? now() : null]);

        return redirect()->route($archived ? 'agencies.index' : 'agencies.show', $archived ? [] : $agency)
            ->with('success', $archived ? "Agencja „{$agency->name}” zarchiwizowana. Strony i dane zostają." : 'Agencja przywrócona.');
    }

    /** Przypisz istniejącą stronę (klienta) do agencji. */
    public function attach(Request $request, Agency $agency)
    {
        $data = $request->validate(['client_id' => 'required|integer|exists:clients,id']);
        $client = Client::findOrFail($data['client_id']);
        $client->update(['agency_id' => $agency->id]);

        return back()->with('success', "Strona „{$client->name}” przypisana do agencji {$agency->name}.");
    }

    /** Odepnij stronę od agencji (strona zostaje na liście klientów). */
    public function detach(Agency $agency, Client $client)
    {
        abort_unless($client->agency_id === $agency->id, 404);
        $client->update(['agency_id' => null]);

        return back()->with('success', "Strona „{$client->name}” nie jest już przypisana do agencji.");
    }

    // ---------------------------------------------------------------- rozliczenie miesięczne (tylko administrator)

    public function settlement(Request $request, Agency $agency, AgencySettlement $settlement)
    {
        $month = AgencySettlement::month($request->input('month'));

        return Inertia::render('agencies/settlement', [
            'agency' => $agency->only(['id', 'name', 'hourly_rate', 'currency', 'contact_email', 'tax_id']),
            'data' => $settlement->build($agency, $month),
        ]);
    }

    public function settlementPdf(Request $request, Agency $agency, AgencySettlement $settlement)
    {
        $month = AgencySettlement::month($request->input('month'));
        $data = $settlement->build($agency, $month);

        return Pdf::loadView('reports.agency-settlement', ['agency' => $agency, 'd' => $data, 'brand' => Setting::brand(), 'pdf' => true])
            ->setPaper('a4')
            ->download(Str::slug($agency->name).'-rozliczenie-'.$data['month'].'.pdf');
    }

    public function settlementInvoiced(Request $request, Agency $agency, AgencySettlement $settlement)
    {
        $month = AgencySettlement::month($request->input('month'));
        $n = $settlement->markInvoiced($agency, $month);

        return back()->with('success', "Oznaczono {$n} prac jako zafakturowane.");
    }

    /** Logo agencji do raportów white label (jak logo w ustawieniach agencji). */
    private function syncLogo(Request $request, Agency $agency): void
    {
        $request->validate(['logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048', 'remove_logo' => 'boolean']);
        if ($request->hasFile('logo')) {
            $old = $agency->logo;
            $agency->update(['logo' => $request->file('logo')->store('branding/agencies', 'public')]);
            $old && Storage::disk('public')->delete($old);
        } elseif ($request->boolean('remove_logo') && $agency->logo) {
            Storage::disk('public')->delete($agency->logo);
            $agency->update(['logo' => null]);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'website_url' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:20',
            'billing_name' => 'nullable|string|max:255',
            'billing_street' => 'nullable|string|max:255',
            'billing_post_code' => 'nullable|string|max:12',
            'billing_city' => 'nullable|string|max:100',
            'billing_email' => 'nullable|email|max:255',
            'contact_name' => 'nullable|string|max:150',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'white_label' => 'boolean',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'report_footer' => 'nullable|string|max:500',
            'hourly_rate' => 'nullable|numeric|min:0|max:100000',
            'currency' => 'nullable|string|size:3',
            'terms' => 'nullable|string|max:20000',
            'notes' => 'nullable|string|max:20000',
        ]);
        $data['currency'] = strtoupper($data['currency'] ?? 'PLN');
        // stawkę zmienia tylko administrator
        if (! $request->user()->is_admin) {
            unset($data['hourly_rate']);
        }
        if (filled($data['website_url'] ?? null)) {
            $url = Client::normalizeUrl($data['website_url']);
            if (! $url) {
                throw ValidationException::withMessages(['website_url' => 'Nieprawidłowy adres strony – wpisz np. agencja.pl']);
            }
            $data['website_url'] = $url;
        }

        return $data;
    }
}
