<?php

namespace Tests\Feature;

use App\Models\WebAudit;
use App\Services\WebAudit\AuditReportBuilder;
use App\Services\WebAudit\PageInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Zgodność z prawem: kody śledzące a zgoda, polityka prywatności, formularze, dane firmy, wymogi dla sklepów (zwroty, Omnibus, EAA). */
class LegalAuditTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSites(): void
    {
        config(['adpilot.pagespeed.enabled' => false]);
        $html = fn ($b, array $h = []) => Http::response($b, 200, ['Content-Type' => 'text/html; charset=UTF-8'] + $h);

        // --- sklep z problemami
        $shopHead = '<!doctype html><html lang="pl"><head><title>Sklep</title><meta name="viewport" content="width=device-width">'
            .'<script src="https://sklep.example/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js"></script>'
            .'<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234567"></script>'
            .'<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","G-ABC1234567");</script>'
            .'<script>!function(f,b,e,v){}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init","1234567890123");fbq("track","PageView");</script>'
            .'<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter"></head><body class="woocommerce-no-js">';
        $shopFooter = '<footer><a href="/regulamin/">Regulamin</a> <a href="/polityka-prywatnosci/">Polityka prywatności</a> <a href="/koszyk/">Koszyk</a>'
            .'<form><input type="email" name="email" placeholder="Twój e-mail"><button>Zapisz się</button></form>'
            .'<div id="cookie-notice" class="cn-cookie">Ta strona korzysta z plików cookies. <a href="#" class="cn-set-cookie">OK</a></div></footer></body></html>';
        $shopHome = $shopHead.'<main><h1>Buty zimowe</h1><a href="/produkt/buty-zimowe/">Buty zimowe Alpina</a> <button>Dodaj do koszyka</button>'
            .'<iframe src="https://www.youtube.com/embed/abc123" title="Film"></iframe>'
            .'<form><input type="text" name="imie" placeholder="Imię"><input type="email" name="email" placeholder="E-mail">'
            .'<label><input type="checkbox" name="zgoda" checked> Wyrażam zgodę na otrzymywanie informacji marketingowych</label><button>Wyślij</button></form></main>'.$shopFooter;
        $product = $shopHead.'<main><h1>Buty zimowe Alpina</h1><p class="price"><del aria-hidden="true"><span class="woocommerce-Price-amount amount"><bdi>199,00&nbsp;'
            .'<span class="woocommerce-Price-currencySymbol">&#122;&#322;</span></bdi></span></del> <ins>149,00 zł</ins></p><button>Dodaj do koszyka</button>'
            .'<div class="woocommerce-Reviews"><p>Świetne buty – 5/5</p></div></main>'.$shopFooter;
        $privacy = $shopHead.'<main><h1>Polityka prywatności</h1><p>Administratorem danych jest Sklep sp. z o.o.</p>'
            .'<p>Dane przetwarzamy zgodnie z ustawą z dnia 29 sierpnia 1997 r. o ochronie danych osobowych. Używamy plików cookies.</p></main>'.$shopFooter;
        $terms = $shopHead.'<main><h1>Regulamin sklepu</h1><p>Konsument może odstąpić od umowy zgodnie z przepisami.</p>'
            .'<p>Reklamacje z tytułu rękojmi rozpatrujemy w ciągu 14 dni roboczych od otrzymania zgłoszenia.</p>'
            .'<p>Platforma ODR: https://ec.europa.eu/consumers/odr</p></main>'.$shopFooter;

        // --- strona zgodna z przepisami: Cookiebot, Consent Mode, piksel zablokowany do zgody, GA4 w GTM
        $okHead = '<!doctype html><html lang="pl"><head><title>Biuro</title>'
            .'<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("consent","default",{ad_storage:"denied",analytics_storage:"denied",ad_user_data:"denied",ad_personalization:"denied"});</script>'
            .'<script id="Cookiebot" src="https://consent.cookiebot.com/uc.js" data-cbid="abc"></script>'
            .'<script>(function(w,d,s,l,i){})(window,document,"script","dataLayer","GTM-ABCD12");</script>'
            .'<script type="text/plain" data-cookieconsent="marketing">fbq("init","9876543210123");</script></head><body>';
        $okFooter = '<footer>Biuro Rachunkowe Nowak, ul. Polna 1, 00-001 Warszawa, NIP 525-000-11-22 <a href="/polityka-prywatnosci/">Polityka prywatności</a></footer></body></html>';
        $okHome = $okHead.'<main><h1>Księgowość</h1><form><input type="text" name="imie" placeholder="Imię"><input type="email" name="email" placeholder="E-mail">'
            .'<p>Administratorem Twoich danych jest Biuro Nowak. Szczegóły w <a href="/polityka-prywatnosci/">polityce prywatności</a>.</p><button>Wyślij</button></form></main>'.$okFooter;
        $okPrivacy = $okHead.'<main><h1>Polityka prywatności</h1><p>Administratorem danych jest Biuro Nowak. Korzystamy z Google Analytics oraz piksela Meta (Facebook). Pliki cookies zapisujemy po zgodzie.</p></main>'.$okFooter;

        // --- sam pasek „strona używa cookies” + GA4
        $infoHome = '<!doctype html><html lang="pl"><head><title>Info</title><script type="text/rocketlazyloadscript" async data-rocket-src="https://www.googletagmanager.com/gtag/js?id=G-INFO123456"></script></head><body>'
            .'<main><h1>Usługi</h1></main><div class="cookie-bar">Ta strona używa plików cookies. <button>Rozumiem</button></div></body></html>';

        Http::fake(function (Request $r) use ($html, $shopHome, $product, $privacy, $terms, $okHome, $okPrivacy, $infoHome) {
            $u = rtrim($r->url(), '/');

            return match ($u) {
                'https://sklep.example' => $html($shopHome, ['Set-Cookie' => '_ga=GA1.1.123; path=/']),
                'https://sklep.example/produkt/buty-zimowe' => $html($product),
                'https://sklep.example/polityka-prywatnosci' => $html($privacy),
                'https://sklep.example/regulamin' => $html($terms),
                'https://dobra.example' => $html($okHome),
                'https://dobra.example/polityka-prywatnosci' => $html($okPrivacy),
                'https://www.googletagmanager.com/gtm.js?id=GTM-ABCD12' => Http::response('var data={"vtp_measurementId":"G-ZZZ1234567"};', 200, ['Content-Type' => 'application/javascript']),
                'https://info.example' => $html($infoHome),
                default => Http::response('', 404),
            };
        });
    }

    private function titles(array $r)
    {
        return collect($r['findings'])->where('section', 'legal')->keyBy('title');
    }

    public function test_shop_violations_are_reported(): void
    {
        $this->fakeSites();
        $r = app(PageInspector::class)->inspect('https://sklep.example');
        $t = $this->titles($r);
        $has = fn (string $part) => $this->assertTrue($t->keys()->contains(fn ($k) => str_contains($k, $part)), "Brak uwagi: $part\n".$t->keys()->implode("\n"));

        $legal = $r['legal'];
        $this->assertTrue($legal['shop']);
        $this->assertSame('WooCommerce', $legal['platform']);
        $this->assertSame('cmp', $legal['banner']);
        $tools = collect($legal['tools'])->keyBy('name');
        $this->assertFalse($tools['Google Analytics 4']['ok']);
        $this->assertSame(['G-ABC1234567'], $tools['Google Analytics 4']['ids']);
        $this->assertFalse($tools['Meta Pixel (Facebook)']['ok']);

        // zgody
        $has('Kody śledzące uruchamiają się przed wyrażeniem zgody');
        $before = $t['Kody śledzące uruchamiają się przed wyrażeniem zgody'];
        $this->assertSame('pilne', $before['sev']);
        $this->assertStringContainsString('G-ABC1234567', $before['text']);
        $this->assertStringContainsString('art. 399', $before['text']);
        $has('Serwer zapisuje ciasteczka śledzące');
        $has('YouTube');
        $has('Czcionki Google');

        // polityka, formularze, dane firmy
        $has('Polityka prywatności nie opisuje narzędzi');
        $this->assertStringContainsString('Meta Pixel', $t['Polityka prywatności nie opisuje narzędzi używanych na stronie']['text']);
        $has('nieaktualne przepisy');
        $has('Zgoda w formularzu zaznaczona z góry');
        $has('Zapis na newsletter bez zgody');
        $has('Formularz bez informacji o przetwarzaniu');
        $has('nie ma NIP');

        // sklep
        $has('Brak wzoru formularza odstąpienia');
        $has('14-dniowego terminu');
        $has('rękojmi');
        $has('platformy ODR');
        $has('Brak informacji o dostawie i płatnościach');
        $this->assertFalse($t->keys()->contains(fn ($k) => str_contains($k, 'prawie odstąpienia')), 'odstąpienie jest opisane w regulaminie');
        $this->assertFalse($t->keys()->contains(fn ($k) => str_contains($k, 'Brak regulaminu')));
        $has('najniższej cenie z 30 dni');
        $this->assertSame('pilne', $t['Promocje bez informacji o najniższej cenie z 30 dni (Omnibus)']['sev']);
        $this->assertStringContainsString('produkt/buty-zimowe', $t['Promocje bez informacji o najniższej cenie z 30 dni (Omnibus)']['evidence']);
        $has('opinie o produktach');
        $has('Europejski Akt o Dostępności');

        $docs = collect($legal['documents'])->keyBy('key');
        $this->assertSame('ok', $docs['privacy']['status']);
        $this->assertSame('in_text', $docs['returns']['status']);
        $this->assertSame('missing', $docs['withdrawal_form']['status']);
        $this->assertFalse($legal['promo']['lowest']);

        // raport: osobna sekcja, tabela narzędzi i dokumentów w wersji dla klienta
        $audit = WebAudit::create(['url' => 'https://sklep.example/', 'status' => 'done', 'score' => 40, 'token' => 'sklep-token', 'areas' => []]);
        $report = app(AuditReportBuilder::class)->build($audit, [], $r, null);
        $section = collect($report['sections'])->firstWhere('key', 'legal');
        $this->assertSame('Zgodność z prawem: cookies, RODO, sklep', $section['title']);
        $this->assertSame('Zgody na cookies i śledzenie', $section['groups'][0]['title']);
        $this->assertContains('technika', array_column($report['plan']['now'], 'who'));
        $this->assertContains('dokumenty', array_column($report['plan']['now'], 'who'));
        $audit->update(['report' => $report]);
        $this->get('/audyt/sklep-token')->assertOk()
            ->assertSee('Zgodność z prawem: cookies, RODO, sklep')
            ->assertSee('Narzędzia śledzące i zgody')
            ->assertSee('G-ABC1234567')
            ->assertSee('uruchamia się od razu, przed zgodą')
            ->assertSee('Wzór formularza odstąpienia od umowy')
            ->assertSee('nie zastępuje porady prawnej');
        $this->get('/audyt/sklep-token/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_compliant_site_has_no_consent_violations(): void
    {
        $this->fakeSites();
        $r = app(PageInspector::class)->inspect('https://dobra.example');
        $legal = $r['legal'];
        $tools = collect($legal['tools'])->keyBy('name');

        $this->assertFalse($legal['shop']);
        $this->assertSame(['Cookiebot'], $legal['cmp']);
        $this->assertTrue($legal['consent_mode']);
        $this->assertTrue($tools['Google Tag Manager']['ok']);
        $this->assertTrue($tools['Meta Pixel (Facebook)']['ok'], 'piksel z type="text/plain" czeka na zgodę');
        $this->assertSame('GTM (GTM-ABCD12)', $tools['Google Analytics 4']['source']);
        $this->assertTrue($tools['Google Analytics 4']['ok']);

        $titles = $this->titles($r)->keys()->implode("\n");
        $this->assertStringNotContainsString('zgod', mb_strtolower(str_replace('Zgoda', '', $titles)), $titles);
        $this->assertStringNotContainsString('Brak polityki', $titles);
        $this->assertStringNotContainsString('nie opisuje narzędzi', $titles);
        $this->assertStringNotContainsString('Formularz bez informacji', $titles);
        $this->assertStringNotContainsString('NIP', $titles);
        $this->assertStringNotContainsString('Omnibus', $titles);
    }

    public function test_info_only_cookie_bar_is_not_consent(): void
    {
        $this->fakeSites();
        $r = app(PageInspector::class)->inspect('https://info.example');
        $t = $this->titles($r);

        $this->assertSame('info', $r['legal']['banner']);
        $this->assertTrue($t->has('Baner cookies tylko informuje – kody śledzące działają bez zgody'), $t->keys()->implode("\n"));
        $this->assertSame('pilne', $t['Baner cookies tylko informuje – kody śledzące działają bez zgody']['sev']);
        $this->assertTrue($t->has('Brak polityki prywatności'));
        $this->assertSame('pilne', $t['Brak polityki prywatności']['sev'], 'strona zbiera dane przez GA4');
    }
}
