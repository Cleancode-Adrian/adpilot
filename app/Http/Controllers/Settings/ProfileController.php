<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $oldEmail = $user->getOriginal('email');
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            // zmiana adresu = przejęcie konta przez reset hasła byłoby proste – wymagamy hasła
            $request->validate(['current_password' => ['required', 'current_password']], ['current_password.required' => 'Podaj obecne hasło, aby zmienić adres e-mail.']);
            $user->email_verified_at = null;
            \App\Models\SecurityEvent::record('email_changed', $user, $request, ['from' => $oldEmail, 'to' => $user->email]);
            $tmp = clone $user;
            $tmp->email = $oldEmail;
            \App\Services\Security\DeviceGuard::notify($tmp, 'Zmieniono adres e-mail konta', ['Adres e-mail konta zmieniono na: '.$user->email, '**IP:** '.$request->ip()], 'Jeśli to nie Ty – natychmiast skontaktuj się z administratorem.');
        }

        $user->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
