<?php

namespace App\Http\Requests\Auth;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\DeviceGuard;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * Sprawdza e-mail i hasło BEZ logowania – przy włączonej weryfikacji dwuetapowej
     * użytkownik zostaje zalogowany dopiero po podaniu kodu.
     *
     * @throws ValidationException
     */
    public function validateCredentials(): User
    {
        $this->ensureIsNotRateLimited();

        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials($this->only('email'));

        if (! $user instanceof User || ! $provider->validateCredentials($user, $this->only('password'))) {
            RateLimiter::hit($this->throttleKey(), 900); // blokada na 15 min po 5 próbach
            SecurityEvent::record('login_failed', $user instanceof User ? $user : null, $this, email: Str::limit((string) $this->input('email'), 190));

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));
        $this->notifyLockout();

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    /** Blokada konta po serii prób – wpis w dzienniku i e-mail do właściciela (najwyżej raz na godzinę). */
    private function notifyLockout(): void
    {
        $user = User::where('email', $this->input('email'))->first();
        if (! Cache::add('lockout-notice:'.$this->throttleKey(), 1, 3600)) {
            return;
        }
        SecurityEvent::record('login_locked', $user, $this, email: Str::limit((string) $this->input('email'), 190));
        if ($user) {
            DeviceGuard::notify($user, 'Wiele nieudanych prób logowania', [
                'Ktoś kilka razy wpisał błędne hasło do Twojego konta. Logowanie z tego adresu jest zablokowane na 15 minut.',
                '**IP:** '.$this->ip(),
                '**Kiedy:** '.now()->format('d.m.Y H:i'),
            ], 'Jeśli to nie Ty – nic nie musisz robić, ale upewnij się, że masz silne hasło i włączoną weryfikację dwuetapową.');
        }
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
