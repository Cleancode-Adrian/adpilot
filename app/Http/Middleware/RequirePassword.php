<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword as BaseRequirePassword;

/**
 * Jak standardowe password.confirm, ale dla akcji (POST/DELETE – np. kliknięcie „Włącz 2FA”) po potwierdzeniu
 * wraca na stronę, z której kliknięto, a nie pod adres akcji (którego nie da się otworzyć jako strony).
 */
class RequirePassword extends BaseRequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if (! $request->isMethod('GET') && ! $request->expectsJson() && $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            $request->session()->put('url.intended', url()->previous());

            return redirect()->route($redirectToRoute ?: 'password.confirm');
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
