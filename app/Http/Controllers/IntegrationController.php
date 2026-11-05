<?php

namespace App\Http\Controllers;

use App\Jobs\SyncClientJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Models\Client;
use App\Models\Integration;
use App\Services\Connectors\Ga4Connector;
use App\Services\Connectors\GoogleAdsConnector;
use App\Services\Connectors\GoogleAuth;
use App\Services\Connectors\MetaConnector;
use App\Services\Connectors\SearchConsoleConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

class IntegrationController extends Controller
{
    public function index(Client $client)
    {
        $client->load('integrations');

        return Inertia::render('clients/integrations', [
            'client' => app(ClientController::class)->clientProps($client),
            'accounts' => $client->integrations->mapWithKeys(fn ($i) => [$i->provider => $i->available_accounts ?? []]),
            'redirects' => [
                'google' => route('oauth.google.callback'),
                'meta' => route('oauth.meta.callback'),
            ],
            'ads_token_configured' => filled(config('adpilot.google.ads_developer_token')),
        ]);
    }

    // ---------------------------------------------------------------- Google

    public function googleRedirect(Request $request, Client $client, GoogleAuth $auth)
    {
        abort_unless(GoogleAuth::configured(), 400, 'Brak GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET w .env');
        // powrót po połączeniu: zakładka SEO albo integracje reklam
        session(['oauth_google_back' => $request->input('back') === 'seo' ? 'seo' : 'integrations']);

        return redirect()->away($auth->authUrl($this->state($client, 'google')));
    }

    public function googleCallback(Request $request, GoogleAuth $auth, GoogleAdsConnector $ads, Ga4Connector $ga4, SearchConsoleConnector $gsc)
    {
        $client = $this->clientFromState($request, 'google');
        $back = session()->pull('oauth_google_back') === 'seo' ? 'clients.seo' : 'clients.integrations';
        if ($request->filled('error')) {
            return redirect()->route($back, $client)->with('error', 'Google: '.$request->input('error'));
        }

        try {
            $token = $auth->exchangeCode($request->input('code'));
            $integration = $client->integrations()->updateOrCreate(['provider' => 'google'], [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $client->integration('google')?->refresh_token,
                'token_expires_at' => now()->addSeconds(($token['expires_in'] ?? 3600) - 120),
                'status' => 'pending',
                'last_error' => null,
            ]);

            $accounts = ['google_ads' => [], 'ga4' => [], 'gsc' => []];
            $errors = [];
            try {
                $accounts['google_ads'] = $ads->listAccounts($integration);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
            try {
                $accounts['ga4'] = $ga4->listProperties($integration);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
            try {
                $accounts['gsc'] = $gsc->listSites($integration);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
            $integration->update(['available_accounts' => $accounts, 'last_error' => $errors ? implode(' | ', $errors) : null]);
        } catch (Throwable $e) {
            return redirect()->route($back, $client)->with('error', $e->getMessage());
        }

        return redirect()->route($back, $client)->with('success', $back === 'clients.seo'
            ? 'Połączono z Google – wybierz witrynę z Search Console.'
            : 'Połączono z Google – wybierz konto Google Ads i usługę GA4.');
    }

    // ---------------------------------------------------------------- Meta

    public function metaRedirect(Client $client, MetaConnector $meta)
    {
        abort_unless(MetaConnector::configured(), 400, 'Brak META_APP_ID / META_APP_SECRET w .env');

        return redirect()->away($meta->authUrl($this->state($client, 'meta')));
    }

    public function metaCallback(Request $request, MetaConnector $meta)
    {
        $client = $this->clientFromState($request, 'meta');
        if ($request->filled('error')) {
            return redirect()->route('clients.integrations', $client)->with('error', 'Meta: '.$request->input('error_description', $request->input('error')));
        }

        try {
            $token = $meta->exchangeCode($request->input('code'));
            $integration = $client->integrations()->updateOrCreate(['provider' => 'meta'], [
                'access_token' => $token['access_token'],
                'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : now()->addDays(60),
                'status' => 'pending',
                'last_error' => null,
            ]);
            $integration->update(['available_accounts' => ['meta_ads' => $meta->listAccounts($integration)]]);
        } catch (Throwable $e) {
            return redirect()->route('clients.integrations', $client)->with('error', $e->getMessage());
        }

        return redirect()->route('clients.integrations', $client)->with('success', 'Połączono z Meta – wybierz konto reklamowe.');
    }

    // ---------------------------------------------------------------- wybór kont

    public function update(Request $request, Client $client, string $provider)
    {
        $integration = $client->integrations()->where('provider', $provider)->firstOrFail();
        $data = $request->validate([
            'customer_id' => 'nullable|string|max:20',
            'login_customer_id' => 'nullable|string|max:20',
            'ga4_property_id' => 'nullable|string|max:20',
            'gsc_site' => 'nullable|string|max:255',
            'ad_account_id' => 'nullable|string|max:40',
        ]);
        $settings = array_filter(array_merge($integration->settings ?? [], $data), fn ($v) => $v !== null && $v !== '');
        $ready = $provider === 'google'
            ? (! empty($settings['customer_id']) || ! empty($settings['ga4_property_id']) || ! empty($settings['gsc_site']))
            : ! empty($settings['ad_account_id']);

        $integration->update(['settings' => $settings, 'status' => $ready ? 'connected' : 'pending']);
        if ($ready && ($request->has('gsc_site') && ! $request->hasAny(['customer_id', 'ga4_property_id']))) {
            SyncSearchConsoleJob::dispatch($client); // tylko Search Console – bez pełnej synchronizacji reklam
        } elseif ($ready) {
            SyncClientJob::dispatch($client);
        }

        return back()->with('success', $ready ? 'Zapisano. Pierwsza synchronizacja danych ruszyła.' : 'Zapisano.');
    }

    public function destroy(Client $client, Integration $integration)
    {
        abort_unless($integration->client_id === $client->id, 404);
        $integration->delete();

        return back()->with('success', 'Integracja odłączona.');
    }

    // ---------------------------------------------------------------- state (CSRF dla OAuth)

    private function state(Client $client, string $provider): string
    {
        $nonce = Str::random(32);
        session(["oauth_$provider" => ['nonce' => $nonce, 'client' => $client->id]]);

        return $nonce;
    }

    private function clientFromState(Request $request, string $provider): Client
    {
        $saved = session()->pull("oauth_$provider");
        abort_unless($saved && hash_equals($saved['nonce'], (string) $request->input('state')), 403, 'Nieprawidłowy parametr state OAuth.');

        return Client::findOrFail($saved['client']);
    }
}
