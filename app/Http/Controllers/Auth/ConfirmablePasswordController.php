<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Ponowne potwierdzenie tożsamości przed wrażliwą operacją: hasło + kod z aplikacji (jeśli włączona 2FA). */
class ConfirmablePasswordController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('auth/confirm-password', ['needsCode' => (bool) $request->user()->two_factor_enabled]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $key = 'confirm:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Za dużo prób. Spróbuj za '.ceil(RateLimiter::availableIn($key) / 60).' min.']);
        }

        if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => (string) $request->password])) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }
        if ($user->two_factor_enabled && ! $user->verifyTwoFactorCode((string) $request->input('code'))) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['code' => 'Nieprawidłowy kod z aplikacji.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('clients.index', absolute: false));
    }
}
