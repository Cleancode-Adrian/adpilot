<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCredential;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Sejf dostępów klienta. Tylko administratorzy, po ponownym podaniu hasła do panelu
 * (middleware password.confirm). Hasła nie trafiają do przeglądarki, dopóki ktoś nie kliknie „Pokaż”
 * – każde odsłonięcie zapisuje się w dzienniku.
 */
class CredentialController extends Controller
{
    public function index(Client $client)
    {
        return Inertia::render('clients/credentials', [
            'client' => app(ClientController::class)->clientProps($client),
            'credentials' => $client->credentials()->orderBy('category')->orderBy('label')->get()
                ->map(fn (ClientCredential $c) => [
                    ...$c->only(['id', 'category', 'label', 'url', 'username', 'secret_changed_at', 'updated_at']),
                    'has_secret' => filled($c->getRawOriginal('secret')),
                    'has_notes' => filled($c->getRawOriginal('notes')),
                ]),
            'logs' => \App\Models\CredentialLog::where('client_id', $client->id)->with('user:id,name')->latest('id')->limit(30)->get(),
            'categories' => ClientCredential::CATEGORIES,
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $data = $this->data($request);
        $cred = $client->credentials()->create($data + ['secret_changed_at' => filled($data['secret'] ?? null) ? now() : null]);
        $cred->log('create', $request->user(), $request->ip());

        return back()->with('success', "Zapisano dostęp „{$cred->label}”.");
    }

    public function update(Request $request, ClientCredential $credential)
    {
        $data = $this->data($request);
        // Puste pole hasła = bez zmian (hasło nie jest wysyłane do formularza edycji)
        if (! filled($data['secret'] ?? null)) {
            unset($data['secret']);
        } elseif ($data['secret'] !== $credential->secret) {
            $data['secret_changed_at'] = now();
        }
        if (! $request->boolean('notes_changed')) {
            unset($data['notes']);
        }
        $credential->update($data);
        $credential->log('update', $request->user(), $request->ip());

        return back()->with('success', 'Zapisano zmiany.');
    }

    public function destroy(Request $request, ClientCredential $credential)
    {
        $credential->log('delete', $request->user(), $request->ip());
        $credential->delete();

        return back()->with('success', 'Usunięto dostęp.');
    }

    /** Odsłania hasło i notatki (JSON) – z wpisem do dziennika. */
    public function reveal(Request $request, ClientCredential $credential)
    {
        $credential->log('view', $request->user(), $request->ip());

        return response()->json(['secret' => $credential->secret, 'notes' => $credential->notes])
            ->header('Cache-Control', 'no-store');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(array_keys(ClientCredential::CATEGORIES))],
            'label' => 'required|string|max:255',
            'url' => 'nullable|string|max:500',
            'username' => 'nullable|string|max:255',
            'secret' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:10000',
        ]);
    }
}
