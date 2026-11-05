<?php

namespace App\Http\Controllers;

use App\Mail\ClientAlertMail;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Publiczny formularz zgłoszeń (link z tokenem, bez logowania):
 * klient zgłasza poprawkę na swojej stronie, agencja – na wybranej stronie ze swojej listy.
 * Zgłoszenie staje się zadaniem z terminem + e-mail do administratorów.
 */
class RequestFormController extends Controller
{
    /** @return array{0: ?Client, 1: ?Agency} */
    private function resolve(string $token): array
    {
        if ($client = Client::active()->where('request_token', $token)->first()) {
            return [$client, null];
        }
        $agency = Agency::active()->where('request_token', $token)->firstOrFail();

        return [null, $agency];
    }

    public function show(string $token)
    {
        [$client, $agency] = $this->resolve($token);

        return view('requests.form', $this->viewData($client, $agency));
    }

    public function submit(Request $request, string $token)
    {
        [$client, $agency] = $this->resolve($token);
        // pułapka na boty – pole niewidoczne dla ludzi
        if (filled($request->input('website'))) {
            return view('requests.form', ['sent' => true] + $this->viewData($client, $agency));
        }

        $data = $request->validate([
            'client_id' => [$agency ? 'required' : 'nullable', Rule::in($agency ? $agency->clients()->active()->pluck('id')->all() : [])],
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'urgent' => 'boolean',
        ], [], ['client_id' => 'strona', 'name' => 'imię i nazwisko', 'title' => 'temat', 'description' => 'opis']);

        $target = $client ?? Client::findOrFail($data['client_id']);
        $urgent = (bool) ($data['urgent'] ?? false);
        $due = now()->addWeekdays($urgent ? 1 : 3)->toDateString();
        $task = $target->careTasks()->create([
            'title' => ($urgent ? 'PILNE: ' : '').Str::limit($data['title'], 200, ''),
            'description' => $data['description'],
            'requester' => array_filter(['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                'agency' => $agency?->name, 'ip' => $request->ip(), 'at' => now()->toDateTimeString()]),
            'source' => 'request',
            'due_date' => $due,
            'period' => substr($due, 0, 7),
        ]);

        try {
            $lines = array_values(array_filter([
                '**'.$target->name.'**'.($agency ? ' (agencja '.$agency->name.')' : ''),
                'Od: '.$data['name'].collect([$data['email'] ?? null, $data['phone'] ?? null])->filter()->map(fn ($v) => ' · '.$v)->implode(''),
                'Termin: '.Carbon::parse($due)->format('d.m.Y').($urgent ? ' – PILNE' : ''),
                '',
                Str::limit($data['description'], 1500),
            ], fn ($l) => $l !== null));
            foreach (User::where('is_admin', true)->pluck('email') as $email) {
                Mail::to($email)->send(new ClientAlertMail(($urgent ? '🔴 Pilne zgłoszenie: ' : '📨 Zgłoszenie: ').Str::limit($data['title'], 80), $lines,
                    route('clients.care', $target), 'Zgłoszenie jest już zadaniem u klienta – odhacz je po wykonaniu.'));
            }
        } catch (Throwable $e) {
            Log::warning('Request mail failed: '.$e->getMessage());
        }

        return view('requests.form', ['sent' => true, 'due' => $due, 'taskId' => $task->id] + $this->viewData($client, $agency));
    }

    private function viewData(?Client $client, ?Agency $agency): array
    {
        // klient agencji white label widzi markę agencji; agencja – Twoją
        $brand = $client ? Setting::brandFor($client) : Setting::brand();

        return [
            'client' => $client,
            'agency' => $agency,
            'sites' => $agency ? $agency->clients()->active()->orderBy('name')->get(['id', 'name', 'website_url']) : collect(),
            'brand' => $brand,
            'sent' => false,
        ];
    }
}
