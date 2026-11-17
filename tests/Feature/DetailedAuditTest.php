<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebAudit;
use App\Services\WebAudit\AiPageReviewer;
use App\Services\WebAudit\AuditReportBuilder;
use App\Services\WebAudit\BotWall;
use App\Services\WebAudit\PageInspector;
use App\Services\WebAudit\PageSpeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Szczegółowa analiza strony: wybór podstron, błędy w kodzie, mobile/desktop, przegląd treści AI, raport. */
class DetailedAuditTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSchoolSite(): void
    {
        config(['adpilot.pagespeed.enabled' => false]);
        $header = '<header class="elementor-location-header"><div class="elementor-hidden-mobile"><a href="tel:%20500 600 700">500 600 700</a></div>'
            .'<nav><ul class="menu"><li><a href="#">Korepetycje</a><ul>'.str_repeat('<li><a href="/k">Przedmiot</a></li>', 9).'</ul></li>'
            .'<li><a href="https://szkola.example/oferta/">Oferta</a></li><li><a href="https://szkola.example/kontakt/">Zapisy i kontakt</a></li></ul></nav>'
            .'<div class="elementor-hidden-mobile"><a class="elementor-button" href="tel:500600700">Umów wizytę</a></div></header>';
        $footer = '<footer><a href="/polityka/">Polityka prywatności</a> <a href="/platnosci/">Płatności i odwoływanie zajęć</a> @2025 Szkoła</footer>';
        $head = fn ($title, $ver = '4.3.5', $lang = 'pl') => '<!doctype html><html lang="'.$lang.'"><head><title>'.$title.'</title><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta property="og:site_name" content="Mój Blog"><meta name="google-site-verification" content="google-site-verification=abc123">'
            .'<link rel="stylesheet" href="https://szkola.example/wp-content/plugins/elementor/assets/css/frontend.min.css?ver='.$ver.'"></head><body>';

        $home = $head('Szkoła – korepetycje').$header
            .'<form role="search"><input type="search" placeholder="Search..."></form><span class="screen-reader-text">Skip to content</span>'
            .'<main><img src="/wp-content/uploads/hero.png" loading="lazy" alt="Uczniowie"><h1>Korepetycje online</h1><h2>Jak Wygląda Rozliczenie Zajęć?</h2><h4>Cennik</h4><h2>Czy Muszę Coś Podpisywać?</h2>'
            .'<a class="button" href="/oferta/">Read more</a><a href="https://facebook.com/szkola"><i class="fab"></i></a><a href="https://instagram.com/szkola"><i class="fab"></i></a>'
            .'<a href="http://szkola.example/product/fizyka-sprawdziany/">Fizyka – sprawdziany (Poziom Podstawowy)</a>'
            .'<a href="http://szkola.example/product/fizyka-sprawdziany/">Fizyka – sprawdziany (Poziom Rozszerzony)</a>'
            .'<span class="elementor-counter-number" data-to-value="1800">0</span>'
            .'<img src="https://szkola.example/wp-content/uploads/Zrzut-ekranu-2026-07-08.png" alt="Partner"><img src="/a.png" alt="Partner"><img src="/b.png" alt="Partner"><img src="/c.png" alt="Partner">'
            .'<ul><li>Pierwszy</li><li></li></ul><p>Napisz: <a href="mailto:kontakt@inna-firma.pl">kontakt@inna-firma.pl</a></p>'
            .'<form><input type="text" name="imie" placeholder="Imię"><input type="text" name="telefon" placeholder="Telefon"><input type="email" name="email" placeholder="E-mail">'
            .'<select name="przedmiot"><option>Matematyka</option><option>Fizyka</option></select>'
            .'<label><input type="checkbox" name="zgoda"> Akceptuję Regulamin</label><button>Wyślij</button></form></main>'
            .'<noscript><img height="1" width="1" src="https://www.facebook.com/tr?id=123&ev=PageView&noscript=1"></noscript>'.$footer.'</body></html>';
        $offer = $head('Oferta', '4.3.4', 'en-US').$header.'<main><h1>Oferta</h1><p>'.str_repeat('Zajęcia z doświadczonymi nauczycielami: Łódź, Gdańsk, Kraków, Poznań, Wrocław, Białystok. ', 2).'</p><a href="https://szkola.example/oferta/matematyka-matura/">Matematyka – matura rozszerzona</a></main>'
            .'<footer><a href="/polityka/">Polityka prywatności</a></footer></body></html>';
        $detail = $head('Matematyka – matura').$header.'<main><h1>Matematyka – matura rozszerzona</h1><p>Cena 85 zł / 60 min.</p></main>'.$footer.'</body></html>';
        $contact = $head('Kontakt').$header.'<main><h1>Zapisy i kontakt</h1><p>NIP 1234567890, REGON 123456789</p></main>'.$footer.'</body></html>';

        // Zdjęcie 3000×2000 px w PNG, 900 KB – serwer zwraca pierwsze 128 KB (Range)
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 3000, 2000)."\x08\x02\x00\x00\x00".'CRC0';
        $png .= str_repeat("\0", 131072 - strlen($png));

        Http::fake(function (Request $r) use ($home, $offer, $detail, $contact, $png) {
            $u = $r->url();
            $html = fn ($b) => Http::response($b, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

            return match (true) {
                $u === 'https://szkola.example/wp-content/uploads/hero.png' => Http::response($png, 206, ['Content-Type' => 'image/png', 'Content-Range' => 'bytes 0-131071/921600']),
                $u === 'https://szkola.example/', $u === 'https://szkola.example' => $html($home),
                $u === 'https://szkola.example/oferta/' => $html($offer),
                $u === 'https://szkola.example/oferta/matematyka-matura/' => $html($detail),
                $u === 'https://szkola.example/kontakt/' => $html($contact),
                $u === 'http://szkola.example/' => Http::response('', 301, ['Location' => 'https://szkola.example/']),
                default => Http::response('', 404),
            };
        });
    }

    public function test_inspector_picks_key_pages_and_finds_code_and_mobile_problems(): void
    {
        $this->fakeSchoolSite();
        $r = app(PageInspector::class)->inspect('https://szkola.example');

        $this->assertSame(['home', 'offer', 'detail', 'contact'], array_keys($r['pages']));
        $this->assertSame('https://szkola.example/oferta/matematyka-matura/', $r['pages']['detail']['url']);

        $titles = collect($r['findings'])->keyBy('title');
        $has = fn (string $part) => $this->assertTrue($titles->keys()->contains(fn ($t) => str_contains($t, $part)), "Brak uwagi: $part\n".$titles->keys()->implode("\n"));

        $has('Różne pozycje prowadzą pod ten sam adres');
        $this->assertSame('pilne', $titles['Różne pozycje prowadzą pod ten sam adres']['sev']);
        $has('„Mój Blog”');
        $has('Adres e-mail w innej domenie');
        $has('Linki wewnętrzne przez http://');
        $has('Błędnie zapisane linki telefoniczne');
        $has('„@2025”');
        $has('podwójny prefiks');
        $has('Piksel Facebooka');
        $has('Różne wersje plików na podstronach (elementor)');
        $has('ten sam opis „Partner”');
        $has('Grafiki wyglądające na robocze pliki');
        $has('Liczniki mają w kodzie wartość 0');
        $has('Pusty punkt na liście');
        $has('Każde Słowo Wielką Literą');
        $has('Pola formularza bez widocznych etykiet');
        $has('Pole telefonu nie włącza klawiatury numerycznej');
        $has('Zgoda w formularzu bez linku');
        $has('brak adresu firmy');
        $has('Stopka różni się');
        $has('prawdopodobnie nie ma numeru');
        $has('Pozycje menu z rozwijaną listą nie mają adresu');
        $has('Bardzo długie podmenu');
        $has('tylko dzwonią');

        // język, nagłówki, dostępność
        $has('Teksty po angielsku na polskiej stronie');
        $this->assertStringContainsString('Read more', $titles['Teksty po angielsku na polskiej stronie']['evidence']);
        $has('Teksty po angielsku ukryte w kodzie');
        $this->assertStringContainsString('Skip to content', $titles['Teksty po angielsku ukryte w kodzie']['evidence']);
        $this->assertStringContainsString('placeholder', $titles['Teksty po angielsku ukryte w kodzie']['evidence']);
        $has('lang="en-US"');
        $has('Przeskoki poziomów nagłówków');
        $this->assertStringContainsString('H2 → H4', $titles['Przeskoki poziomów nagłówków']['evidence']);
        $has('Linki bez nazwy');

        // obrazy i wydajność
        $has('Ciężkie zdjęcia');
        $has('Zdjęcia w rozdzielczości prosto z aparatu');
        $has('Zdjęcia zapisane jako PNG');
        $has('Główne zdjęcie na górze strony ładuje się z opóźnieniem');
        $has('Niedziałające obrazki');
        $this->assertSame('perf', $titles['Ciężkie zdjęcia (1 plik powyżej 250 KB)']['section']);
        $hero = $r['performance']['images'][0];
        $this->assertSame([900, 3000, 'PNG'], [$hero['kb'], $hero['width'], $hero['format']]);
        $this->assertGreaterThan(400, $hero['saving_kb']);
        $this->assertSame('Strona główna', $r['performance']['pages'][0]['label']);
        $this->assertArrayNotHasKey('raw', $r['pages']['home']);

        $this->assertSame('mobile', $titles['Pozycje menu z rozwijaną listą nie mają adresu']['section']);
        $this->assertTrue($titles['Pozycje menu z rozwijaną listą nie mają adresu']['verify']);
    }

    public function test_detailed_report_with_ai_review_and_public_view(): void
    {
        $this->fakeSchoolSite();
        config(['adpilot.ai.api_key' => 'test-key']);
        $this->app->bind(AiPageReviewer::class, fn () => new class extends AiPageReviewer
        {
            protected function request(string $system, string $prompt): ?string
            {
                // prompt zawiera treść podstron i listę uwag automatu
                if (! str_contains($prompt, 'Matematyka – matura rozszerzona') || ! str_contains($prompt, 'already_found_by_automation')) {
                    return null;
                }

                return json_encode([
                    'company' => ['name' => 'Szkoła Example', 'industry' => 'korepetycje online', 'audience' => 'rodzice uczniów'],
                    'summary' => 'Strona ma bogatą ofertę, ale mylące linki i dwie nazwy firmy.',
                    'strengths' => ['Ceny podane przy każdej ofercie'],
                    'findings' => [
                        ['page' => 'home', 'group' => 'Spójność i wiarygodność', 'severity' => 'pilne', 'title' => 'Dwie nazwy firmy na jednej stronie',
                            'description' => 'Logo mówi „Szkoła”, a e-mail to inna-firma.pl.', 'evidence' => 'kontakt@inna-firma.pl', 'fix' => 'Dodać zdanie o marce.', 'verify' => false],
                        ['page' => 'offer', 'group' => 'Oferta i ceny', 'severity' => 'drobne', 'title' => 'Literówki i styl',
                            'description' => '„Menadżera” → menedżera.', 'evidence' => '', 'fix' => 'Poprawić.', 'verify' => false],
                        ['page' => 'about', 'group' => 'X', 'severity' => 'srednie', 'title' => 'Uwaga o nieistniejącej podstronie', 'description' => 'Trafia do „Cała strona”.', 'evidence' => '', 'fix' => '', 'verify' => true],
                    ],
                    'proposals' => [['title' => 'Konfigurator „Dobierz zajęcia i sprawdź koszt”', 'what' => 'Wybór przedmiotu i celu.', 'steps' => ['Przedmiot', 'Cel', 'Wynik'],
                        'benefit' => 'Odpowiada na pytanie o koszt.', 'needs_from_client' => 'Cennik.', 'implementation' => 'Calculated Fields Form.', 'effort' => 'M']],
                    'checklist' => ['Czy kliknięcie „Fizyka – sprawdziany (Poziom Podstawowy)” otwiera właściwą kartę?'],
                ], JSON_UNESCAPED_UNICODE);
            }
        });

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/audits', ['url' => 'szkola.example', 'name' => 'Szkoła Example'])->assertRedirect();
        $audit = WebAudit::first();
        $this->assertSame('done', $audit->status, (string) $audit->error);
        $this->assertArrayNotHasKey('error', $audit->areas['tracking'], 'pomiar sprawdzony na stronie głównej');

        $r = $audit->report;
        $this->assertSame(2, $r['version']);
        $this->assertSame('korepetycje online', $r['industry']);
        $keys = array_column($r['sections'], 'key');
        $this->assertSame(['home', 'offer', 'detail', 'contact', 'site', 'code', 'perf', 'mobile'], $keys);
        $this->assertContains('przeskok z H2', array_column($r['pages'][0]['outline'], 'problem'));

        $all = collect($r['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => $g['findings']));
        $brand = $all->firstWhere('title', 'Dwie nazwy firmy na jednej stronie');
        $this->assertMatchesRegularExpression('/^1.[12]$/', $brand['id'], 'pilna uwaga AI na początku sekcji strony głównej');
        $this->assertSame('site', $all->firstWhere('title', 'Uwaga o nieistniejącej podstronie')['page']);
        $this->assertSame($all->count(), array_sum($r['counts']));
        $this->assertSame($r['counts']['pilne'], count($r['plan']['now']));
        $this->assertSame('Konfigurator „Dobierz zajęcia i sprawdź koszt”', $r['proposals'][0]['title']);
        $this->assertStringContainsString('Fizyka – sprawdziany', $r['checklist'][0]);
        $this->assertTrue($all->contains(fn ($f) => $f['group'] === 'Bezpieczeństwo'), 'uwagi z dziedzin technicznych w sekcji Kod i technika');

        $this->get("/audits/{$audit->id}")->assertOk()->assertInertia(fn ($p) => $p->component('audits/show')->where('report.version', 2)->missing('audit.report'));

        auth()->logout();
        $token = $audit->getRawOriginal('token');
        $this->get("/audyt/{$token}")->assertOk()
            ->assertSee('Szkoła Example – szczegółowa analiza strony')
            ->assertSee('Zakres i metoda')
            ->assertSee('Dwie nazwy firmy na jednej stronie')
            ->assertSee('Telefon a komputer')
            ->assertSee('Plan działań')
            ->assertSee('Propozycje rozbudowy')
            ->assertSee('Checklista do sprawdzenia na telefonie i komputerze')
            ->assertSee('do potwierdzenia')
            ->assertSee('Wydajność i szybkość')
            ->assertSee('Najcięższe obrazy')
            ->assertSee('Załącznik: struktura nagłówków');
        $this->get("/audyt/{$token}/pdf")->assertOk();
    }

    public function test_pagespeed_parse_and_report_findings(): void
    {
        $lh = [
            'categories' => [
                'performance' => ['score' => 0.38, 'auditRefs' => [['id' => 'render-blocking-resources', 'weight' => 0], ['id' => 'modern-image-formats', 'weight' => 0], ['id' => 'largest-contentful-paint', 'weight' => 25]]],
                'accessibility' => ['score' => 0.81, 'auditRefs' => [['id' => 'color-contrast', 'weight' => 7], ['id' => 'html-has-lang', 'weight' => 7]]],
                'best-practices' => ['score' => 0.92, 'auditRefs' => [['id' => 'errors-in-console', 'weight' => 1]]],
                'seo' => ['score' => 0.9, 'auditRefs' => []],
            ],
            'audits' => [
                'largest-contentful-paint' => ['score' => 0.1, 'numericValue' => 6400],
                'first-contentful-paint' => ['numericValue' => 2900],
                'speed-index' => ['numericValue' => 5100],
                'total-blocking-time' => ['numericValue' => 820],
                'cumulative-layout-shift' => ['numericValue' => 0.31],
                'server-response-time' => ['numericValue' => 1250],
                'total-byte-weight' => ['numericValue' => 4_404_019],
                'dom-size' => ['numericValue' => 2100],
                'render-blocking-resources' => ['score' => 0, 'title' => 'Wyeliminuj zasoby blokujące renderowanie', 'description' => 'Zasoby blokują pierwsze wyrenderowanie strony. [Więcej informacji](https://x)',
                    'details' => ['overallSavingsMs' => 1830, 'items' => [['url' => 'https://szkola.example/wp-includes/js/jquery/jquery.min.js', 'totalBytes' => 90000, 'wastedMs' => 900]]]],
                'modern-image-formats' => ['score' => 0.2, 'title' => 'Wyświetlaj obrazy w formatach nowej generacji', 'description' => 'WebP i AVIF.',
                    'details' => ['overallSavingsMs' => 600, 'overallSavingsBytes' => 812_000, 'items' => [['url' => 'https://szkola.example/hero.png', 'totalBytes' => 921600, 'wastedBytes' => 700000]]]],
                'color-contrast' => ['score' => 0, 'title' => 'Kolory tła i pierwszego planu mają niewystarczający współczynnik kontrastu', 'description' => 'Tekst o niskim kontraście jest trudny do odczytania.',
                    'details' => ['items' => [['node' => ['nodeLabel' => 'Zapisz się']], ['node' => ['nodeLabel' => 'Cennik']], ['node' => ['nodeLabel' => 'Stopka']]]]],
                'html-has-lang' => ['score' => 1],
                'errors-in-console' => ['score' => 0, 'title' => 'W konsoli zostały zarejestrowane błędy przeglądarki', 'description' => 'Błędy w konsoli.', 'details' => ['items' => [['description' => 'Uncaught TypeError']]]],
            ],
        ];
        $ps = PageSpeed::parse($lh);
        $this->assertSame([38, 81, 92, 90], [$ps['performance'], $ps['accessibility'], $ps['best_practices'], $ps['seo']]);
        $this->assertSame([6.4, 2.9, 820, 0.31, 1250, 4301], [$ps['lcp_s'], $ps['fcp_s'], $ps['tbt_ms'], $ps['cls'], $ps['ttfb_ms'], $ps['total_kb']]);
        $this->assertSame(['render-blocking-resources', 'modern-image-formats'], array_column($ps['opportunities'], 'id'));
        $this->assertSame('Zasoby blokują pierwsze wyrenderowanie strony.', $ps['opportunities'][0]['description'], 'bez linku „Więcej informacji”');
        $this->assertSame(793, $ps['opportunities'][1]['savings_kb']);
        $this->assertSame('color-contrast', $ps['accessibility_fails'][0]['id']);
        $this->assertSame(3, $ps['accessibility_fails'][0]['count']);

        $audit = new WebAudit(['url' => 'https://szkola.example', 'name' => 'Szkoła']);
        $report = app(AuditReportBuilder::class)->build($audit, ['speed' => ['score' => 38, 'pagespeed' => $ps, 'desktop' => ['performance' => 74] + $ps]], null, null);
        $all = collect($report['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => $g['findings']));
        $perf = collect($report['sections'])->firstWhere('key', 'perf');
        $this->assertSame(['Wyniki Google PageSpeed', 'Możliwości przyspieszenia (Google PageSpeed)'], array_column($perf['groups'], 'title'));
        $this->assertSame('pilne', $all->firstWhere('title', 'Strona ładuje się wolno na telefonie')['sev']);
        $this->assertStringContainsString('na komputerze: 74/100', $all->firstWhere('title', 'Strona ładuje się wolno na telefonie')['text']);
        $this->assertSame('srednie', $all->firstWhere('title', 'Wyeliminuj zasoby blokujące renderowanie')['sev']);
        $this->assertStringContainsString('jquery.min.js', $all->firstWhere('title', 'Wyeliminuj zasoby blokujące renderowanie')['evidence']);
        $this->assertSame('srednie', $all->firstWhere('title', 'Kolory tła i pierwszego planu mają niewystarczający współczynnik kontrastu')['sev']);
        $this->assertNotNull($all->first(fn ($f) => str_starts_with($f['title'], 'Treść „skacze”')));
        $this->assertSame(74, $report['performance']['desktop']['performance']);
    }

    /** Hosting z limitem zapytań: po kilku pobraniach pokazuje ekran weryfikacji – nie wolno go oceniać jak treści. */
    public function test_verification_screen_is_not_reported_as_site_content(): void
    {
        Sleep::fake();
        $wall = '<!doctype html><html><head><title>One moment, please...</title></head><body><h1>Please wait while your request is being verified...</h1><script>/* challenge */</script></body></html>';
        $this->assertSame('zabezpieczenie hostingu', BotWall::detect($wall));
        $this->assertNull(BotWall::detect('<html><title>Firma</title><body>'.str_repeat('Treść strony. ', 4000).'One moment, please...</body></html>'), 'duża zwykła strona to treść');

        // Zabezpieczenie: 'all' – blokuje od pierwszego zapytania, liczba – blokuje szkołę po tylu zapytaniach
        $block = 'all';
        $count = 0;
        Http::fake(function (Request $r) use (&$block, &$count, $wall) {
            $blocked = $block === 'all' || (str_contains($r->url(), 'szkola.example') && ++$count > $block);

            return $blocked ? Http::response($wall, 200, ['Content-Type' => 'text/html']) : null; // null = dalej obsługuje fałszywa strona szkoły
        });
        $this->fakeSchoolSite();

        // a) blokada od pierwszego zapytania – audyt kończy się czytelnym komunikatem, bez fałszywych uwag
        config(['adpilot.pagespeed.enabled' => false]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/audits', ['url' => 'blokada.example']);
        $audit = WebAudit::first();
        $this->assertSame('failed', $audit->status);
        $this->assertStringContainsString('ekran weryfikacji (zabezpieczenie hostingu)', $audit->error);
        $this->assertNull($audit->report);
        $this->assertSame('zabezpieczenie hostingu', $audit->areas['tracking']['blocked'], 'brak „braku GA4” – pomiar nieoceniony');
        Sleep::assertSlept(fn ($d) => $d->totalSeconds >= 8, 3); // podstrony: 8 s i 20 s, przejście po stronie: 8 s

        // b) blokada po kilku zapytaniach – podstrony sprawdzone, przejście po stronie przerwane, raport z adnotacją
        $block = 25;
        $this->post('/audits', ['url' => 'szkola.example', 'name' => 'Szkoła']);
        $audit = WebAudit::latest('id')->first();
        $this->assertSame('done', $audit->status, (string) $audit->error);
        $this->assertSame('zabezpieczenie hostingu', $audit->report['blocked']);
        $this->assertSame(['home', 'offer', 'detail', 'contact'], array_column($audit->report['pages'], 'role'));
        $titles = collect($audit->report['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => $g['findings']))->pluck('title');
        $this->assertFalse($titles->contains(fn ($t) => preg_match('/ekran weryfik|one moment|being verified|zabezpieczeni/iu', $t)), 'ekran weryfikacji nie jest uwagą');
        auth()->logout();
        $this->get('/audyt/'.$audit->getRawOriginal('token'))->assertOk()->assertSee('Uwaga o zabezpieczeniu serwera');
    }

    public function test_report_without_ai_has_template_proposals_and_old_audits_still_render(): void
    {
        $this->fakeSchoolSite();
        config(['adpilot.ai.api_key' => null]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->post('/audits', ['url' => 'szkola.example']);
        $audit = WebAudit::first();

        $this->assertNull($audit->report['ai_model']);
        $this->assertNotEmpty($audit->report['proposals']);
        $this->assertContains('Przyklejony pasek kontaktu na telefonie', array_column($audit->report['proposals'], 'title'));
        $this->assertStringContainsString('Sprawdziliśmy 4 podstrony', $audit->report['summary']);

        // audyt sprzed zmiany (bez raportu) – raport składa się z wyników technicznych
        $audit->update(['report' => null]);
        auth()->logout();
        $this->get('/audyt/'.$audit->getRawOriginal('token'))->assertOk()->assertSee('Kod i technika');
    }

    public function test_audit_can_be_deleted_from_the_list(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $audit = WebAudit::create(['url' => 'https://gpi-tanks.com/', 'status' => 'done', 'score' => 65, 'token' => 'tajny-token']);
        $this->get('/audits')->assertOk();

        $this->delete("/audits/{$audit->id}")->assertRedirect('/audits');
        $this->assertDatabaseMissing('web_audits', ['id' => $audit->id]);
        auth()->logout();
        $this->get('/audyt/tajny-token')->assertNotFound(); // link dla klienta przestaje działać
    }
}
