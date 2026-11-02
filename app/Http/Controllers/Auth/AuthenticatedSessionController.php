<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\DeviceGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Krok 1: e-mail + hasło. Przy włączonej weryfikacji dwuetapowej → krok 2 (kod z aplikacji).
     * „Zapamiętaj mnie” jest wyłączone – po zamknięciu sesji zawsze pełne logowanie.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $request->validateCredentials();

        if ($user->two_factor_enabled) {
            $request->session()->regenerate();
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.at', now()->getTimestamp());

            return redirect()->route('two-factor.login');
        }

        return self::completeLogin($request, $user);
    }

    public static function completeLogin(Request $request, User $user): RedirectResponse
    {
        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->forget(['login.id', 'login.at']);
        app(DeviceGuard::class)->onLogin($request, $user);

        return redirect()->intended(route('clients.index', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            SecurityEvent::record('logout', $user, $request);
        }
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
