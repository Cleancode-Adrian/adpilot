<?php

namespace Tests\Feature;

use App\Mail\ClientAlertMail;
use App\Models\AuditBatch;
use App\Models\AuditBatchItem;
use App\Models\User;
use App\Models\WebAudit;
use App\Services\Sales\Followups;
use App\Services\WebAudit\BatchListParser;
use App\Services\WebAudit\PageInspector;
use App\Services\WebAudit\SecurityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Audyt zbiorczy: lista stron (wklejona / z arkusza Google) → audyt każdej strony po kolei → wspólne zestawienie, raport i arkusz. */
class AuditBatchTest extends TestCase
{
    use RefreshDatabase;

    /** strona gamma-test.pl: najpierw błąd 500, potem „wraca do życia” */
    private bool $gammaUp = false;

    private function parse(string $input): array
    {
        return app(BatchListParser::class)->parse($input);
    }

    public function test_parser_groups_pasted_addresses_by_site(): void
    {
        Http::fake();
        $r = $this->parse(<<<'TXT'
        1. Gsw.com.pl
        https://gsw.com.pl/oferta/prawo-pracy/
        https://gsw.com.pl/publikacje/sukcesja/
        https://www.belaw.pl/specjalizacje/prawo-transportowe/ oraz Belaw.pl
        https://madejski.com.pl/pl,categoryView,hydraulika_wysokiego_cisnienia,10,0,1.html
        Gpi-tanks.com/pl
        https://gpi-tanks.com/pl/zbiorniki-magazynowe/
        Plucinski.pl – czekamy za nową stroną
        3pietro.eu
        Kontakt m.in. przez biuro@agencja.pl, szczegóły w pliku raport.pdf, ul.Polna 3
        https://stawarz.technology/polerowanie-aluminium/ i Stawarz.technology
        TXT);
        $items = collect($r['items'])->keyBy('label');

        $this->assertSame(['gsw.com.pl', 'belaw.pl', 'madejski.com.pl', 'gpi-tanks.com/pl', 'plucinski.pl', '3pietro.eu', 'stawarz.technology'], $items->keys()->all());
        $this->assertSame('https://gsw.com.pl', $items['gsw.com.pl']['url']);
        $this->assertSame(['https://gsw.com.pl/oferta/prawo-pracy/', 'https://gsw.com.pl/publikacje/sukcesja/'], $items['gsw.com.pl']['pages']);
        $this->assertSame('https://www.belaw.pl', $items['belaw.pl']['url'], 'postać domeny (www) z pełnego adresu');
        $this->assertSame(['https://madejski.com.pl/pl,categoryView,hydraulika_wysokiego_cisnienia,10,0,1.html'], $items['madejski.com.pl']['pages'], 'przecinki w adresie zostają');
        $this->assertSame('https://gpi-tanks.com/pl/', $items['gpi-tanks.com/pl']['url'], 'wersja językowa jako strona główna');
        $this->assertSame(['https://gpi-tanks.com/pl/zbiorniki-magazynowe/'], $items['gpi-tanks.com/pl']['pages']);
        $this->assertFalse($items['plucinski.pl']['enabled'], 'sama domena z dopiskiem – do decyzji');
        $this->assertSame('czekamy za nową stroną', $items['plucinski.pl']['note']);
        $this->assertTrue($items['3pietro.eu']['enabled']);
        $this->assertSame([], $items['3pietro.eu']['pages']);
        $this->assertSame(['https://stawarz.technology/polerowanie-aluminium/'], $items['stawarz.technology']['pages'], 'rzadka końcówka domeny znana z pełnego adresu');
        $this->assertNull($r['source']);
    }

    public function test_parser_reads_google_sheet_and_cells_copied_from_it(): void
    {
        $csv = ",,,URL SG,Najważniejsze podstrony\n"
            .",,1,Byd.cichy-zasada.pl,czekamy za nową stroną \n"
            .",,2,Kabdetailing.pl,\"https://kabdetailing.pl/powloki-ochronne/\nhttps://kabdetailing.pl/cennik/\"\n"
            .",,3,InstytutKorepetycji,\"https://www.instytutkorepetycji.pl/korepetycje-matematyka/\"\n";
        Http::fake(function (Request $r) use ($csv) {
            return match ($r->url()) {
                'https://docs.google.com/spreadsheets/d/ABC_123-x/export?format=csv&gid=7' => Http::response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8']),
                'https://docs.google.com/spreadsheets/d/PRIVATE/export?format=csv' => Http::response('<html>Zaloguj się</html>', 200, ['Content-Type' => 'text/html']),
                default => Http::response('', 404),
            };
        });

        $r = $this->parse('https://docs.google.com/spreadsheets/d/ABC_123-x/edit?pli=1&gid=7#gid=7');
        $items = collect($r['items'])->keyBy('label');
        $this->assertSame(['byd.cichy-zasada.pl', 'kabdetailing.pl', 'instytutkorepetycji.pl'], $items->keys()->all());
        $this->assertFalse($items['byd.cichy-zasada.pl']['enabled']);
        $this->assertCount(2, $items['kabdetailing.pl']['pages']);
        $this->assertSame('https://www.instytutkorepetycji.pl', $items['instytutkorepetycji.pl']['url']);
        $this->assertTrue($items['instytutkorepetycji.pl']['enabled']);
        $this->assertStringStartsWith('https://docs.google.com/spreadsheets/d/ABC_123-x/', $r['source']);

        // komórki skopiowane z arkusza: tabulatory i cudzysłowy wokół komórki z kilkoma liniami
        $tsv = "1\tKabdetailing.pl\t\"https://kabdetailing.pl/powloki-ochronne/\nhttps://kabdetailing.pl/cennik/\"\n2\tNomoview.pl\thttps://nomoview.pl/okna-aluminiowe/";
        $pasted = collect($this->parse($tsv)['items'])->keyBy('label');
        $this->assertCount(2, $pasted['kabdetailing.pl']['pages']);
        $this->assertSame(['https://nomoview.pl/okna-aluminiowe/'], $pasted['nomoview.pl']['pages']);

        // nieudostępniony arkusz – zrozumiały komunikat zamiast pustej listy
        try {
            $this->parse('https://docs.google.com/spreadsheets/d/PRIVATE/edit');
            $this->fail('Brak wyjątku');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Każda osoba mająca link', $e->errors()['list'][0]);
        }

        // limit wskazanych podstron na stronę
        $many = implode("\n", array_map(fn ($n) => "https://duzo.pl/strona-$n/", range(1, 9)));
        $item = $this->parse($many)['items'][0];
        $this->assertCount(PageInspector::KEY_PAGES, $item['pages']);
        $this->assertStringContainsString('pominięto 3', $item['note']);
    }

    private function fakeSites(): void
    {
        config(['adpilot.pagespeed.enabled' => false, 'adpilot.ai.api_key' => null]);
        $this->app->bind(SecurityScanner::class, fn () => new class extends SecurityScanner
        {
            protected function fetchCertificate(string $host): ?array
            {
                return ['validTo_time_t' => now()->addDays(60)->timestamp];
            }
        });
        $page = fn (string $title, string $body) => '<!doctype html><html lang="pl"><head><title>'.$title.'</title><meta name="viewport" content="width=device-width">'
            .'<meta name="description" content="Opis strony"></head><body><header><nav><a href="/kontakt/">Kontakt</a></nav></header><main>'.$body.'</main>'
            .'<footer>Firma sp. z o.o., NIP 525-000-11-22</footer></body></html>';
        $html = fn ($b) => Http::response($b, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        Http::fake(function (Request $r) use ($page, $html) {
            $u = rtrim($r->url(), '/');

            return match (true) {
                $u === 'https://alfa-test.pl' => $html($page('Alfa – kancelaria', '<h1>Kancelaria Alfa</h1><p>'.str_repeat('Pomagamy firmom. ', 40).'</p><a href="/oferta/prawo-pracy/">Prawo pracy</a>')),
                $u === 'https://alfa-test.pl/oferta/prawo-pracy' => $html($page('Prawo pracy – Alfa', '<h1>Prawo pracy</h1><h4>Zakres</h4><p>'.str_repeat('Doradzamy pracodawcom. ', 30).'</p>')),
                $u === 'https://alfa-test.pl/blog/artykul' => $html($page('Jak zwolnić pracownika – Alfa', '<h1>Jak zwolnić pracownika</h1><p>'.str_repeat('Poradnik. ', 60).'</p>')),
                $u === 'https://alfa-test.pl/kontakt' => $html($page('Kontakt – Alfa', '<h1>Kontakt</h1><p>ul. Polna 1, 00-001 Warszawa, tel. 500 600 700</p>')),
                $u === 'https://beta-test.pl' => $html($page('Beta – okna', '<h1>Okna Beta</h1><p>'.str_repeat('Produkujemy okna. ', 40).'</p>')),
                $u === 'https://beta-test.pl/okna-aluminiowe' => $html($page('Okna aluminiowe – Beta', '<h1>Okna aluminiowe</h1><p>'.str_repeat('Ciepłe i trwałe. ', 40).'</p>')),
                str_starts_with($u, 'https://gamma-test.pl') && ! $this->gammaUp => Http::response('Błąd serwera', 500),
                $u === 'https://gamma-test.pl' => $html($page('Gamma – transport', '<h1>Gamma</h1><p>'.str_repeat('Przewozimy towary. ', 40).'</p>')),
                default => Http::response('', 404),
            };
        });
    }

    public function test_batch_flow_from_list_to_combined_report(): void
    {
        $this->fakeSites();
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        // 1. wklejona lista → podgląd (nic jeszcze nie rusza)
        $this->post('/audit-batches', ['name' => 'Serwisy agencji', 'list' => implode("\n", [
            'Alfa-test.pl', 'https://alfa-test.pl/oferta/prawo-pracy/', 'https://alfa-test.pl/blog/artykul/',
            'https://beta-test.pl/okna-aluminiowe/',
            'gamma-test.pl',
            'delta-test.pl czekamy na nową stronę',
        ])])->assertRedirect();
        $batch = AuditBatch::first();
        $this->assertSame('draft', $batch->status);
        $this->assertSame(['alfa-test.pl', 'beta-test.pl', 'gamma-test.pl', 'delta-test.pl'], $batch->items->pluck('label')->all());
        $this->assertSame(0, WebAudit::count());
        $this->get("/audit-batches/{$batch->id}")->assertInertia(fn (Assert $p) => $p->component('audit-batches/show')
            ->where('batch.status', 'draft')->where('progress.total', 3)->where('progress.skipped', 1)->has('items', 4)
            ->where('items.3.enabled', false)->where('items.3.note', 'czekamy na nową stronę'));
        $this->get('/audit-batches')->assertInertia(fn (Assert $p) => $p->component('audit-batches/index')->has('batches', 1));

        // odznaczenie i ponowne zaznaczenie strony
        $gamma = $batch->items->firstWhere('label', 'gamma-test.pl');
        $this->patch("/audit-batches/{$batch->id}/items/{$gamma->id}", ['enabled' => false])->assertRedirect();
        $this->assertFalse($gamma->fresh()->enabled);
        $this->patch("/audit-batches/{$batch->id}", ['enabled' => true])->assertRedirect();
        $this->assertSame(4, $batch->items()->where('enabled', true)->count());
        $delta = $batch->items->firstWhere('label', 'delta-test.pl');
        $this->patch("/audit-batches/{$batch->id}/items/{$delta->id}", ['enabled' => false]);

        // 2. start – kolejka w testach działa od razu, więc strony przechodzą po kolei do końca
        $this->post("/audit-batches/{$batch->id}/start", ['ai' => false])->assertRedirect();
        $batch->refresh();
        $this->assertSame('done', $batch->status);
        $this->assertNotNull($batch->finished_at);
        $this->assertSame(3, WebAudit::where('audit_batch_id', $batch->id)->count());
        $this->assertSame(0, WebAudit::where('followup_off', false)->count(), 'strony z listy nie trafiają do przypomnień o kontakcie');
        $this->assertCount(0, app(Followups::class)->due());

        $alfa = $batch->items()->where('label', 'alfa-test.pl')->first();
        $this->assertSame('done', $alfa->summary['status'], (string) $alfa->audit->error);
        $this->assertFalse($alfa->audit->options['ai']);
        $pages = collect($alfa->audit->report['pages'])->pluck('label', 'role');
        $this->assertSame(['home', 'key1', 'key2', 'contact'], $pages->keys()->all(), 'wskazane podstrony zamiast dobranych automatycznie + kontakt');
        $this->assertSame('Prawo pracy', $pages['key1']);
        $this->assertSame('Jak zwolnić pracownika', $pages['key2']);
        $this->assertContains('key1', array_column($alfa->audit->report['sections'], 'key'));
        $this->assertGreaterThan(0, count($alfa->summary['findings']));

        $failed = $batch->items()->where('label', 'gamma-test.pl')->first();
        $this->assertSame('failed', $failed->summary['status']);
        $this->assertNull($batch->items()->where('label', 'delta-test.pl')->first()->web_audit_id, 'odznaczona strona nie jest audytowana');

        // 3. zestawienie w panelu: postęp, statystyki, wspólne problemy
        $this->get("/audit-batches/{$batch->id}")->assertInertia(fn (Assert $p) => $p
            ->where('batch.status', 'done')->where('progress.done', 2)->where('progress.failed', 1)->where('progress.skipped', 1)
            ->where('stats.sites', 2)->where('items.0.state', 'done')->where('items.2.state', 'failed')
            ->where('common', fn ($c) => collect($c)->contains(fn ($x) => $x['count'] === 2 && $x['sites'] == ['alfa-test.pl', 'beta-test.pl'])));
        // pojedyncze audyty z listy nie zaśmiecają zakładki „Audyt strony”
        $this->get('/audits')->assertInertia(fn (Assert $p) => $p->has('audits', 0));
        $this->get("/audits/{$alfa->web_audit_id}")->assertInertia(fn (Assert $p) => $p->where('batch.id', $batch->id));

        // 4. arkusz wszystkich uwag i raport zbiorczy pod tajnym linkiem
        $csv = $this->get("/audit-batches/{$batch->id}/export")->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Strona;Adres;Ocena', $csv);
        $this->assertStringContainsString('alfa-test.pl', $csv);
        $this->assertStringContainsString('https://alfa-test.pl/oferta/prawo-pracy/', $csv);
        $this->assertStringContainsString('Audyt nieudany', $csv);

        $token = $batch->getRawOriginal('token');
        auth()->logout();
        $this->get("/audyt-zbiorczy/{$token}")->assertOk()
            ->assertSee('Serwisy agencji – audyt zbiorczy')->assertSee('Najczęstsze problemy')->assertSee('alfa-test.pl')->assertSee('beta-test.pl')
            ->assertSee('audyt nieudany')->assertSee('noindex', false);
        $this->get("/audyt-zbiorczy/{$token}/csv")->assertOk();
        $this->get('/audyt-zbiorczy/zly-token')->assertNotFound();
        $this->get("/audit-batches/{$batch->id}")->assertRedirect('/login');
        $this->actingAs($user);

        // 5. ponowienie nieudanych: strona wróciła do życia
        $this->assertStringContainsString('błąd HTTP 500', $failed->audit->error);
        $this->gammaUp = true;
        $this->post("/audit-batches/{$batch->id}/retry-failed")->assertRedirect();
        $batch->refresh();
        $this->assertSame('done', $batch->status);
        $this->assertSame('done', $batch->items()->where('label', 'gamma-test.pl')->first()->summary['status']);
        $this->assertSame(3, WebAudit::where('audit_batch_id', $batch->id)->count(), 'nieudany audyt zastąpiony nowym');

        // 6. „Ponów” w pojedynczym audycie podmienia wynik w zestawieniu
        $old = $alfa->web_audit_id;
        $this->post("/audits/{$old}/rerun")->assertRedirect();
        $alfa->refresh();
        $this->assertNotSame($old, $alfa->web_audit_id);
        $this->assertSame('done', $alfa->summary['status']);
        $this->assertSame($batch->id, $alfa->audit->audit_batch_id);

        // 7. skrócony PDF całej listy
        $this->get("/audyt-zbiorczy/{$token}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // 8. ponowny audyt całej listy = nowy audyt zbiorczy z porównaniem; e-mail po zakończeniu
        $this->gammaUp = false; // gamma znów nie działa – nie wchodzi do porównania
        Mail::fake();
        $this->post("/audit-batches/{$batch->id}/rerun")->assertRedirect();
        $again = AuditBatch::latest('id')->first();
        $this->assertSame($batch->id, $again->previous_id);
        $this->assertSame('done', $again->status);
        $this->assertStringEndsWith('– ponownie '.now()->format('d.m.Y'), $again->name);
        $this->assertSame(4, $again->items()->count());
        $this->assertNull($again->items()->where('label', 'delta-test.pl')->first()->web_audit_id, 'odznaczone strony zostają odznaczone');
        Mail::assertSent(ClientAlertMail::class, fn (ClientAlertMail $m) => $m->hasTo($user->email) && str_contains($m->title, 'Audyt zbiorczy gotowy') && str_contains(implode(' ', $m->lines), 'Nieudane: 1'));
        $this->assertNotNull($again->notified_at);
        $this->get("/audit-batches/{$again->id}")->assertInertia(fn (Assert $p) => $p
            ->where('comparison.previous.id', $batch->id)->where('comparison.sites_compared', 2)
            ->where('comparison.fixed_total', 0)->where('comparison.new_total', 0)
            ->where('comparison.sites', fn ($s) => collect($s)->get('alfa-test.pl')['before'] === $alfa->summary['score']));
        $this->delete("/audit-batches/{$again->id}")->assertRedirect('/audit-batches');

        // 9. usunięcie listy usuwa też audyty jej stron
        $this->delete("/audit-batches/{$batch->id}")->assertRedirect('/audit-batches');
        $this->assertSame(0, AuditBatch::count());
        $this->assertSame(0, AuditBatchItem::count());
        $this->assertSame(0, WebAudit::whereNotNull('audit_batch_id')->count());
    }

    public function test_pause_resume_and_stale_audit_recovery(): void
    {
        $this->fakeSites();
        $this->actingAs(User::factory()->create());
        $batch = AuditBatch::create(['name' => 'Lista', 'token' => 'tok', 'options' => ['ai' => false], 'status' => 'paused']);
        foreach (['alfa-test.pl', 'beta-test.pl'] as $n => $host) {
            $batch->items()->create(['position' => $n + 1, 'label' => $host, 'url' => "https://$host", 'pages' => []]);
        }

        // wstrzymana lista nie rusza
        $batch->advance();
        $this->assertSame(0, WebAudit::count());

        // audyt, który „utknął” (np. restart serwera): po czasie zostaje zamknięty, a lista idzie dalej
        $stuck = WebAudit::create(['url' => 'https://alfa-test.pl', 'token' => 'x', 'audit_batch_id' => $batch->id, 'status' => 'running']);
        $batch->items()->first()->update(['web_audit_id' => $stuck->id]);
        $batch->update(['status' => 'running']);
        $batch->advance();
        $this->assertSame(1, WebAudit::count(), 'trwający audyt blokuje kolejne (strony po kolei)');

        $this->travel(30)->minutes();
        $batch->advance();
        $batch->refresh();
        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('done', $batch->items()->where('label', 'beta-test.pl')->first()->summary['status']);
        $this->assertSame('done', $batch->status);
        $this->assertSame(['done' => 1, 'failed' => 1], array_intersect_key($batch->progress(), ['done' => 1, 'failed' => 1]));

        $this->post("/audit-batches/{$batch->id}/pause")->assertRedirect();
        $this->assertSame('done', $batch->fresh()->status, 'zakończonej listy nie da się wstrzymać');
        $this->assertSame('ciężkie zdjęcia', AuditBatchItem::problemKey('Ciężkie zdjęcia (3 pliki powyżej 250 KB)'));
        $this->assertSame(AuditBatchItem::problemKey('Podstrona „Kontakt” nie działa'), AuditBatchItem::problemKey('Podstrona „Oferta” nie działa'));
    }
}
