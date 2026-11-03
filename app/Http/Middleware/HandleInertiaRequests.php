<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'invite_link' => fn () => $request->session()->get('invite_link'),
            ],
            'brand' => fn () => \App\Models\Setting::brand(),
            // Licznik w menu „Opieka”: zadania po terminie i na dziś (aktywni klienci)
            'care_due' => fn () => $request->user() ? \App\Models\CareTask::due()->whereHas('client', fn ($q) => $q->active())->count() : 0,
            // Alerty WordPress do sprawdzenia (krytyczne i ostrzeżenia) – znaczek przy „Wszyscy klienci”
            'wp_alerts' => fn () => $request->user() ? \App\Models\WpEvent::alerts()->whereHas('client', fn ($q) => $q->active())->count() : 0,
            'vault' => fn () => ['unlocked_until' => (int) $request->session()->get('vault.unlocked_until') ?: null, 'seconds' => (int) config('adpilot.security.vault_unlock_seconds')],
            'sites_down' => fn () => $request->user() ? \App\Models\SiteMonitor::where('enabled', true)->where('status', 'down')->whereHas('client', fn ($q) => $q->active())->count() : 0,
            'admin' => fn () => $request->user()?->is_admin ? [
                'problems' => app(\App\Services\SystemStatus::class)->problemCount(),
            ] : null,
        ];
    }
}
