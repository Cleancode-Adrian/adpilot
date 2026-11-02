<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Odczyt hasła z sejfu tylko w krótkim oknie po potwierdzeniu hasłem + kodem (423 = poproś o potwierdzenie). */
class EnsureVaultUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->session()->get('vault.unlocked_until') < now()->getTimestamp()) {
            return response()->json(['message' => 'Potwierdź hasłem, aby zobaczyć dane.', 'unlock' => true], 423);
        }

        return $next($request);
    }
}
