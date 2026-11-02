<?php

namespace App\Services\Security;

use App\Mail\SecurityAlertMail;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rozpoznaje przeglądarki, z których użytkownik już się logował (losowy token w zaszyfrowanym ciasteczku).
 * Logowanie z nowej przeglądarki → wpis w dzienniku + e-mail do właściciela konta.
 */
class DeviceGuard
{
    public const COOKIE = 'cc_device';

    public function onLogin(Request $request, User $user): void
    {
        $token = (string) $request->cookie(self::COOKIE);
        $device = $token !== '' ? $user->devices()->where('token_hash', hash('sha256', $token))->first() : null;

        if ($device) {
            $device->update(['last_used_at' => now(), 'ip' => $request->ip()]);
            SecurityEvent::record('login', $user, $request);

            return;
        }

        $isFirstEver = ! $user->devices()->exists();
        $token = Str::random(64);
        $user->devices()->create([
            'token_hash' => hash('sha256', $token),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'ip' => $request->ip(),
            'last_used_at' => now(),
        ]);
        Cookie::queue(Cookie::make(self::COOKIE, $token, 60 * 24 * 400, '/', null, $request->isSecure(), true, false, 'lax'));

        SecurityEvent::record($isFirstEver ? 'login' : 'login_new_device', $user, $request);
        if (! $isFirstEver) {
            self::notify($user, 'Logowanie z nowego urządzenia', [
                'Ktoś zalogował się na Twoje konto z przeglądarki, której wcześniej nie używałeś.',
                '**Kiedy:** '.now()->format('d.m.Y H:i'),
                '**IP:** '.$request->ip(),
                '**Przeglądarka:** '.Str::limit((string) $request->userAgent(), 160),
            ], 'Jeśli to nie Ty – od razu zmień hasło (Ustawienia → Hasło) i wyloguj pozostałe urządzenia (Ustawienia → Bezpieczeństwo).');
        }
    }

    /** E-mail bezpieczeństwa do właściciela konta. Awaria poczty nie może blokować logowania. */
    public static function notify(User $user, string $subject, array $lines, ?string $action = null): void
    {
        try {
            Mail::to($user->email)->send(new SecurityAlertMail($subject, $lines, $action));
        } catch (Throwable $e) {
            Log::warning('Security mail failed: '.$e->getMessage());
        }
    }
}
