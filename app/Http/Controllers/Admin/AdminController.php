<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reports\AiReportWriter;
use App\Services\SystemStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

/** Panel administratora: status, ustawienia agencji, zespół. */
class AdminController extends Controller
{
    // ---------------------------------------------------------------- Status

    public function status(SystemStatus $status)
    {
        return Inertia::render('admin/status', [
            'checks' => $status->checks(),
            'integrations' => $status->integrations(),
        ]);
    }

    public function testMail(Request $request)
    {
        try {
            Mail::raw('To jest testowa wiadomość z panelu '.Setting::brand()['name'].'. Poczta działa poprawnie.', function ($m) use ($request) {
                $m->to($request->user()->email)->subject('Test poczty – '.Setting::brand()['name']);
            });
        } catch (Throwable $e) {
            return back()->with('error', 'Wysyłka nie powiodła się: '.$e->getMessage());
        }
        $note = in_array(config('mail.default'), ['log', 'array'], true) ? ' (MAIL_MAILER=log – wiadomość trafiła do storage/logs/laravel.log, nie do skrzynki)' : '';

        return back()->with('success', 'Wysłano testowy e-mail na '.$request->user()->email.$note);
    }

    public function testAi()
    {
        if (! config('adpilot.ai.api_key')) {
            return back()->with('error', 'Brak ANTHROPIC_API_KEY w .env.');
        }
        [$text, $model] = app(AiReportWriter::class)->ping();

        return $text
            ? back()->with('success', "AI działa ({$model}): „{$text}”")
            : back()->with('error', 'Nie udało się połączyć z AI – szczegóły w storage/logs/laravel.log.');
    }

    // ---------------------------------------------------------------- Ustawienia agencji

    public function settings()
    {
        return Inertia::render('admin/settings', ['settings' => Setting::all_(), 'brand' => Setting::brand()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'agency_name' => 'required|string|max:100',
            'agency_email' => 'nullable|email',
            'agency_phone' => 'nullable|string|max:40',
            'agency_website' => 'nullable|string|max:150',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'report_footer' => 'nullable|string|max:500',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'remove_logo' => 'boolean',
        ]);

        if ($request->hasFile('logo')) {
            $old = Setting::get('agency_logo');
            $data['agency_logo'] = $request->file('logo')->store('branding', 'public');
            $old && Storage::disk('public')->delete($old);
        } elseif ($request->boolean('remove_logo')) {
            Storage::disk('public')->delete((string) Setting::get('agency_logo'));
            $data['agency_logo'] = null;
        }
        unset($data['logo'], $data['remove_logo']);
        Setting::put($data);

        return back()->with('success', 'Zapisano ustawienia agencji.');
    }

    // ---------------------------------------------------------------- Zespół

    public function team()
    {
        return Inertia::render('admin/team', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'is_admin', 'daily_digest', 'last_login_at', 'created_at']),
        ]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'is_admin' => 'boolean',
        ]);
        $user = User::create($data + ['password' => Str::password(32), 'email_verified_at' => now()]);
        Password::sendResetLink(['email' => $user->email]);

        return back()->with('success', "Dodano {$user->name}. Zaproszenie z linkiem do ustawienia hasła wysłano na {$user->email}.");
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate(['is_admin' => 'boolean', 'daily_digest' => 'boolean']);
        if ($user->is($request->user()) && array_key_exists('is_admin', $data) && ! $data['is_admin']) {
            return back()->with('error', 'Nie możesz odebrać uprawnień administratora samemu sobie.');
        }
        $user->update($data);

        return back()->with('success', 'Zapisano.');
    }

    public function destroyUser(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'Nie możesz usunąć własnego konta tutaj.');
        $user->delete();

        return back()->with('success', 'Usunięto użytkownika.');
    }

}
