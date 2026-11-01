<?php

namespace App\Services\Connectors;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** OAuth 2.0 Google – jedno połączenie daje dostęp do Google Ads i GA4. */
class GoogleAuth
{
    public static function configured(): bool
    {
        return filled(config('adpilot.google.client_id')) && filled(config('adpilot.google.client_secret'));
    }

    public function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('adpilot.google.client_id'),
            'redirect_uri' => route('oauth.google.callback'),
            'response_type' => 'code',
            'scope' => implode(' ', config('adpilot.google.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('adpilot.google.client_id'),
            'client_secret' => config('adpilot.google.client_secret'),
            'redirect_uri' => route('oauth.google.callback'),
            'grant_type' => 'authorization_code',
        ]);
        if ($res->failed()) {
            throw new RuntimeException('Google OAuth: '.$res->json('error_description', $res->body()));
        }

        return $res->json();
    }

    /** Zwraca ważny access token, odświeżając go w razie potrzeby. */
    public function token(Integration $integration): string
    {
        if ($integration->access_token && $integration->token_expires_at?->isFuture()) {
            return $integration->access_token;
        }
        if (! $integration->refresh_token) {
            throw new RuntimeException('Brak refresh tokena Google – połącz konto ponownie.');
        }

        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('adpilot.google.client_id'),
            'client_secret' => config('adpilot.google.client_secret'),
            'refresh_token' => $integration->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if ($res->failed()) {
            throw new RuntimeException('Nie udało się odświeżyć tokena Google: '.$res->json('error_description', $res->body()));
        }

        $integration->update([
            'access_token' => $res->json('access_token'),
            'token_expires_at' => now()->addSeconds($res->json('expires_in', 3600) - 120),
        ]);

        return $integration->access_token;
    }
}
