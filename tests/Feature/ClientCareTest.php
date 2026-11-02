<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\CareTemplate;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\CredentialLog;
use App\Models\User;
use App\Services\Care\CarePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientCareTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create(['name' => 'Firma Test', 'website_url' => 'https://firma.pl', 'currency' => 'PLN']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_preset_creates_plan_and_current_month_tasks_idempotently(): void
    {
        $client = $this->client();
        $planner = app(CarePlanner::class);

        $added = $planner->applyPreset($client, 'wordpress');
        $this->assertSame(count(CarePlanner::PRESETS['wordpress']['items']), $added);
        $monthly = $client->careTemplates()->where('frequency', 'monthly')->count();
        $this->assertGreaterThanOrEqual($monthly, $client->careTasks()->where('period', now()->format('Y-m'))->count());

        // ponowne wywołania nic nie dublują
        $this->assertSame(0, $planner->applyPreset($client, 'wordpress'));
        $before = CareTask::count();
        $planner->ensureMonth($client);
        $planner->ensureAll();
        $this->assertSame($before, CareTask::count());
    }

    public function test_frequency_months(): void
    {
        $t = new CareTemplate(['frequency' => 'quarterly', 'start_month' => 11]);
        $this->assertTrue($t->isDueIn(11));
        $this->assertTrue($t->isDueIn(2));
        $this->assertTrue($t->isDueIn(8));
        $this->assertFalse($t->isDueIn(12));
        $y = new CareTemplate(['frequency' => 'yearly', 'start_month' => 3]);
        $this->assertTrue($y->isDueIn(3));
        $this->assertFalse($y->isDueIn(4));
    }

    public function test_next_month_tasks_and_due_day_clamped(): void
    {
        $client = $this->client();
        $client->careTemplates()->create(['title' => 'Aktualizacje', 'frequency' => 'monthly', 'start_month' => 1, 'due_day' => 31]);
        $feb = CarbonImmutable::create(now()->year + 1, 2, 1);
        app(CarePlanner::class)->ensureMonth($client, $feb);
        $task = $client->careTasks()->where('period', $feb->format('Y-m'))->first();
        $this->assertSame($feb->endOfMonth()->toDateString(), substr($task->due_date, 0, 10));
    }

    public function test_task_flow_overdue_reminders_and_skip_instead_of_delete(): void
    {
        $this->actingAs($user = $this->admin());
        $client = $this->client();
        $tpl = $client->careTemplates()->create(['title' => 'Skan malware', 'frequency' => 'monthly', 'start_month' => 1, 'due_day' => 1]);
        app(CarePlanner::class)->ensureMonth($client);
        $task = $client->careTasks()->first();
        $task->update(['due_date' => now()->subDays(2)->toDateString()]);

        // przypomnienia: licznik w menu i baner w karcie klienta
        $this->get("/clients/{$client->id}")->assertInertia(fn ($p) => $p->where('care_due', 1)->where('client.care.overdue', 1));
        $this->get('/care')->assertOk()->assertInertia(fn ($p) => $p->component('care')->has('rows', 1));

        $this->patch("/care/tasks/{$task->id}", ['status' => 'done', 'note' => 'Czysto'])->assertRedirect();
        $task->refresh();
        $this->assertSame('done', $task->status);
        $this->assertSame($user->id, $task->done_by);
        $this->get("/clients/{$client->id}/care")->assertOk()->assertInertia(fn ($p) => $p->where('care_due', 0)->has('tasks', 1));

        // zadanie z planu: „usuń” = pomiń (inaczej wróciłoby przy planowaniu)
        $task->update(['status' => 'todo']);
        $this->delete("/care/tasks/{$task->id}");
        $this->assertSame('skipped', $task->fresh()->status);

        // jednorazowe – usuwa się naprawdę
        $this->post("/clients/{$client->id}/care/tasks", ['title' => 'Nowy baner', 'due_date' => now()->addDays(3)->toDateString()])->assertRedirect();
        $one = $client->careTasks()->whereNull('care_template_id')->first();
        $this->assertSame(now()->addDays(3)->format('Y-m'), $one->period);
        $this->delete("/care/tasks/{$one->id}");
        $this->assertModelMissing($one);

        // wstrzymanie zadania w planie usuwa niewykonane z bieżącego miesiąca
        $task->update(['status' => 'todo']);
        $this->patch("/care/templates/{$tpl->id}", ['active' => false]);
        $this->assertSame(0, $client->careTasks()->todo()->count());
    }

    public function test_credentials_are_encrypted_admin_only_and_require_password_confirmation(): void
    {
        $client = $this->client();

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get("/clients/{$client->id}/credentials")->assertForbidden();

        $this->actingAs($admin = $this->admin());
        $this->get("/clients/{$client->id}/credentials")->assertRedirect(route('password.confirm'));

        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->post("/clients/{$client->id}/credentials", [
            'category' => 'cms', 'label' => 'WP admin', 'url' => 'https://firma.pl/wp-admin', 'username' => 'admin',
            'secret' => 'Tajne!Haslo123', 'notes' => 'kody 2FA: 1111',
        ])->assertRedirect();
        $cred = ClientCredential::first();

        // w bazie zaszyfrowane
        $raw = DB::table('client_credentials')->first();
        $this->assertStringNotContainsString('Tajne', $raw->secret);
        $this->assertStringNotContainsString('2FA', $raw->notes);

        // lista nie zawiera hasła
        $res = $this->get("/clients/{$client->id}/credentials")->assertOk();
        $this->assertStringNotContainsString('Tajne!Haslo123', $res->getContent());
        $res->assertInertia(fn ($p) => $p->where('credentials.0.has_secret', true)->missing('credentials.0.secret'));

        // odsłonięcie – z wpisem w dzienniku
        $this->postJson("/credentials/{$cred->id}/reveal")->assertOk()->assertJson(['secret' => 'Tajne!Haslo123', 'notes' => 'kody 2FA: 1111']);
        $this->assertDatabaseHas('credential_logs', ['client_credential_id' => $cred->id, 'action' => 'view', 'user_id' => $admin->id]);

        // edycja z pustym hasłem i bez flagi notatek nie zmienia hasła ani notatek
        $this->put("/credentials/{$cred->id}", ['category' => 'cms', 'label' => 'WP – admin', 'secret' => '', 'notes' => ''])->assertRedirect();
        $cred->refresh();
        $this->assertSame('Tajne!Haslo123', $cred->secret);
        $this->assertSame('kody 2FA: 1111', $cred->notes);
        $this->assertSame('WP – admin', $cred->label);

        // bez świeżego potwierdzenia hasła odczyt jest blokowany
        $this->withSession(['auth.password_confirmed_at' => time() - 99999]);
        $this->postJson("/credentials/{$cred->id}/reveal")->assertStatus(423);

        $this->delete("/credentials/{$cred->id}")->assertRedirect();
        $this->assertSame(0, ClientCredential::count());
        $this->assertSame(1, CredentialLog::where('action', 'delete')->count());
    }

    public function test_care_only_client_flow_without_ads(): void
    {
        $this->actingAs($this->admin());

        $this->post('/clients', [
            'name' => 'Medoncr', 'website_url' => 'medoncr.com', 'admin_url' => 'medoncr.com/wp-admin',
            'contact_name' => 'Jan Kowalski', 'contact_phone' => '600 100 200', 'has_care' => true, 'has_ads' => false,
        ])->assertRedirect();
        $client = Client::where('name', 'Medoncr')->first();
        $this->assertTrue($client->has_care);
        $this->assertFalse($client->has_ads);
        $this->assertSame('https://medoncr.com/wp-admin', $client->admin_url);
        $this->assertSame('PLN', $client->currency);
        $this->assertSame(0, $client->audits()->count()); // bez reklam – bez audytu pomiaru

        // nie ma go w wynikach reklam, jest na liście klientów
        $this->get('/dashboard')->assertInertia(fn ($p) => $p->has('clients', 0));
        $this->get('/clients')->assertOk()->assertInertia(fn ($p) => $p->component('clients/index')->where('clients.0.name', 'Medoncr'));

        // karta: zadanie jednorazowe + notatki
        $this->post("/clients/{$client->id}/care/tasks", ['title' => 'Wymienić baner', 'due_date' => now()->toDateString()]);
        $this->patch("/clients/{$client->id}/notes", ['notes' => "Abonament 300 zł\nHosting: cyber_Folks"])->assertRedirect();
        $this->get("/clients/{$client->id}")->assertOk()->assertInertia(fn ($p) => $p->component('clients/card')
            ->where('client.notes', "Abonament 300 zł\nHosting: cyber_Folks")
            ->where('client.admin_url', 'https://medoncr.com/wp-admin')
            ->has('upcoming', 1)
            ->where('adsKpis', null));

        $this->get('/')->assertRedirect('/clients');
    }

    public function test_panel_login_and_password_from_client_form_go_to_encrypted_vault(): void
    {
        $this->actingAs($admin = $this->admin());

        $this->post('/clients', [
            'name' => 'Medoncr', 'website_url' => 'medoncr.com', 'admin_url' => 'medoncr.com/wp-admin',
            'panel_login' => 'adrian', 'panel_password' => 'S3kretne!', 'has_care' => true,
        ])->assertRedirect();
        $client = Client::where('name', 'Medoncr')->first();
        $cred = $client->credentials()->where('category', 'cms')->first();
        $this->assertSame('adrian', $cred->username);
        $this->assertSame('S3kretne!', $cred->secret);
        $this->assertSame('https://medoncr.com/wp-admin', $cred->url);
        $this->assertStringNotContainsString('S3kretne', DB::table('client_credentials')->value('secret'));

        // karta: login widoczny, hasło nie
        $res = $this->get("/clients/{$client->id}")->assertInertia(fn ($p) => $p->where('client.panel.username', 'adrian')->where('client.panel.has_secret', true));
        $this->assertStringNotContainsString('S3kretne!', $res->getContent());

        // edycja: formularz nie zawiera hasła; puste hasło = bez zmian
        $edit = $this->get("/clients/{$client->id}/edit")->assertInertia(fn ($p) => $p->where('panel.username', 'adrian'));
        $this->assertStringNotContainsString('S3kretne!', $edit->getContent());
        $this->put("/clients/{$client->id}", ['name' => 'Medoncr', 'website_url' => 'medoncr.com', 'admin_url' => 'medoncr.com/wp-admin', 'panel_login' => 'admin2', 'panel_password' => '', 'has_care' => true]);
        $cred->refresh();
        $this->assertSame('admin2', $cred->username);
        $this->assertSame('S3kretne!', $cred->secret);
        $this->assertSame(1, $client->credentials()->count());

        // użytkownik bez uprawnień nie zmieni hasła przez formularz klienta
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->put("/clients/{$client->id}", ['name' => 'Medoncr', 'website_url' => 'medoncr.com', 'panel_login' => 'hacker', 'panel_password' => 'x', 'has_care' => true]);
        $this->assertSame('admin2', $cred->fresh()->username);
        $this->get("/clients/{$client->id}")->assertInertia(fn ($p) => $p->where('client.panel', null));

        // odblokowanie sejfu wraca tylko na adres w panelu
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('/vault/unlock?back=/clients/'.$client->id)->assertRedirect('/clients/'.$client->id);
        $this->get('/vault/unlock?back=//evil.com')->assertRedirect('/clients');
    }
}