<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administrator bez weryfikacji dwuetapowej nie wejdzie dalej niż do ustawień bezpieczeństwa.
 * (Konto admina ma dostęp do haseł klientów i stron – samo hasło to za mało.)
 */
class RequireTwoFactor
{
    private const ALLOWED = ['security.*', 'logout', 'password.confirm', 'two-factor.*', 'verification.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->is_admin && config('adpilot.security.require_2fa') && ! $user->two_factor_enabled
            && ! $request->routeIs(...self::ALLOWED)) {
            return redirect()->route('security.edit')
                ->with('error', 'Włącz weryfikację dwuetapową – bez niej konto administratora nie ma dostępu do panelu.');
        }

        return $next($request);
    }
}
