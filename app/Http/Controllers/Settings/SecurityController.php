<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\UserDevice;
use App\Services\Security\DeviceGuard;
use App\Services\Security\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Ustawienia → Bezpieczeństwo: weryfikacja dwuetapowa, kody zapasowe, urządzenia, sesje, dziennik. */
class SecurityController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        $setupSecret = $request->session()->get('two_factor.setup_secret');
        $issuer = config('app.name') ?: 'Panel';

        return Inertia::render('settings/security', [
            'twoFactor' => [
                'enabled' => $user->two_factor_enabled,
                'confirmed_at' => $user->two_factor_confirmed_at,
                'recovery_left' => $user->two_factor_enabled ? count((array) $user->two_factor_recovery_codes) : 0,
                'required' => $user->is_admin && config('adpilot.security.require_2fa'),
                'setup' => $setupSecret ? [
                    'secret' => trim(chunk_split($setupSecret, 4, ' ')),
                    'qr' => Totp::qrSvg(Totp::uri($setupSecret, $user->email, $issuer)),
                ] : null,
            ],
            'recoveryCodes' => $request->session()->get('two_factor.recovery_codes'), // pokazane raz, po włączeniu / wygenerowaniu
            'devices' => $user->devices()->latest('last_used_at')->get(['id', 'user_agent', 'ip', 'last_used_at', 'created_at'])
                ->map(fn ($d) => [...$d->toArray(), 'current' => hash_equals((string) $d->getRawOriginal('token_hash'), hash('sha256', (string) $request->cookie(DeviceGuard::COOKIE)))]),
            'sessions' => config('session.driver') === 'database'
                ? DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                    ->map(fn ($s) => ['ip' => $s->ip_address, 'agent' => Str::limit((string) $s->user_agent, 140), 'last_activity' => date('c', $s->last_activity), 'current' => $s->id === $request->session()->getId()])
                : [],
            'events' => SecurityEvent::with('user:id,name')
                ->when(! $user->is_admin, fn ($q) => $q->where('user_id', $user->id))
                ->latest('id')->limit(60)->get()
                ->map(fn ($e) => [...$e->only(['id', 'type', 'email', 'ip', 'user_agent', 'created_at']), 'label' => SecurityEvent::LABELS[$e->type] ?? $e->type, 'user' => $e->user?->name]),
            'eventsScope' => $user->is_admin ? 'all' : 'own',
        ]);
    }

    /** Krok 1: nowy sekret (jeszcze nieaktywny – dopiero po wpisaniu poprawnego kodu). */
    public function enable(Request $request)
    {
        $request->session()->put('two_factor.setup_secret', Totp::generateSecret());

        return back();
    }

    /** Krok 2: potwierdzenie kodem z aplikacji → włączenie + kody zapasowe. */
    public function confirm(Request $request)
    {
        $secret = $request->session()->get('two_factor.setup_secret');
        $code = (string) $request->validate(['code' => 'required|string|max:10'])['code'];
        $step = $secret ? Totp::verify($secret, $code) : null;
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'Nieprawidłowy kod. Sprawdź, czy czas w telefonie ustawia się automatycznie.']);
        }

        $user = $request->user();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
        $codes = $user->regenerateRecoveryCodes();
        $request->session()->forget('two_factor.setup_secret');
        $request->session()->flash('two_factor.recovery_codes', $codes);
        SecurityEvent::record('two_factor_enabled', $user, $request);
        DeviceGuard::notify($user, 'Włączono weryfikację dwuetapową', ['Logowanie do panelu wymaga teraz kodu z aplikacji w telefonie.']);

        return back()->with('success', 'Weryfikacja dwuetapowa włączona. Zapisz kody zapasowe w bezpiecznym miejscu.');
    }

    public function cancelSetup(Request $request)
    {
        $request->session()->forget('two_factor.setup_secret');

        return back();
    }

    public function recoveryCodes(Request $request)
    {
        $user = $request->user();
        abort_unless($user->two_factor_enabled, 400);
        $request->session()->flash('two_factor.recovery_codes', $user->regenerateRecoveryCodes());
        SecurityEvent::record('recovery_codes_regenerated', $user, $request);

        return back()->with('success', 'Wygenerowano nowe kody zapasowe – stare przestały działać.');
    }

    public function disable(Request $request)
    {
        $user = $request->user();
        if ($user->is_admin && config('adpilot.security.require_2fa')) {
            return back()->with('error', 'Konto administratora musi mieć włączoną weryfikację dwuetapową. Możesz ją ustawić od nowa (np. na nowym telefonie).');
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        SecurityEvent::record('two_factor_disabled', $user, $request);
        DeviceGuard::notify($user, 'Wyłączono weryfikację dwuetapową', ['Logowanie do panelu wymaga teraz tylko hasła.'], 'Jeśli to nie Ty – natychmiast zmień hasło.');

        return back()->with('success', 'Weryfikacja dwuetapowa wyłączona.');
    }

    /** Ustawienie 2FA od nowa (nowy telefon) – dla admina, który nie może jej wyłączyć. */
    public function reset(Request $request)
    {
        $request->session()->put('two_factor.setup_secret', Totp::generateSecret());

        return back()->with('success', 'Zeskanuj nowy kod QR. Dotychczasowa aplikacja działa do momentu potwierdzenia nowej.');
    }

    /** Wylogowanie wszystkich innych sesji (np. zgubiony laptop). */
    public function logoutOthers(Request $request)
    {
        $password = (string) $request->validate(['password' => 'required|string'])['password'];
        $user = $request->user();
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Nieprawidłowe hasło.']);
        }
        Auth::logoutOtherDevices($password);
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        SecurityEvent::record('sessions_revoked', $user, $request);

        return back()->with('success', 'Wylogowano wszystkie inne urządzenia.');
    }

    public function removeDevice(Request $request, UserDevice $device)
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $device->delete();
        SecurityEvent::record('device_removed', $request->user(), $request);

        return back()->with('success', 'Usunięto urządzenie – kolejne logowanie z niego wyśle ostrzeżenie.');
    }
}
