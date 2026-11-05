<?php

use App\Jobs\GenerateReportJob;
use App\Jobs\HeartbeatJob;
use App\Jobs\PullWpSiteJob;
use App\Jobs\RunSiteAuditJob;
use App\Jobs\RunTrackingAuditJob;
use App\Jobs\SyncClientJob;
use App\Jobs\SyncSearchConsoleJob;
use App\Jobs\UptimeCheckJob;
use App\Mail\DailyDigestMail;
use App\Models\CareTask;
use App\Models\Client;
use App\Models\Recommendation;
use App\Models\SiteMonitor;
use App\Models\User;
use App\Models\WpEvent;
use App\Models\WpSite;
use App\Services\Billing\BillingService;
use App\Services\Care\CarePlanner;
use App\Services\ClientSync;
use App\Services\Demo\DemoDataGenerator;
use App\Services\Monitoring\UptimeMonitor;
use App\Services\Sales\Followups;
use App\Services\SiteAudit\SiteAuditor;
use App\Services\SystemStatus;
use App\Services\WordPress\WpMonitor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('adpilot:create-admin {email} {name}', function (string $email, string $name) {
    $password = Str::password(16, symbols: false);
    $user = User::updateOrCreate(['email' => strtolower($email)], [
        'name' => $name, 'password' => $password, 'is_admin' => true, 'email_verified_at' => now(),
    ]);
    $this->info("Administrator: {$user->name} <{$user->email}>");
    $this->line("Hasło tymczasowe: <comment>{$password}</comment>");
    $this->line('Zmień je po zalogowaniu: Ustawienia → Hasło.');
})->purpose('Tworzy (lub resetuje) konto administratora z losowym hasłem');

Artisan::command('adpilot:demo', function () {
    foreach ([DemoDataGenerator::createClient(), DemoDataGenerator::createLeadsClient()] as $client) {
        app(ClientSync::class)->sync($client);
        $this->info("Utworzono: {$client->name} (#{$client->id}) – dane z 60 dni i rekomendacje.");
    }
})->purpose('Tworzy 2 klientów demo: sklep (sprzedaż) i biuro usługowe (kontakty)');

Artisan::command('adpilot:sync {client?}', function (?int $client = null) {
    $clients = $client ? Client::whereKey($client)->get() : Client::active()->get();
    foreach ($clients as $c) {
        $this->line("→ {$c->name}");
        foreach (app(ClientSync::class)->sync($c) as $line) {
            $this->line("   $line");
        }
    }
})->purpose('Synchronizuje dane i przelicza rekomendacje (wszyscy aktywni klienci lub jeden)');

Artisan::command('adpilot:monthly-reports', function () {
    $from = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
    $to = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
    foreach (Client::active()->runsAds()->where('is_demo', false)->get() as $c) {
        GenerateReportJob::dispatch($c, 'client', $from, $to, $c->auto_send_reports);
    }
    $this->info("Zlecono raporty za $from – $to.");
})->purpose('Generuje raporty miesięczne dla klientów (i wysyła, jeśli włączone)');

Artisan::command('adpilot:digest {--force : wyślij nawet gdy nie ma nic pilnego}', function (SystemStatus $status) {
    $recs = Recommendation::open()->whereIn('severity', ['critical', 'high'])
        ->whereHas('client', fn ($q) => $q->active())->with('client:id,name')->bySeverity()->get();

    $clients = $recs->groupBy('client_id')->map(fn ($items) => [
        'name' => $items->first()->client->name,
        'items' => $items->take(6)->map->only(['severity', 'title'])->values()->all(),
    ])->values()->all();

    $problems = [];
    foreach ($status->integrations() as $c) {
        foreach ($c['providers'] as $p) {
            if (in_array($p['status'], ['fail', 'warn'], true)) {
                $problems[] = ['client' => $c['name'], 'provider' => $p['label'], 'details' => Str::limit($p['details'], 160)];
            }
        }
    }

    // Opieka: zaległe, na dziś i w ciągu 3 dni
    app(CarePlanner::class)->ensureAll();
    $care = CareTask::due(now()->addDays(3)->toDateString())->whereHas('client', fn ($q) => $q->active())
        ->with('client:id,name')->orderBy('due_date')->get()->groupBy('client_id')->map(fn ($items) => [
            'id' => $items->first()->client_id,
            'name' => $items->first()->client->name,
            'items' => $items->map(fn ($t) => ['title' => $t->title, 'due_date' => $t->due_date, 'overdue' => $t->due_date < now()->toDateString()])->values()->all(),
        ])->values()->all();

    $wp = WpEvent::alerts()->whereHas('client', fn ($q) => $q->active())->with('client:id,name')->latest('occurred_at')->get()
        ->groupBy('client_id')->map(fn ($items) => ['id' => $items->first()->client_id, 'name' => $items->first()->client->name,
            'items' => $items->take(6)->map(fn ($e) => ['severity' => $e->severity, 'title' => $e->title])->values()->all()])->values()->all();

    $sites = SiteMonitor::where('enabled', true)->whereHas('client', fn ($q) => $q->active())->with('client:id,name')->get()
        ->filter(fn ($m) => $m->status === 'down' || (($d = $m->sslDaysLeft()) !== null && $d <= 14))
        ->map(fn ($m) => ['id' => $m->client_id, 'name' => $m->client->name, 'text' => $m->status === 'down' ? 'strona nie działa od '.$m->down_since?->format('d.m H:i') : 'certyfikat SSL wygasa za '.$m->sslDaysLeft().' dni'])->values()->all();

    $followups = app(Followups::class)->due()->map(fn ($f) => Arr::only($f, ['who', 'title', 'since']))->all();

    $digest = ['clients' => $clients, 'integrationProblems' => $problems, 'care' => $care, 'wp' => $wp, 'sites' => $sites, 'followups' => $followups,
        'urgent_count' => $recs->where('severity', 'critical')->count() + count($problems)
            + CareTask::due(now()->subDay()->toDateString())->whereHas('client', fn ($q) => $q->active())->count()];

    if (! $clients && ! $problems && ! $care && ! $wp && ! $sites && ! $followups && ! $this->option('force')) {
        $this->info('Nic pilnego – nie wysyłam.');

        return;
    }
    $to = User::where('daily_digest', true)->pluck('email');
    foreach ($to as $email) {
        Mail::to($email)->send(new DailyDigestMail($digest));
    }
    $this->info('Wysłano do: '.$to->implode(', '));
})->purpose('Poranny e-mail z pilnymi zadaniami');

Artisan::command('adpilot:backup {--keep=14}', function () {
    $dir = storage_path('app/backups');
    File::ensureDirectoryExists($dir);
    $conn = config('database.default');
    $cfg = config("database.connections.$conn");
    $file = $dir.'/adpilot-'.now()->format('Y-m-d_His');

    if ($cfg['driver'] === 'sqlite') {
        File::copy($cfg['database'], $file .= '.sqlite');
    } else {
        $file .= '.sql.gz';
        $cmd = sprintf('mysqldump --single-transaction --quick --no-tablespaces -h %s -P %s -u %s %s | gzip > %s',
            escapeshellarg($cfg['host']), escapeshellarg((string) $cfg['port']), escapeshellarg($cfg['username']),
            escapeshellarg($cfg['database']), escapeshellarg($file));
        $result = Process::env(['MYSQL_PWD' => (string) $cfg['password']])->run($cmd);
        if ($result->failed() || ! filesize($file)) {
            $this->error('Kopia nie powiodła się: '.$result->errorOutput());

            return 1;
        }
    }
    // usuń najstarsze
    collect(File::glob("$dir/adpilot-*"))->sort()->reverse()->slice((int) $this->option('keep'))->each(fn ($f) => File::delete($f));
    $this->info('Kopia: '.basename($file).' ('.round(filesize($file) / 1024).' KB)');
})->purpose('Kopia zapasowa bazy (trzyma 14 ostatnich)');

// ------------------------------------------------------------------ Harmonogram
// Na VPS: kontener "scheduler" (schedule:work) albo cron "* * * * * php artisan schedule:run"

Schedule::call(fn () => SystemStatus::beat(SystemStatus::HEARTBEAT_KEY))->everyMinute()->name('adpilot-heartbeat')->evenInMaintenanceMode();
Schedule::job(new HeartbeatJob)->everyFiveMinutes()->name('adpilot-worker-heartbeat');

Schedule::call(fn () => Client::active()->runsAds()->get()->each(fn ($c) => SyncClientJob::dispatch($c)))
    ->dailyAt('05:30')->name('adpilot-daily-sync');

// Na bieżąco: co godzinę wczoraj + dziś (Meta odświeża dane co ~15–30 min, Google Ads z opóźnieniem do ~3 h)
Schedule::call(fn () => Client::active()->runsAds()->where('is_demo', false)
    ->whereHas('integrations', fn ($q) => $q->where('status', 'connected'))->get()
    ->each(fn ($c) => SyncClientJob::dispatch($c, true)))
    ->hourlyAt(15)->between('6:00', '23:30')->when(fn () => config('adpilot.live_sync'))->name('adpilot-live-sync');

Schedule::call(function () {
    Client::active()->runsAds()->where('is_demo', false)->get()
        ->each(fn ($c) => RunTrackingAuditJob::dispatch($c->audits()->create(['url' => $c->website_url])));
})->weeklyOn(1, '04:00')->name('adpilot-weekly-audit');

Schedule::call(fn () => app(CarePlanner::class)->ensureAll())->dailyAt('00:15')->name('adpilot-care-plan');
// WordPress: codzienne odpytanie stron (raport + skan plików) i kontrola braku kontaktu
Schedule::call(fn () => WpSite::where('status', 'connected')->whereHas('client', fn ($q) => $q->active())->get()
    ->each(fn ($s) => PullWpSiteJob::dispatch($s, true)))->dailyAt('06:40')->name('adpilot-wp-pull');
Schedule::call(fn () => app(WpMonitor::class)->checkOffline())->hourly()->name('adpilot-wp-offline');
// Dostępność stron co 5 min, certyfikaty SSL raz dziennie, audyt stron z opieką raz w miesiącu
Schedule::job(new UptimeCheckJob)->everyFiveMinutes()->name('adpilot-uptime');
Schedule::call(fn () => SiteMonitor::where('enabled', true)->whereHas('client', fn ($q) => $q->active())->get()
    ->each(fn ($m) => app(UptimeMonitor::class)->checkSsl($m)))->dailyAt('07:10')->name('adpilot-ssl');
Schedule::call(fn () => Client::active()->where('is_demo', false)->where('has_care', true)->get()
    ->each(fn ($c) => RunSiteAuditJob::dispatch(app(SiteAuditor::class)->start($c))))->monthlyOn(1, '03:30')->name('adpilot-site-audits');
// Rozliczenia: przypomnienia o fakturach i odnowieniach (zadania u klienta + e-mail)
Schedule::call(fn () => app(BillingService::class)->remind())->dailyAt('07:20')->name('adpilot-billing');
// Search Console: klienci bez reklam (klienci z reklamami synchronizują się razem z kampaniami o 05:30)
Schedule::call(fn () => Client::active()->where('has_ads', false)->where('is_demo', false)
    ->whereHas('integrations', fn ($q) => $q->where('provider', 'google'))->with('integrations')->get()
    ->filter(fn ($c) => $c->integration('google')?->setting('gsc_site'))
    ->each(fn ($c) => SyncSearchConsoleJob::dispatch($c)))->dailyAt('06:10')->name('adpilot-gsc');
Schedule::command('adpilot:digest')->dailyAt('08:00');
Schedule::command('adpilot:backup')->dailyAt('03:00');
Schedule::command('adpilot:monthly-reports')->monthlyOn(2, '07:00');
Schedule::command('queue:prune-failed --hours=336')->weekly();
