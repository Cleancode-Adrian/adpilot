<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nagłówki bezpieczeństwa: HSTS, zakaz osadzania w ramkach, nosniff, Permissions-Policy
 * i Content-Security-Policy z nonce (skrypty tylko z panelu – wstrzyknięty obcy skrypt się nie wykona).
 * SECURITY_CSP=report → tryb tylko-raportujący (gdyby coś przestało działać), off → wyłączone.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = config('adpilot.security.csp');
        $csp = $mode !== 'off' && ! (app()->isLocal() && Vite::isRunningHot()); // lokalny serwer Vite (npm run dev) – bez CSP
        if ($csp) {
            Vite::useCspNonce();
        }

        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Frame-Options', 'DENY');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($csp && str_contains((string) $h->get('Content-Type'), 'text/html')) {
            $nonce = Vite::cspNonce();
            $policy = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                "font-src 'self' data: https://fonts.bunny.net",
                "img-src 'self' data: blob:",
                "connect-src 'self'",
                "frame-src 'none'",
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
            $h->set($mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $policy);
        }

        return $response;
    }
}
