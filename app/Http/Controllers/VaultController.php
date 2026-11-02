<?php

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sejf haseł klientów: każde odsłonięcie wymaga świeżego potwierdzenia (hasło + kod 2FA).
 * Odblokowanie trwa krótko (domyślnie 2 min – można kilka haseł skopiować pod rząd), potem znów pyta.
 */
class VaultController extends Controller
{
    public function unlock(Request $request)
    {
        $user = $request->user();
        $key = 'vault:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Za dużo prób. Spróbuj za '.ceil(RateLimiter::availableIn($key) / 60).' min.'], 429);
        }

        $errors = [];
        if (! Hash::check((string) $request->input('password'), $user->password)) {
            $errors['password'] = 'Nieprawidłowe hasło.';
        } elseif ($user->two_factor_enabled && ! $user->verifyTwoFactorCode((string) $request->input('code'))) {
            $errors['code'] = 'Nieprawidłowy kod z aplikacji.';
        }
        if ($errors) {
            RateLimiter::hit($key, 900);
            SecurityEvent::record('vault_unlock_failed', $user, $request);

            return response()->json(['message' => reset($errors), 'errors' => $errors], 422);
        }

        RateLimiter::clear($key);
        $until = now()->getTimestamp() + (int) config('adpilot.security.vault_unlock_seconds');
        $request->session()->put('vault.unlocked_until', $until);
        SecurityEvent::record('vault_unlocked', $user, $request);

        return response()->json(['unlocked_until' => $until]);
    }

    public function lock(Request $request)
    {
        $request->session()->forget('vault.unlocked_until');

        return response()->json(['ok' => true]);
    }
}
