<?php

use App\Jobs\GenerateReportJob;
use App\Jobs\HeartbeatJob;
use App\Jobs\RunTrackingAuditJob;
use App\Jobs\SyncClientJob;
use App\Mail\DailyDigestMail;
use App\Models\Client;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\ClientSync;
use App\Services\Demo\DemoDataGenerator;
use App\Services\SystemStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
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
    app(\App\Services\Care\CarePlanner::class)->ensureAll();
    $care = \App\Models\CareTask::due(now()->addDays(3)->toDateString())->whereHas('client', fn ($q) => $q->active())
        ->with('client:id,name')->orderBy('due_date')->get()->groupBy('client_id')->map(fn ($items) => [
            'id' => $items->first()->client_id,
            'name' => $items->first()->client->name,
            'items' => $items->map(fn ($t) => ['title' => $t->title, 'due_date' => $t->due_date, 'overdue' => $t->due_date < now()->toDateString()])->values()->all(),
        ])->values()->all();

    $wp = \App\Models\WpEvent::alerts()->whereHas('client', fn ($q) => $q->active())->with('client:id,name')->latest('occurred_at')->get()
        ->groupBy('client_id')->map(fn ($items) => ['id' => $items->first()->client_id, 'name' => $items->first()->client->name,
            'items' => $items->take(6)->map(fn ($e) => ['severity' => $e->severity, 'title' => $e->title])->values()->all()])->values()->all();

    $digest = ['clients' => $clients, 'integrationProblems' => $problems, 'care' => $care, 'wp' => $wp,
        'urgent_count' => $recs->where('severity', 'critical')->count() + count($problems)
            + \App\Models\CareTask::due(now()->subDay()->toDateString())->whereHas('client', fn ($q) => $q->active())->count()];

    if (! $clients && ! $problems && ! $care && ! $wp && ! $this->option('force')) {
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
        $result = \Illuminate\Support\Facades\Process::env(['MYSQL_PWD' => (string) $cfg['password']])->run($cmd);
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

Schedule::call(fn () => app(\App\Services\Care\CarePlanner::class)->ensureAll())->dailyAt('00:15')->name('adpilot-care-plan');
// WordPress: codzienne odpytanie stron (raport + skan plików) i kontrola braku kontaktu
Schedule::call(fn () => \App\Models\WpSite::where('status', 'connected')->whereHas('client', fn ($q) => $q->active())->get()
    ->each(fn ($s) => \App\Jobs\PullWpSiteJob::dispatch($s, true)))->dailyAt('06:40')->name('adpilot-wp-pull');
Schedule::call(fn () => app(\App\Services\WordPress\WpMonitor::class)->checkOffline())->hourly()->name('adpilot-wp-offline');
Schedule::command('adpilot:digest')->dailyAt('08:00');
Schedule::command('adpilot:backup')->dailyAt('03:00');
Schedule::command('adpilot:monthly-reports')->monthlyOn(2, '07:00');
Schedule::command('queue:prune-failed --hours=336')->weekly();
