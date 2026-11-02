<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\DeviceGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Krok 2 logowania: kod z aplikacji uwierzytelniającej albo kod zapasowy. */
class TwoFactorChallengeController extends Controller
{
    private const TTL = 300; // na wpisanie kodu jest 5 minut

    public function create(Request $request)
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->with('status', 'Czas na wpisanie kodu minął – zaloguj się ponownie.');
        }
        $data = $request->validate(['code' => 'nullable|string|max:20', 'recovery_code' => 'nullable|string|max:40']);

        $key = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Za dużo prób. Spróbuj za '.ceil(RateLimiter::availableIn($key) / 60).' min.']);
        }

        $ok = filled($data['recovery_code'] ?? null)
            ? $user->useRecoveryCode($data['recovery_code'])
            : filled($data['code'] ?? null) && $user->verifyTwoFactorCode($data['code']);

        if (! $ok) {
            RateLimiter::hit($key, 900);
            SecurityEvent::record('two_factor_failed', $user, $request);
            throw ValidationException::withMessages([filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code' => 'Nieprawidłowy kod.']);
        }

        RateLimiter::clear($key);
        if (filled($data['recovery_code'] ?? null)) {
            SecurityEvent::record('recovery_code_used', $user, $request, ['left' => count((array) $user->two_factor_recovery_codes)]);
            DeviceGuard::notify($user, 'Użyto kodu zapasowego', [
                'Do logowania użyto jednego z kodów zapasowych. Pozostało: '.count((array) $user->two_factor_recovery_codes).'.',
                '**IP:** '.$request->ip(),
            ], 'Jeśli straciłeś telefon – ustaw weryfikację dwuetapową ponownie (Ustawienia → Bezpieczeństwo).');
        }

        return AuthenticatedSessionController::completeLogin($request, $user);
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $at = (int) $request->session()->get('login.at');
        if (! $id || now()->getTimestamp() - $at > self::TTL) {
            $request->session()->forget(['login.id', 'login.at']);

            return null;
        }

        return User::find($id);
    }
}
