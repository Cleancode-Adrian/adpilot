<?php

namespace App\Http\Middleware;

use App\Models\CareTask;
use App\Models\Setting;
use App\Models\SiteMonitor;
use App\Models\WpEvent;
use App\Services\SystemStatus;
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
            'brand' => fn () => Setting::brand(),
            // stawka VAT do pokazywania kwot brutto obok netto (null = zwolniony z VAT)
            'vat_rate' => fn () => Setting::vatRate(),
            // Licznik w menu „Opieka”: zadania po terminie i na dziś (aktywni klienci)
            'care_due' => fn () => $request->user() ? CareTask::due()->whereHas('client', fn ($q) => $q->active())->count() : 0,
            // Alerty WordPress do sprawdzenia (krytyczne i ostrzeżenia) – znaczek przy „Wszyscy klienci”
            'wp_alerts' => fn () => $request->user() ? WpEvent::alerts()->whereHas('client', fn ($q) => $q->active())->count() : 0,
            'vault' => fn () => ['unlocked_until' => (int) $request->session()->get('vault.unlocked_until') ?: null, 'seconds' => (int) config('adpilot.security.vault_unlock_seconds')],
            'sites_down' => fn () => $request->user() ? SiteMonitor::where('enabled', true)->where('status', 'down')->whereHas('client', fn ($q) => $q->active())->count() : 0,
            'admin' => fn () => $request->user()?->is_admin ? [
                'problems' => app(SystemStatus::class)->problemCount(),
            ] : null,
        ];
    }
}
