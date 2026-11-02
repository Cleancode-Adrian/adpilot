<?php

namespace App\Services\Tracking;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audyt pomiaru na stronie klienta: GTM, GA4, Google Ads, Meta Pixel, Consent Mode,
 * podstawy SEO/technika i (opcjonalnie) PageSpeed. Działa bez dostępu do kont reklamowych.
 *
 * Kontenery GTM są pobierane publicznie (gtm.js), więc wykrywamy też tagi
 * dodane w GTM – nie tylko te wklejone w kod strony.
 */
class TrackingAuditor
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 AdPilotAudit/1.0';

    private const CMP_SIGNATURES = [
        'Cookiebot' => ['consent.cookiebot.com', 'Cookiebot'],
        'CookieYes' => ['cookieyes.com', 'cky-consent'],
        'OneTrust' => ['onetrust.com', 'otSDKStub', 'cookielaw.org'],
        'Complianz' => ['complianz', 'cmplz'],
        'Cookie Script' => ['cookie-script.com'],
        'iubenda' => ['iubenda.com'],
        'CookieFirst' => ['cookiefirst.com'],
        'Usercentrics' => ['usercentrics.eu', 'usercentrics'],
        'Didomi' => ['didomi'],
        'Cookie Notice' => ['cookie-notice'],
        'Shopify Customer Privacy' => ['customerPrivacy'],
    ];

    private const PLATFORMS = [
        'Shopify' => ['cdn.shopify.com', 'Shopify.theme'],
        'WooCommerce' => ['woocommerce', 'wc-ajax'],
        'PrestaShop' => ['prestashop', '/modules/ps_'],
        'Shoper' => ['shoper.pl', 'shoper-'],
        'IdoSell' => ['idosell', 'iai-shop', 'iaicdn'],
        'Magento' => ['Magento_', 'mage/cookies'],
        'Sky-Shop' => ['sky-shop'],
        'WordPress' => ['wp-content', 'wp-includes'],
    ];

    /** @param string|null $goal sales|leads|mixed; null = wykryj (sklep → sales, inne → leads) */
    public function audit(string $url, ?string $goal = null): array
    {
        $url = $this->normalizeUrl($url);
        $start = microtime(true);
        $response = Http::withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'pl,en;q=0.8'])
            ->timeout(25)->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
            ->get($url);
        $responseMs = (int) round((microtime(true) - $start) * 1000);

        if ($response->failed()) {
            throw new \RuntimeException("Strona zwróciła błąd HTTP {$response->status()}."
                .(in_array($response->status(), [403, 429, 503]) ? ' Prawdopodobnie blokuje automatyczne skanowanie (np. Cloudflare) – sprawdź pomiar ręcznie w Tag Assistant.' : ''));
        }

        $html = $response->body();
        $finalUrl = $this->finalUrl($response, $url);

        $detected = $this->detectTags($html);
        $containers = [];
        foreach ($detected['gtm'] as $gtmId) {
            $containers[$gtmId] = $this->inspectGtmContainer($gtmId);
        }

        $base = $this->origin($finalUrl);
        $robots = $this->safeGet($base.'/robots.txt');
        $sitemapUrl = $robots && preg_match('/^sitemap:\s*(\S+)/mi', $robots->body(), $m) ? $m[1] : $base.'/sitemap.xml';
        $sitemap = $this->safeGet($sitemapUrl);

        $pagespeed = config('adpilot.pagespeed.enabled') ? $this->pagespeed($finalUrl) : null;

        $platform = $this->detectPlatform($html);
        $goal ??= in_array($platform, [null, 'WordPress'], true) ? 'leads' : 'sales';

        $checks = $this->buildChecks($html, $finalUrl, $detected, $containers, $robots, $sitemap, $responseMs, $pagespeed, $goal);

        return [
            'final_url' => $finalUrl,
            'platform' => $platform,
            'goal' => $goal,
            'response_ms' => $responseMs,
            'page_kb' => (int) round(strlen($html) / 1024),
            'detected' => $detected,
            'gtm_containers' => $containers,
            'pagespeed' => $pagespeed,
            'checks' => $checks,
            'score' => $this->score($checks),
        ];
    }

    // ---------------------------------------------------------------- wykrywanie

    private function detectTags(string $html): array
    {
        $unique = fn (array $m) => array_values(array_unique($m));

        preg_match_all('/GTM-[A-Z0-9]{4,10}/', $html, $gtm);
        preg_match_all('/\bG-[A-Z0-9]{6,12}\b/', $html, $ga4);
        preg_match_all('/\bAW-\d{6,12}\b/', $html, $aw);
        preg_match_all('/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{10,20})[\'"]/', $html, $fb);

        $others = [];
        $map = [
            'TikTok Pixel' => 'analytics.tiktok.com',
            'Microsoft Clarity' => 'clarity.ms',
            'Hotjar' => 'static.hotjar.com',
            'Pinterest Tag' => 's.pinimg.com/ct',
            'Microsoft Ads (UET)' => 'bat.bing.com',
            'LinkedIn Insight' => 'snap.licdn.com',
            'Criteo' => 'criteo',
            'Ceneo zaufane opinie' => 'ceneo',
            'Trusted Shops' => 'trustedshops',
            'Klaviyo' => 'klaviyo',
            'GetResponse' => 'getresponse',
        ];
        foreach ($map as $name => $needle) {
            if (Str::contains($html, $needle, true)) {
                $others[] = $name;
            }
        }

        $cmp = [];
        foreach (self::CMP_SIGNATURES as $name => $needles) {
            if (Str::contains($html, $needles, true)) {
                $cmp[] = $name;
            }
        }

        return [
            'gtm' => $unique($gtm[0]),
            'ga4' => $unique($ga4[0]),
            'google_ads' => $unique($aw[0]),
            'meta_pixel' => $unique($fb[1]),
            'meta_pixel_script' => Str::contains($html, 'connect.facebook.net', true),
            'gtag_script' => Str::contains($html, 'googletagmanager.com/gtag/js'),
            'consent_default_in_page' => (bool) preg_match('/gtag\(\s*[\'"]consent[\'"]\s*,\s*[\'"]default[\'"]/', $html),
            'datalayer' => Str::contains($html, 'dataLayer'),
            'forms' => preg_match_all('/<form\b/i', $html),
            'tel_links' => preg_match_all('/href=["\']tel:/i', $html),
            'lead_event_in_page' => (bool) preg_match('/["\'](generate_lead|form_submit|sign_up|submit_lead_form)["\']/', $html),
            'cmp' => $cmp,
            'others' => $others,
        ];
    }

    private function inspectGtmContainer(string $id): array
    {
        $res = $this->safeGet("https://www.googletagmanager.com/gtm.js?id={$id}");
        if (! $res || ! $res->successful()) {
            return ['reachable' => false];
        }
        $js = $res->body();

        preg_match_all('/\bG-[A-Z0-9]{6,12}\b/', $js, $ga4);
        preg_match_all('/\bAW-\d{6,12}\b/', $js, $aw);
        // W gtm.js identyfikatory konwersji Ads zwykle występują jako "vtp_conversionId":"123456789"
        preg_match_all('/vtp_conversionId"\s*:\s*"(\d{6,12})"/', $js, $awConv);
        preg_match_all('/(?:fbq\(\s*\\\\?[\'"]init\\\\?[\'"]\s*,\s*\\\\?[\'"]|vtp_pixelId"\s*:\s*")(\d{10,20})/', $js, $fb);

        $awIds = array_unique(array_merge($aw[0], array_map(fn ($n) => 'AW-'.$n, $awConv[1])));

        return [
            'reachable' => true,
            'size_kb' => (int) round(strlen($js) / 1024),
            'ga4' => array_values(array_unique($ga4[0])),
            'google_ads' => array_values($awIds),
            'meta_pixel' => array_values(array_unique($fb[1])),
            'has_meta_pixel_code' => Str::contains($js, ['connect.facebook.net', 'fbevents']),
            'has_ads_conversion_tag' => Str::contains($js, '__awct'),
            'has_remarketing_tag' => Str::contains($js, '__sp'),
            'has_conversion_linker' => Str::contains($js, '__gclidw'),
            'has_ga4_event_tag' => Str::contains($js, '__gaawe'),
            'has_purchase_event' => Str::contains($js, '"purchase"'),
            'has_lead_event' => Str::contains($js, ['"generate_lead"', '"form_submit"', '"sign_up"', '"submit_lead_form"', '"close_convert_lead"']),
            'has_form_trigger' => Str::contains($js, ['gtm.formSubmit', '__fsl']),
            'has_meta_lead' => (bool) preg_match('/(Lead|CompleteRegistration|Contact)/', $js) && Str::contains($js, ['fbq', 'fbevents']),
            'has_enhanced_conversions' => Str::contains($js, ['enhanced_conversion', 'vtp_enableEnhancedConversion', 'user_data']),
            'has_consent_settings' => Str::contains($js, ['consent', 'ad_storage', 'analytics_storage']),
            'has_ad_user_data' => Str::contains($js, ['ad_user_data', 'ad_personalization']),
        ];
    }

    private function detectPlatform(string $html): ?string
    {
        foreach (self::PLATFORMS as $name => $needles) {
            if (Str::contains($html, $needles, true)) {
                return $name;
            }
        }

        return null;
    }

    private function pagespeed(string $url): ?array
    {
        try {
            $res = Http::timeout(90)->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', array_filter([
                'url' => $url,
                'strategy' => 'mobile',
                'category' => 'performance',
                'key' => config('adpilot.pagespeed.api_key'),
            ]));
            if (! $res->successful()) {
                return null;
            }
            $lh = $res->json('lighthouseResult');

            return [
                'performance' => (int) round(($lh['categories']['performance']['score'] ?? 0) * 100),
                'lcp_s' => round(($lh['audits']['largest-contentful-paint']['numericValue'] ?? 0) / 1000, 1),
                'cls' => round($lh['audits']['cumulative-layout-shift']['numericValue'] ?? 0, 3),
                'tbt_ms' => (int) ($lh['audits']['total-blocking-time']['numericValue'] ?? 0),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------- checklista

    private function buildChecks(string $html, string $url, array $d, array $containers, ?Response $robots, ?Response $sitemap, int $responseMs, ?array $ps, string $goal = 'sales'): array
    {
        $checks = [];
        $add = function (string $key, string $group, string $label, string $status, string $details, ?string $fix = null, int $weight = 5) use (&$checks) {
            $checks[] = compact('key', 'group', 'label', 'status', 'details', 'fix', 'weight');
        };

        $allGa4 = array_unique(array_merge($d['ga4'], ...array_map(fn ($c) => $c['ga4'] ?? [], array_values($containers))));
        $allAw = array_unique(array_merge($d['google_ads'], ...array_map(fn ($c) => $c['google_ads'] ?? [], array_values($containers))));
        $pixelIds = array_unique(array_merge($d['meta_pixel'], ...array_map(fn ($c) => $c['meta_pixel'] ?? [], array_values($containers))));
        $pixelPresent = $pixelIds || $d['meta_pixel_script'] || collect($containers)->contains(fn ($c) => $c['has_meta_pixel_code'] ?? false);
        $any = fn (string $flag) => collect($containers)->contains(fn ($c) => $c[$flag] ?? false);

        // --- Fundament
        $add('https', 'Fundament', 'Strona działa na HTTPS', str_starts_with($url, 'https://') ? 'ok' : 'fail',
            str_starts_with($url, 'https://') ? 'Certyfikat SSL aktywny.' : 'Strona nie przekierowuje na HTTPS.',
            'Włącz SSL i przekierowanie 301 na https – bez tego spada jakość reklam i zaufanie.', 8);

        $add('gtm', 'Fundament', 'Google Tag Manager', $d['gtm'] ? 'ok' : 'warn',
            $d['gtm'] ? 'Wykryto: '.implode(', ', $d['gtm']) : 'Brak kontenera GTM.',
            'Wdróż GTM – ułatwia zarządzanie tagami GA4, Ads i Meta bez zmian w kodzie sklepu.', 6);

        if (count($d['gtm']) > 1) {
            $add('gtm_multi', 'Fundament', 'Wiele kontenerów GTM', 'warn', 'Na stronie są '.count($d['gtm']).' kontenery GTM.',
                'Sprawdź, czy kontenery nie dublują tagów (podwójne konwersje).', 4);
        }

        // --- GA4
        $add('ga4', 'Google Analytics 4', 'Tag GA4', $allGa4 ? 'ok' : 'fail',
            $allGa4 ? 'Identyfikatory pomiaru: '.implode(', ', $allGa4) : 'Nie wykryto GA4 ani w kodzie, ani w GTM.',
            'Wdróż GA4 (najlepiej przez GTM) – bez tego nie zmierzymy ruchu i sprzedaży niezależnie od platform reklamowych.', 15);

        if (count($allGa4) > 1) {
            $add('ga4_multi', 'Google Analytics 4', 'Wiele strumieni GA4', 'warn', 'Wykryto '.count($allGa4).' różne ID GA4.',
                'Upewnij się, że to celowe (np. dwie usługi). Inaczej dane rozjeżdżają się między raportami.', 3);
        }

        $containerGa4 = array_merge([], ...array_map(fn ($c) => $c['ga4'] ?? [], array_values($containers)));
        if ($d['gtag_script'] && array_intersect($d['ga4'], $containerGa4)) {
            $add('ga4_duplicate', 'Google Analytics 4', 'Podwójny tag GA4', 'fail',
                'Ten sam identyfikator GA4 jest w kodzie strony (gtag.js) i w GTM.',
                'Usuń jedno z wdrożeń – podwójne odsłony i transakcje zawyżają wyniki.', 10);
        }

        if ($d['gtm'] && $goal !== 'leads') {
            $add('purchase_event', 'Google Analytics 4', 'Zdarzenie purchase w GTM', $any('has_purchase_event') ? 'ok' : 'warn',
                $any('has_purchase_event') ? 'Kontener zawiera konfigurację zdarzenia purchase.' : 'Nie znaleziono zdarzenia purchase w kontenerze GTM.',
                'Sprawdź, czy sklep wysyła purchase z wartością i walutą (dataLayer ecommerce). Bez tego nie policzymy ROAS.', 12);
        }

        // --- Pozyskiwanie kontaktów (formularze, rejestracje, telefony)
        if ($goal !== 'sales') {
            $leadMeasured = $any('has_lead_event') || $d['lead_event_in_page'];
            $add('lead_event', 'Kontakty (leady)', 'Mierzenie wysłania formularza / rejestracji', $leadMeasured ? 'ok' : ($any('has_form_trigger') ? 'warn' : 'fail'),
                $leadMeasured ? 'Wykryto zdarzenie leadowe (np. generate_lead, form_submit, sign_up).'
                    : ($any('has_form_trigger') ? 'GTM ma wyzwalacz formularza, ale nie znaleziono zdarzenia generate_lead.' : 'Nie znaleziono zdarzenia mierzącego wysłanie formularza.'),
                'Dodaj w GTM zdarzenie GA4 „generate_lead” (lub „sign_up” dla rejestracji) wywoływane po udanym wysłaniu formularza – najlepiej na stronie podziękowania. Oznacz je w GA4 jako kluczowe i zaimportuj do Google Ads jako konwersję główną.', 15);

            $add('forms', 'Kontakty (leady)', 'Formularz na stronie głównej', $d['forms'] > 0 ? 'ok' : 'warn',
                $d['forms'] > 0 ? 'Liczba formularzy: '.$d['forms'].' (w tym np. wyszukiwarka/newsletter).' : 'Brak formularza w kodzie strony głównej (może być na podstronie lub ładowany skryptem).',
                'Formularz kontaktowy powinien być widoczny na stronach, na które kierują reklamy – krótki (3–5 pól) i z wyraźnym przyciskiem.', 4);

            $add('tel_links', 'Kontakty (leady)', 'Klikalny numer telefonu', $d['tel_links'] > 0 ? 'ok' : 'warn',
                $d['tel_links'] > 0 ? 'Linki tel: '.$d['tel_links'] : 'Brak linków tel: – na telefonie nie da się zadzwonić jednym kliknięciem.',
                'Zamień numer na link <a href="tel:+48..."> i mierz kliknięcia jako zdarzenie (np. phone_click) – to często 30–50% kontaktów z reklam na mobile.', 5);

            if ($d['meta_pixel'] || $d['meta_pixel_script'] || $any('has_meta_pixel_code')) {
                $metaLead = $any('has_meta_lead') || preg_match('/fbq\(\s*["\']track["\']\s*,\s*["\'](Lead|CompleteRegistration|Contact)/', $html);
                $add('meta_lead_event', 'Kontakty (leady)', 'Zdarzenie Lead w Meta Pixel', $metaLead ? 'ok' : 'warn',
                    $metaLead ? 'Wykryto zdarzenie Lead/CompleteRegistration/Contact.' : 'Nie wykryto zdarzenia Lead.',
                    'Wysyłaj do Meta zdarzenie Lead po wysłaniu formularza – bez tego kampanie na konwersje nie wiedzą, kogo szukać.', 8);
            }
        }

        // --- Google Ads
        $add('google_ads_tag', 'Google Ads', 'Tag Google Ads (AW-)', $allAw ? 'ok' : 'warn',
            $allAw ? 'Wykryto: '.implode(', ', $allAw) : 'Brak tagu Google Ads.',
            'Dodaj tag konwersji Google Ads lub importuj konwersje z GA4 – inaczej Smart Bidding nie ma czego optymalizować.', 10);

        if ($allAw) {
            $add('conversion_linker', 'Google Ads', 'Conversion Linker', $any('has_conversion_linker') || $d['gtag_script'] ? 'ok' : 'warn',
                $any('has_conversion_linker') ? 'Tag Conversion Linker obecny w GTM.' : 'Nie wykryto Conversion Linkera.',
                'Dodaj tag „Conversion Linker” w GTM (wyzwalacz: All Pages) – poprawia przypisanie kliknięć (gclid).', 5);

            $add('enhanced_conversions', 'Google Ads', 'Konwersje rozszerzone (Enhanced Conversions)', $any('has_enhanced_conversions') ? 'ok' : 'warn',
                $any('has_enhanced_conversions') ? 'Wykryto konfigurację danych użytkownika.' : 'Nie wykryto konwersji rozszerzonych.',
                'Włącz Enhanced Conversions – odzyskuje zwykle kilka–kilkanaście % konwersji utraconych przez cookies.', 6);
        }

        // --- Meta
        $add('meta_pixel', 'Meta (Facebook/Instagram)', 'Meta Pixel', $pixelPresent ? 'ok' : 'warn',
            $pixelPresent ? ($pixelIds ? 'Pixel ID: '.implode(', ', $pixelIds) : 'Wykryto kod Pixela.') : 'Nie wykryto Meta Pixela.',
            'Wdróż Meta Pixel ze zdarzeniami ViewContent, AddToCart, InitiateCheckout, Purchase (z wartością).', 10);

        if ($pixelPresent) {
            $add('meta_capi', 'Meta (Facebook/Instagram)', 'Conversions API (CAPI)', 'info',
                'CAPI działa po stronie serwera – nie da się go wykryć z kodu strony.',
                'Zweryfikuj w Menedżerze zdarzeń: „Jakość dopasowania zdarzeń” ≥ 6 i deduplikację (event_id). Integracje: Shopify/Woo mają gotowe wtyczki.', 0);
        }

        // --- Zgody (RODO / Consent Mode v2)
        $consentInGtm = $any('has_consent_settings');
        $consentV2InGtm = $consentInGtm && $any('has_ad_user_data');
        $consentOk = $d['consent_default_in_page'] || $consentV2InGtm || ($d['cmp'] && ($consentInGtm || $d['gtag_script']));

        // Brak znanego CMP przy skonfigurowanych zgodach w GTM = najpewniej własny baner → ostrzeżenie, nie błąd
        $cmpStatus = $d['cmp'] ? 'ok' : ($consentInGtm ? 'warn' : 'fail');
        $add('cmp', 'Zgody (RODO)', 'Baner zgód (CMP)', $cmpStatus,
            $d['cmp'] ? 'Wykryto: '.implode(', ', $d['cmp'])
                : ($consentInGtm ? 'Nie rozpoznano znanego CMP, ale GTM zawiera ustawienia zgód – prawdopodobnie własny baner.' : 'Nie wykryto znanej platformy zgód.'),
            $consentInGtm
                ? 'Sprawdź ręcznie, czy baner jest certyfikowany przez Google (lista CMP Partner Program) – w EOG to wymóg dla remarketingu i personalizacji.'
                : 'Wdróż certyfikowany CMP (np. Cookiebot, CookieYes) – w EOG jest to wymagane przez Google od 03.2024.', 10);

        $add('consent_mode', 'Zgody (RODO)', 'Google Consent Mode v2', $consentOk ? 'ok' : 'fail',
            $consentOk ? ($consentV2InGtm && ! $d['consent_default_in_page'] ? 'Wykryto ustawienia Consent Mode v2 w kontenerze GTM.' : 'Wykryto sygnały Consent Mode.') : 'Brak sygnałów Consent Mode (consent default).',
            'Skonfiguruj Consent Mode v2 (ad_storage, analytics_storage, ad_user_data, ad_personalization). Bez tego Google Ads traci remarketing i modelowanie konwersji w UE.', 12);

        if ($consentOk && $d['gtm'] && ! $any('has_ad_user_data') && ! Str::contains($html, 'ad_user_data')) {
            $add('consent_v2_params', 'Zgody (RODO)', 'Parametry v2 (ad_user_data / ad_personalization)', 'warn',
                'Nie znaleziono nowych parametrów Consent Mode v2.', 'Zaktualizuj CMP/szablon GTM do wersji obsługującej Consent Mode v2.', 6);
        }

        // --- SEO / technika (ma wpływ na Wynik Jakości i koszt kliknięcia)
        $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
        $add('title', 'Strona i SEO', 'Tytuł strony', $title && mb_strlen($title) <= 65 ? 'ok' : 'warn',
            $title ? '„'.Str::limit($title, 80).'” ('.mb_strlen($title).' zn.)' : 'Brak tagu <title>.', 'Tytuł 30–65 znaków z główną frazą i marką.', 2);

        $hasDesc = (bool) preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\'][^"\']{20,}/i', $html);
        $add('meta_description', 'Strona i SEO', 'Meta description', $hasDesc ? 'ok' : 'warn',
            $hasDesc ? 'Obecny.' : 'Brak lub zbyt krótki.', 'Dodaj opis 120–160 znaków z korzyścią i CTA.', 2);

        $hasViewport = Str::contains($html, 'name="viewport"') || Str::contains($html, "name='viewport'");
        $add('viewport', 'Strona i SEO', 'Wersja mobilna (viewport)', $hasViewport ? 'ok' : 'fail',
            $hasViewport ? 'Meta viewport obecny.' : 'Brak meta viewport.', 'Strona musi być responsywna – ponad 70% ruchu z reklam to mobile.', 5);

        $hasSchema = Str::contains($html, 'application/ld+json');
        $add('schema', 'Strona i SEO', 'Dane strukturalne (JSON-LD)', $hasSchema ? 'ok' : 'warn',
            $hasSchema ? 'Wykryto JSON-LD.' : 'Brak danych strukturalnych.', 'Dodaj Organization oraz Product (z ceną i dostępnością) na kartach produktów.', 2);

        $add('robots', 'Strona i SEO', 'robots.txt', $robots && $robots->successful() ? 'ok' : 'warn',
            $robots && $robots->successful() ? 'Dostępny.' : 'Nie znaleziono robots.txt.', 'Dodaj robots.txt ze wskazaniem mapy strony.', 1);

        $sitemapOk = $sitemap && $sitemap->successful() && Str::contains($sitemap->body(), ['<urlset', '<sitemapindex']);
        $add('sitemap', 'Strona i SEO', 'Mapa strony (sitemap.xml)', $sitemapOk ? 'ok' : 'warn',
            $sitemapOk ? 'Dostępna.' : 'Nie znaleziono poprawnej mapy strony.', 'Wygeneruj sitemap.xml i zgłoś w Search Console.', 1);

        $add('response_time', 'Szybkość', 'Czas odpowiedzi serwera', $responseMs < 800 ? 'ok' : ($responseMs < 2000 ? 'warn' : 'fail'),
            "{$responseMs} ms (pobranie HTML).", 'Włącz cache strony / CDN, rozważ szybszy hosting.', 4);

        if ($ps) {
            $perf = $ps['performance'];
            $add('pagespeed', 'Szybkość', 'PageSpeed (mobile)', $perf >= 70 ? 'ok' : ($perf >= 45 ? 'warn' : 'fail'),
                "Wynik {$perf}/100, LCP {$ps['lcp_s']} s, CLS {$ps['cls']}, TBT {$ps['tbt_ms']} ms.",
                'Optymalizuj obrazy (WebP/AVIF), lazy-load, ogranicz skrypty zewnętrzne. Wolna strona = droższe kliknięcia i niższa konwersja.', 8);
        }

        return $checks;
    }

    private function score(array $checks): int
    {
        $total = 0;
        $earned = 0;
        foreach ($checks as $c) {
            if ($c['weight'] <= 0) {
                continue;
            }
            $total += $c['weight'];
            $earned += match ($c['status']) { 'ok' => $c['weight'], 'warn' => $c['weight'] * 0.4, default => 0 };
        }

        return $total ? (int) round($earned / $total * 100) : 0;
    }

    // ---------------------------------------------------------------- helpers

    private function normalizeUrl(string $url): string
    {
        return \App\Models\Client::normalizeUrl($url)
            ?? throw new \RuntimeException("Nieprawidłowy adres strony: {$url}. Popraw go w ustawieniach klienta (np. https://twojadomena.pl).");
    }

    private function finalUrl(Response $response, string $fallback): string
    {
        $history = $response->header('X-Guzzle-Redirect-History');
        if ($history) {
            $parts = array_map('trim', explode(',', $history));

            return end($parts) ?: $fallback;
        }

        return $fallback;
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
    }

    private function safeGet(string $url): ?Response
    {
        try {
            return Http::withHeaders(['User-Agent' => self::UA])->timeout(15)->get($url);
        } catch (Throwable) {
            return null;
        }
    }
}
