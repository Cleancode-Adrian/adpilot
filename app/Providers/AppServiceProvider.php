<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Własny pakiet certyfikatów CA (np. gdy php.ini ma błędną ścieżkę curl.cainfo)
        if ($ca = config('adpilot.http_ca_bundle')) {
            Http::globalOptions(['verify' => $ca]);
        }

        Gate::define('admin', fn ($user) => (bool) $user->is_admin);

        // Silne hasła: min. 12 znaków, małe i wielkie litery, cyfry, znak specjalny, nie z wycieków (haveibeenpwned – k-anonimowość)
        \Illuminate\Validation\Rules\Password::defaults(fn () => \Illuminate\Validation\Rules\Password::min(12)->letters()->mixedCase()->numbers()->symbols()
            ->when(app()->isProduction(), fn ($rule) => $rule->uncompromised()));

        Event::listen(Login::class, fn (Login $e) => $e->user->forceFill(['last_login_at' => now()])->saveQuietly());

        // Polski e-mail resetu hasła (używany też przy zaproszeniu nowej osoby)
        ResetPassword::toMailUsing(function ($user, string $token) {
            $url = url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false));
            $brand = Setting::brand()['name'];
            $isInvite = $user->last_login_at === null;

            return (new MailMessage)
                ->subject($isInvite ? "Zaproszenie do panelu {$brand}" : "Reset hasła – {$brand}")
                ->greeting("Cześć {$user->name}!")
                ->line($isInvite
                    ? "Założyliśmy Ci konto w panelu {$brand}. Kliknij przycisk, aby ustawić własne hasło."
                    : 'Otrzymaliśmy prośbę o reset hasła do Twojego konta.')
                ->action($isInvite ? 'Ustaw hasło' : 'Ustaw nowe hasło', $url)
                ->line('Link jest ważny przez '.config('auth.passwords.users.expire').' minut.')
                ->line($isInvite ? 'Login: '.$user->email : 'Jeśli to nie Ty – zignoruj tę wiadomość.')
                ->salutation("Pozdrawiamy,\n{$brand}");
        });
    }
}
