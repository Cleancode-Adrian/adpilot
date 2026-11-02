<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = $request->user();
        $user->update(['password' => Hash::make($validated['password'])]);

        // po zmianie hasła inne zalogowane urządzenia tracą dostęp
        \Illuminate\Support\Facades\Auth::logoutOtherDevices($validated['password']);
        if (config('session.driver') === 'database') {
            \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        \App\Models\SecurityEvent::record('password_changed', $user, $request);
        \App\Services\Security\DeviceGuard::notify($user, 'Zmieniono hasło', ['Hasło do panelu zostało zmienione. Pozostałe urządzenia zostały wylogowane.', '**IP:** '.$request->ip()], 'Jeśli to nie Ty – użyj „Nie pamiętasz hasła?” na stronie logowania i skontaktuj się z administratorem.');

        return back();
    }
}
