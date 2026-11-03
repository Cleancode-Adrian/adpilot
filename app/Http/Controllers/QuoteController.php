<?php

namespace App\Http\Controllers;

use App\Mail\ClientAlertMail;
use App\Mail\QuoteMail;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\User;
use App\Services\Quotes\QuoteTemplates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

/** Oferty: szablony, edycja pozycji, wysyłka, akceptacja online przez klienta, PDF. */
class QuoteController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status');

        return Inertia::render('quotes/index', [
            'quotes' => Quote::with('client:id,name')->with('items')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest('id')->limit(200)->get()
                ->map(fn (Quote $q) => [...$q->only(['id', 'number', 'title', 'status', 'valid_until', 'currency', 'sent_at', 'viewed_at', 'decided_at', 'created_at']),
                    'client' => $q->client?->only(['id', 'name']), 'totals' => $q->totals(), 'expired' => $q->isExpired()]),
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'templates' => collect(QuoteTemplates::TEMPLATES)->map(fn ($t) => $t['label']),
            'status' => $status,
            'stats' => [
                'sent' => Quote::where('status', 'sent')->count(),
                'accepted_month' => Quote::where('status', 'accepted')->where('decided_at', '>=', now()->startOfMonth())->count(),
                'accepted_value_month' => round(Quote::where('status', 'accepted')->where('decided_at', '>=', now()->startOfMonth())->with('items')->get()
                    ->sum(fn (Quote $q) => ($q->totals()['once']['net'] ?? 0) + 12 * ($q->totals()['monthly']['net'] ?? 0) + ($q->totals()['yearly']['net'] ?? 0)), 2),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'template' => ['nullable', Rule::in(array_keys(QuoteTemplates::TEMPLATES))],
        ]);
        $client = Client::findOrFail($data['client_id']);
        $tpl = QuoteTemplates::TEMPLATES[$data['template'] ?? ''] ?? null;

        $quote = DB::transaction(function () use ($client, $tpl) {
            $quote = Quote::newFor($client, $tpl ? ['title' => $tpl['title'], 'intro' => $tpl['intro']] : []);
            foreach ($tpl['items'] ?? [] as $pos => [$name, $desc, $qty, $unit, $price, $rec]) {
                $quote->items()->create(['position' => $pos, 'name' => $name, 'description' => $desc, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $price, 'recurring' => $rec]);
            }

            return $quote;
        });

        return redirect()->route('quotes.edit', $quote)->with('success', $tpl ? 'Oferta utworzona z szablonu – sprawdź ceny i opisy.' : 'Utworzono pustą ofertę.');
    }

    public function edit(Quote $quote)
    {
        $quote->load('items', 'client:id,name,contact_email,contact_name');

        return Inertia::render('quotes/edit', [
            'quote' => [...$quote->toArray(), 'expired' => $quote->isExpired()],
            'totals' => $quote->totals(),
            'public_url' => route('public.quote', $quote->token),
            'pdf_url' => route('public.quote.pdf', $quote->token),
        ]);
    }

    public function update(Request $request, Quote $quote)
    {
        abort_if(in_array($quote->status, ['accepted', 'rejected'], true), 403, 'Oferta po decyzji klienta jest zablokowana – zduplikuj ją, aby zmienić.');
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'intro' => 'nullable|string|max:10000',
            'terms' => 'nullable|string|max:10000',
            'valid_until' => 'nullable|date',
            'currency' => 'required|string|size:3',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'array|max:100',
            'items.*.name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string|max:5000',
            'items.*.quantity' => 'required|numeric|min:0|max:100000',
            'items.*.unit' => 'nullable|string|max:20',
            'items.*.unit_price' => 'required|numeric|min:0|max:10000000',
            'items.*.recurring' => ['nullable', Rule::in(['monthly', 'yearly'])],
        ]);

        DB::transaction(function () use ($quote, $data) {
            $quote->update(['currency' => strtoupper($data['currency'])] + collect($data)->except('items')->all());
            $quote->items()->delete();
            foreach ($data['items'] ?? [] as $pos => $i) {
                $quote->items()->create(['position' => $pos, 'unit' => $i['unit'] ?: 'szt.'] + $i);
            }
        });

        return back()->with('success', 'Zapisano ofertę.');
    }

    /** Wysyłka linku do oferty na e-mail kontaktowy klienta. */
    public function send(Request $request, Quote $quote)
    {
        $email = $request->validate(['email' => 'required|email'])['email'];
        try {
            Mail::to($email)->send(new QuoteMail($quote));
        } catch (Throwable $e) {
            return back()->with('error', 'Nie udało się wysłać e-maila ('.Str::limit($e->getMessage(), 120).'). Skopiuj link do oferty i wyślij go ręcznie.');
        }
        $quote->update(['status' => $quote->status === 'draft' ? 'sent' : $quote->status, 'sent_at' => now()]);

        return back()->with('success', "Oferta wysłana na $email.");
    }

    public function markSent(Quote $quote)
    {
        if ($quote->status === 'draft') {
            $quote->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return back()->with('success', 'Oznaczono jako wysłaną.');
    }

    public function duplicate(Quote $quote)
    {
        $copy = DB::transaction(function () use ($quote) {
            $copy = Quote::newFor($quote->client, ['title' => $quote->title, 'intro' => $quote->intro, 'terms' => $quote->terms, 'vat_rate' => $quote->vat_rate, 'currency' => $quote->currency]);
            foreach ($quote->items as $i) {
                $copy->items()->create($i->only(['position', 'name', 'description', 'quantity', 'unit', 'unit_price', 'recurring']));
            }

            return $copy;
        });

        return redirect()->route('quotes.edit', $copy)->with('success', 'Utworzono kopię oferty '.$copy->number.'.');
    }

    public function destroy(Quote $quote)
    {
        $quote->delete();

        return redirect()->route('quotes.index')->with('success', 'Usunięto ofertę.');
    }

    // ------------------------------------------------------------------ strona dla klienta

    public function public(Request $request, string $token)
    {
        $quote = Quote::where('token', $token)->with('items', 'client')->firstOrFail();
        if (! $quote->viewed_at && ! $request->user()) {
            $quote->update(['viewed_at' => now()]);
        }

        return view('quotes.public', $this->viewData($quote, false));
    }

    public function pdf(string $token)
    {
        $quote = Quote::where('token', $token)->with('items', 'client')->firstOrFail();

        return Pdf::loadView('quotes.public', $this->viewData($quote, true))->setPaper('a4')
            ->download(Str::slug($quote->number.'-'.$quote->client->name).'.pdf');
    }

    public function decide(Request $request, string $token)
    {
        $quote = Quote::where('token', $token)->with('client')->firstOrFail();
        if ($quote->status !== 'sent' || $quote->isExpired()) {
            return back()->with('quote_error', $quote->isExpired() ? 'Oferta wygasła – skontaktuj się z nami, przygotujemy aktualną.' : 'Ta oferta nie czeka już na decyzję.');
        }
        $data = $request->validate([
            'decision' => 'required|in:accept,reject',
            'name' => 'required_if:decision,accept|nullable|string|max:150',
            'agree' => 'accepted_if:decision,accept',
            'reason' => 'nullable|string|max:2000',
        ], ['name.required_if' => 'Podaj imię i nazwisko osoby akceptującej.', 'agree.accepted_if' => 'Zaznacz potwierdzenie akceptacji.']);

        $accept = $data['decision'] === 'accept';
        $quote->update([
            'status' => $accept ? 'accepted' : 'rejected',
            'decided_at' => now(),
            'decided_name' => $data['name'] ?? null,
            'decided_ip' => $request->ip(),
            'rejection_reason' => $accept ? null : ($data['reason'] ?? null),
        ]);

        try {
            foreach (User::where('is_admin', true)->pluck('email') as $email) {
                Mail::to($email)->send(new ClientAlertMail(
                    ($accept ? '✅ Oferta zaakceptowana: ' : '❌ Oferta odrzucona: ').$quote->number.' – '.$quote->client->name,
                    array_filter([
                        '**Oferta:** '.$quote->title,
                        $accept ? '**Zaakceptował(a):** '.$quote->decided_name : null,
                        ! $accept && $quote->rejection_reason ? '**Powód:** '.$quote->rejection_reason : null,
                        '**Kiedy:** '.now()->format('d.m.Y H:i').' (IP '.$request->ip().')',
                    ]),
                    route('quotes.edit', $quote),
                    $accept ? 'Skontaktuj się z klientem i zaplanuj start prac. Dodaj abonament w Rozliczeniach klienta.' : null,
                ));
            }
        } catch (Throwable $e) {
            Log::warning('Quote decision mail failed: '.$e->getMessage());
        }

        return back()->with('quote_success', $accept ? 'Dziękujemy! Oferta została zaakceptowana – skontaktujemy się w sprawie dalszych kroków.' : 'Dziękujemy za informację.');
    }

    private function viewData(Quote $quote, bool $pdf): array
    {
        return ['quote' => $quote, 'totals' => $quote->totals(), 'pdf' => $pdf, 'brand' => Setting::brand(), 'agency' => Setting::brand()['name'],
            'pdfUrl' => $pdf ? null : route('public.quote.pdf', $quote->token)];
    }
}
