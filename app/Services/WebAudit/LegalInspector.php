<?php

namespace App\Services\WebAudit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Zgodność strony z przepisami – to, co da się sprawdzić z kodu i treści, bez logowania i bez składania zamówienia:
 * - kody śledzące (Google Analytics, Tag Manager, Google Ads, piksele) a zgoda: czy ładują się od razu, czy czekają na decyzję w banerze,
 *   baner zgód (CMP), Consent Mode, ciasteczka ustawiane przez serwer, osadzone filmy i mapy,
 * - polityka prywatności (czy jest, czy działa, czy opisuje używane narzędzia, czy nie powołuje się na stare przepisy),
 *   formularze (informacja RODO, zgody zaznaczone z góry, newsletter), dane firmy (NIP / KRS / CEIDG),
 * - sklep: regulamin, prawo odstąpienia i wzór formularza, reklamacje, dostawa i płatności, Omnibus (najniższa cena z 30 dni),
 *   weryfikacja opinii, informacja o dostępności (EAA).
 *
 * To ocena techniczna, nie porada prawna – punkty, których nie da się przesądzić z samego kodu, mają znacznik „do potwierdzenia”.
 */
class LegalInspector
{
    public const GROUPS = ['Zgody na cookies i śledzenie', 'Polityka prywatności i formularze', 'Dane firmy', 'Sklep: regulamin, zwroty i reklamacje',
        'Ceny i promocje (Omnibus)', 'Dostępność cyfrowa (EAA)'];

    private const UA = 'Mozilla/5.0 (compatible; CC-SiteAudit/1.0)';

    private const G_CONSENT = 'Zgody na cookies i śledzenie';

    private const G_PRIVACY = 'Polityka prywatności i formularze';

    private const G_COMPANY = 'Dane firmy';

    private const G_SHOP = 'Sklep: regulamin, zwroty i reklamacje';

    private const G_OMNIBUS = 'Ceny i promocje (Omnibus)';

    private const G_EAA = 'Dostępność cyfrowa (EAA)';

    private const LAW_COOKIES = 'Pliki cookies analityczne i reklamowe wolno zapisać dopiero po zgodzie odwiedzającego (art. 399 Prawa komunikacji elektronicznej, wcześniej art. 173 Prawa telekomunikacyjnego), a dane zbierane przez te narzędzia są danymi osobowymi w rozumieniu RODO.';

    /** narzędzie => [wzorzec w kodzie skryptu, wzorzec identyfikatora (grupa 1 albo całe dopasowanie), narzędzie Google (działa z Consent Mode)] */
    private const TOOLS = [
        'Google Tag Manager' => ['#googletagmanager\.com/gtm\.js|[\'"]GTM-[A-Z0-9]{4,10}[\'"]#', '/GTM-[A-Z0-9]{4,10}/', true],
        'Google Analytics 4' => ['#googletagmanager\.com/gtag/js\?id=GT?-|gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]GT?-#', '/\bGT?-[A-Z0-9]{6,12}\b/', true],
        'Google Ads' => ['#googletagmanager\.com/gtag/js\?id=AW-|gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]AW-#', '/\bAW-\d{6,12}\b/', true],
        'Universal Analytics (stary kod)' => ['#google-analytics\.com/(?:analytics|ga)\.js|[\'"]UA-\d{4,10}-\d{1,3}[\'"]#', '/\bUA-\d{4,10}-\d{1,3}\b/', true],
        'Meta Pixel (Facebook)' => ['#connect\.facebook\.net/[^"\']*/fbevents\.js|fbq\(\s*[\'"]init#', '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{10,20})/', false],
        'TikTok Pixel' => ['#analytics\.tiktok\.com#', null, false],
        'Hotjar' => ['#static\.hotjar\.com|hotjar\.com/c/hotjar#', null, false],
        'Microsoft Clarity' => ['#clarity\.ms/tag#', null, false],
        'LinkedIn Insight' => ['#snap\.licdn\.com#', null, false],
        'Pinterest Tag' => ['#s\.pinimg\.com/ct#', null, false],
        'Microsoft Ads (UET)' => ['#bat\.bing\.com#', null, false],
        'Yandex Metrica' => ['#mc\.yandex\.(?:ru|com)#', null, false],
        'Criteo' => ['#static\.criteo\.net|dynamic\.criteo\.com#', null, false],
    ];

    /** jak narzędzie wygląda w kontenerze GTM (gtm.js) */
    private const GTM_TOOLS = [
        'Google Analytics 4' => '/\bG-[A-Z0-9]{6,12}\b/',
        'Google Ads' => '/\bAW-\d{6,12}\b|vtp_conversionId/',
        'Meta Pixel (Facebook)' => '/fbevents\.js|fbq\(/',
        'TikTok Pixel' => '/analytics\.tiktok\.com/',
        'Hotjar' => '/static\.hotjar\.com|__hjt/',
        'Microsoft Clarity' => '/clarity\.ms/',
        'LinkedIn Insight' => '/snap\.licdn\.com|__bzi/',
        'Pinterest Tag' => '/s\.pinimg\.com/',
        'Microsoft Ads (UET)' => '/bat\.bing\.com|__baut/',
        'Criteo' => '/criteo\.(?:net|com)/',
    ];

    /** jak dane narzędzie bywa nazwane w polityce prywatności */
    private const MENTIONS = [
        'Google Analytics 4' => '/google\s*analytics|\banalytics\b|google\s*ireland|narzędzi\w* google/iu',
        'Universal Analytics (stary kod)' => '/google\s*analytics|\banalytics\b/iu',
        'Google Ads' => '/google\s*ads|adwords|remarketing|reklam\w*\s+google|google\s*ireland/iu',
        'Meta Pixel (Facebook)' => '/facebook|meta\s+platforms|\bmeta\b|piksel|pixel/iu',
        'TikTok Pixel' => '/tiktok/iu',
        'Hotjar' => '/hotjar/iu',
        'Microsoft Clarity' => '/clarity|microsoft/iu',
        'LinkedIn Insight' => '/linkedin/iu',
        'Pinterest Tag' => '/pinterest/iu',
        'Microsoft Ads (UET)' => '/microsoft|bing/iu',
        'Yandex Metrica' => '/yandex/iu',
        'Criteo' => '/criteo/iu',
    ];

    /** platforma zgód => [wykrycie w kodzie, tryb automatycznego blokowania skryptów] */
    private const CMPS = [
        'Cookiebot' => ['/consent\.cookiebot\.(?:com|eu)|cookiebot/i', '/data-blockingmode\s*=\s*["\']auto/i'],
        'CookieYes' => ['/cdn-cookieyes\.com|cookieyes|cookie-law-info/i', null],
        'Complianz' => ['/complianz|cmplz/i', null],
        'OneTrust' => ['/onetrust|cookielaw\.org|otSDKStub/i', '/otAutoBlock/i'],
        'Usercentrics' => ['/usercentrics/i', '/autoblocker/i'],
        'iubenda' => ['/iubenda\.com/i', '/iubenda\.com\/autoblocking/i'],
        'Borlabs Cookie' => ['/borlabs-cookie|BorlabsCookie/i', null],
        'Real Cookie Banner' => ['/real-cookie-banner/i', null],
        'CookieAdmin' => ['/cookieadmin/i', null],
        'GDPR Cookie Compliance' => ['/moove_gdpr|gdpr-cookie-compliance/i', null],
        'Cookie Notice' => ['/cookie-notice|cn-cookie/i', null],
        'CookieFirst' => ['/cookiefirst\.com/i', null],
        'Cookie Script' => ['/cookie-script\.com/i', null],
        'Didomi' => ['/didomi/i', null],
        'Termly' => ['/termly\.io/i', '/resource-blocker/i'],
        'Klaro' => ['/klaro(?:\.js|-config|Config)/i', null],
        'Axeptio' => ['/axeptio/i', null],
        'Osano' => ['/osano\.com/i', null],
        'Shopify (Customer Privacy)' => ['/customerPrivacy|shopify-privacy-banner/i', null],
    ];

    /** ciasteczka narzędzi śledzących – nie mogą przyjść od serwera przy pierwszym wejściu */
    private const TRACKING_COOKIES = '/^(_ga|_gid|_gat|_gcl_|_fbp|_fbc|_hj|_clck|_clsk|_ttp|_uetsid|_uetvid|_pin_unauth|_ym_|bcookie|li_sugr)/i';

    /** warunek zgody wpisany w sam skrypt (skrypt startuje, ale czeka na zgodę) */
    private const GATED = '/Cookiebot\.consent|CookiebotOnAccept|CookieConsent\.|cmplz_|getCkyConsent|cookieyes|OnetrustActiveGroups|OptanonActiveGroups|wp_has_consent|consent_given|hasConsent|cookie_consent|cookiesAccepted|document\.cookie\.(?:indexOf|match|includes)/i';

    /** dokument => [nazwa, wzorzec w tekście lub adresie linku] */
    private const DOCS = [
        'privacy' => ['Polityka prywatności', '/polityk\w*[\s_-]+prywatno|privacy|ochron\w*[\s_-]+danych|\brodo\b|klauzul\w*[\s_-]+informacyjn/iu'],
        'cookies' => ['Polityka cookies', '/polityk\w*[\s_-]+(?:plik\w*[\s_-]+)?cookie|cookies?[\s_-]+polic|ciasteczk/iu'],
        'terms' => ['Regulamin', '/regulamin|\bterms\b|warunki[\s_-]+(?:sprzeda|korzystan|zakup)/iu'],
        'returns' => ['Zwroty i odstąpienie od umowy', '/zwrot|odst[aą]pieni|\breturns?\b|wymian/iu'],
        'withdrawal_form' => ['Wzór formularza odstąpienia od umowy', '/formularz\w*[\s_-]+(?:odst|zwrot)|wz[oó]r[\s_-]+formularza|odst[aą]pieni\w*.*\.(?:pdf|docx?)$|zwrot\w*.*\.(?:pdf|docx?)$/iu'],
        'complaints' => ['Reklamacje', '/reklamac|complaint/iu'],
        'delivery' => ['Dostawa i płatności', '/dostaw|wysy[lł]k|p[lł]atno|shipping|delivery|payment/iu'],
        'accessibility' => ['Informacja o dostępności', '/dost[eę]pno[sś]\w*[\s_-]+(?:cyfrow|serwis|sklep|stron|usług|uslug)|deklaracj\w*[\s_-]+dost[eę]pno|accessibility/iu'],
    ];

    /** te same treści, gdy nie mają osobnej strony, a są opisane w regulaminie */
    private const IN_TEXT = [
        'returns' => '/odst[aą]pi\w*\s+od\s+umowy/iu',
        'withdrawal_form' => '/formularz\w*\s+odst[aą]pienia|wz[oó]r\s+formularza|formularz\w*\s+zwrotu|za[lł][aą]cznik\w*\s+nr\s*2/iu',
        'complaints' => '/reklamac/iu',
        'delivery' => '/(?:koszt\w*|czas\w*|spos[oó]b\w*|metod\w*)\s+(?:dostaw|wysy[lł]k|p[lł]atno)|formy\s+p[lł]atno/iu',
        'accessibility' => '/dost[eę]pno[sś]\w*\s+(?:cyfrow|serwis|sklep|stron|usług)|wymaga\w*\s+dost[eę]pno|deklaracj\w*\s+dost[eę]pno/iu',
    ];

    private const RODO_INFO = '/administrator\w*|\brodo\b|polityk\w*\s+prywatno|przetwarza\w*|dan\w*\s+osobow|privacy|personal\s+data|gdpr|data\s+protection|controller/iu';

    private array $findings = [];

    private int $requests = 0;

    /**
     * @param  array<string, array>  $pages  podstrony z PageInspector (z surowym kodem)
     * @return array{findings: list<array>, shop: bool, platform: ?string, banner: ?string, cmp: list<string>, cmp_auto: bool, consent_mode: bool, tools: list<array>, documents: list<array>, promo: ?array}
     */
    public function inspect(array $pages, string $siteHost): array
    {
        $this->findings = [];
        $this->requests = 0;
        $links = $this->links($pages);
        $plain = array_map(fn ($p) => $this->plain($p['raw']), $pages);
        $allPlain = implode("\n", $plain);
        $code = $this->codeOnly(implode("\n", array_column($pages, 'raw')));

        [$shop, $platform] = $this->detectShop($code, $links, $allPlain);
        // strona po polsku: lang="pl…" albo (bez lang) polskie znaki w treści – od tego zależą sprawdzenia polskiego tekstu i prawa
        $lang = strtolower((string) ($pages['home']['lang'] ?? ''));
        $polish = $lang !== '' ? str_starts_with($lang, 'pl') : preg_match_all('/[ąęśćżźńół]/iu', mb_substr($plain['home'] ?? $allPlain, 0, 20000)) > 30;

        // --- dokumenty: linki, a najważniejsze także pobrane (treść polityki i regulaminu)
        $docs = [];
        foreach (self::DOCS as $key => [$label, $re]) {
            $docs[$key] = ['key' => $key, 'label' => $label, 'url' => $this->findLink($links, $re, $key), 'status' => 'missing', 'http' => null, 'text' => ''];
        }
        $fetched = [];
        foreach (['privacy', 'terms', 'returns'] as $key) {
            $url = $docs[$key]['url'];
            if (! $url || preg_match('/\.(pdf|docx?)$/i', (string) parse_url($url, PHP_URL_PATH)) || ($key === 'returns' && ! $shop)) {
                continue;
            }
            $fetched[$url] ??= $this->get($url);
            $res = $fetched[$url];
            $docs[$key]['http'] = $res['status'];
            $docs[$key]['status'] = $res['blocked'] || $res['status'] === 0 ? 'unknown' : ($res['status'] >= 400 ? 'broken' : 'ok');
            $docs[$key]['text'] = $res['html'] !== '' ? $this->plain($res['html']) : '';
        }
        foreach ($docs as &$d) {
            if ($d['url'] && $d['status'] === 'missing') {
                $d['status'] = 'link';
            }
        }
        unset($d);
        $docText = implode("\n", array_column($docs, 'text'));
        if ($docs['cookies']['status'] === 'missing' && preg_match('/cookie|ciasteczk/iu', $docs['privacy']['text'])) {
            $docs['cookies']['status'] = 'in_text'; // rozdział o cookies w polityce prywatności
        }
        foreach (self::IN_TEXT as $key => $re) {
            if ($docs[$key]['status'] === 'missing' && preg_match($re, $docText."\n".$allPlain)) {
                $docs[$key]['status'] = 'in_text';
            }
        }

        // --- kody śledzące i zgody
        $tracking = $this->tracking($pages, $code);
        $this->consentFindings($tracking, $pages);

        // --- polityka prywatności, formularze, dane firmy
        $forms = $this->personalForms($pages);
        $this->privacyFindings($docs, $tracking, $forms, $allPlain, $polish);
        $this->formFindings($forms);
        if ($polish) { // NIP / KRS i wymogi polskiego prawa konsumenckiego – tylko dla stron po polsku
            $this->companyFindings($shop, $allPlain."\n".$docText);
        }

        // --- sklep
        $promo = null;
        if ($shop && $polish) {
            $this->shopFindings($docs, $platform);
            $promo = $this->omnibus($pages, $links, $allPlain."\n".$docText);
            $this->eaa($docs, $allPlain."\n".$docText);
        }

        return [
            'findings' => $this->findings,
            'shop' => $shop,
            'platform' => $platform,
            'banner' => $tracking['banner'],
            'cmp' => $tracking['cmp'],
            'cmp_auto' => $tracking['cmp_auto'],
            'consent_mode' => $tracking['consent_default'],
            'tools' => array_map(fn ($t) => array_intersect_key($t, array_flip(['name', 'ids', 'source', 'load', 'ok'])), $tracking['tools']),
            'documents' => array_values(array_map(fn ($d) => ['key' => $d['key'], 'label' => $d['label'], 'url' => $d['url'], 'status' => $d['status'], 'http' => $d['http'],
                'required' => $this->required($d['key'], $shop, $tracking, $forms)], array_filter($docs, fn ($d) => $shop || in_array($d['key'], ['privacy', 'cookies', 'terms'], true)))),
            'promo' => $promo,
        ];
    }

    // ------------------------------------------------------------------ kody śledzące

    private function tracking(array $pages, string $code): array
    {
        $cmp = [];
        $auto = false;
        foreach (self::CMPS as $name => [$re, $autoRe]) {
            if (preg_match($re, $code)) {
                $cmp[] = $name;
                $auto = $auto || ($autoRe && preg_match($autoRe, $code));
            }
        }

        // skrypty na podstronach
        $scripts = [];
        foreach ($pages as $role => $p) {
            preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $p['raw'], $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($m as $s) {
                $scripts[] = ['role' => $role, 'attrs' => $s[1][0], 'body' => $s[2][0], 'offset' => $s[0][1], 'tag' => $s[0][0], 'exec' => $this->executable($s[1][0])];
            }
        }

        // Consent Mode: domyślna odmowa ustawiona przed tagami Google
        $consentDefault = false;
        foreach ($scripts as $s) {
            if ($s['exec'] && preg_match('/[\'"]consent[\'"]\s*,\s*[\'"]default[\'"]\s*,\s*\{([^}]*)\}/', $s['body'], $cm) && preg_match('/(?:analytics|ad)_storage[\'"]?\s*:\s*[\'"]denied/', $cm[1])) {
                $consentDefault = true;
            }
        }

        $tools = [];
        foreach (self::TOOLS as $name => [$re, $idRe, $google]) {
            $hits = array_values(array_filter($scripts, fn ($s) => preg_match($re, $s['attrs'].' '.$s['body'])));
            if (! $hits) {
                continue;
            }
            $ids = [];
            foreach ($hits as $h) {
                if ($idRe && preg_match_all($idRe, $h['attrs'].' '.$h['body'], $im)) {
                    array_push($ids, ...($im[1] ?? $im[0]));
                }
            }
            $exec = array_values(array_filter($hits, fn ($h) => $h['exec']));
            $gated = $exec && ! array_filter($exec, fn ($h) => ! preg_match(self::GATED, $h['body']));
            [$load, $ok] = match (true) {
                ! $exec => ['zablokowany do czasu zgody', true],
                $auto => ['blokuje baner '.implode(', ', $cmp).' (tryb automatyczny)', true],
                $gated => ['w kodzie jest warunek zgody – do sprawdzenia', null],
                $google && $consentDefault => ['Consent Mode: bez ciasteczek do czasu zgody', true],
                $name === 'Google Tag Manager' && $cmp => ['zależy od ustawień tagów w GTM', null],
                default => ['uruchamia się od razu, przed zgodą', false],
            };
            $tools[$name] = ['name' => $name, 'ids' => array_values(array_unique($ids)), 'source' => 'kod strony', 'load' => $load, 'ok' => $ok, 'pages' => array_values(array_unique(array_column($hits, 'role'))),
                'evidence' => $exec ? mb_strimwidth(preg_replace('/\s+/', ' ', $exec[0]['tag']), 0, 220, '…') : null];
        }

        // Meta Pixel z wtyczki PixelYourSite (skrypt piksela dokładany później przez wtyczkę)
        if (! isset($tools['Meta Pixel (Facebook)']) && preg_match('/pixelyoursite|pys_opts|"pixelIds"\s*:\s*\[\s*"(\d{10,20})/i', $code, $pm)) {
            $tools['Meta Pixel (Facebook)'] = ['name' => 'Meta Pixel (Facebook)', 'ids' => isset($pm[1]) ? [$pm[1]] : [], 'source' => 'wtyczka PixelYourSite',
                'load' => $cmp ? 'zależy od ustawień wtyczki i banera' : 'uruchamia się od razu, przed zgodą', 'ok' => $cmp ? null : false, 'pages' => [], 'evidence' => null];
        }

        // Narzędzia dodane w kontenerach GTM (gtm.js jest publiczny)
        $gtm = $tools['Google Tag Manager'] ?? null;
        $cmpInGtm = [];
        foreach (array_slice($gtm['ids'] ?? [], 0, 2) as $id) {
            $js = $this->gtmContainer($id);
            if ($js === null) {
                continue;
            }
            foreach (self::CMPS as $name => [$re]) {
                if (preg_match($re, $js)) {
                    $cmpInGtm[] = $name;
                }
            }
            foreach (self::GTM_TOOLS as $name => $re) {
                if (isset($tools[$name]) || ! preg_match($re, $js)) {
                    continue;
                }
                $ids = [];
                if (in_array($name, ['Google Analytics 4', 'Google Ads'], true) && preg_match_all(self::TOOLS[$name][1], $js, $im)) {
                    $ids = array_values(array_unique($im[0]));
                }
                $tools[$name] = ['name' => $name, 'ids' => $ids, 'source' => 'GTM ('.$id.')', 'pages' => $gtm['pages'], 'evidence' => null] + match (true) {
                    $gtm['ok'] === true => ['load' => $gtm['load'], 'ok' => true],
                    (bool) ($cmp || $cmpInGtm) => ['load' => 'zależy od ustawień tagów w GTM', 'ok' => null],
                    default => ['load' => 'uruchamia się od razu, przed zgodą', 'ok' => false],
                };
            }
        }
        if ($cmpInGtm) {
            $cmp = array_values(array_unique(array_merge($cmp, array_map(fn ($n) => $n.' (przez GTM)', $cmpInGtm))));
            if ($gtm && $gtm['ok'] === false) { // baner ładowany przez GTM – ocena tagów zależy od ustawień kontenera
                $tools['Google Tag Manager']['load'] = 'zależy od ustawień tagów w GTM';
                $tools['Google Tag Manager']['ok'] = null;
            }
        }

        // Baner: znana platforma, własny baner albo sam pasek informacyjny
        $homePlain = $this->plain($pages['home']['raw'] ?? '');
        $infoSentence = preg_match('/(?:używa|korzysta\w*\s+z|wykorzystuje|stosuje)\s+(?:plik\w*\s+)?(?:cookie|ciasteczek)/iu', $homePlain);
        $choice = preg_match('/odrzu[cć]|ustawieni\w*\s+(?:plik\w*\s+)?(?:cookie|prywatno|ciasteczek)|zarządzaj|dostosuj|preferencj|tylko\s+niezbędne|wyłącznie\s+niezbędne|reject|decline/iu', $homePlain);
        $ownBanner = preg_match('/(?:id|class)\s*=\s*["\'][^"\']*(?:cookie[-_]?(?:banner|consent|notice|bar|popup|law|info|box|alert)|cookies?[-_](?:banner|consent|notice|bar|popup|box))/i', $code);
        $banner = match (true) {
            (bool) $cmp => 'cmp',
            $infoSentence && ! $choice => 'info',
            $infoSentence && $choice => 'own',
            (bool) $ownBanner => 'own',
            default => null,
        };

        return ['tools' => array_values($tools), 'cmp' => $cmp, 'cmp_auto' => (bool) $auto, 'consent_default' => $consentDefault, 'banner' => $banner];
    }

    private function consentFindings(array $t, array $pages): void
    {
        $tools = $t['tools'];
        $bad = array_values(array_filter($tools, fn ($x) => $x['ok'] === false));
        $unknown = array_values(array_filter($tools, fn ($x) => $x['ok'] === null));
        $list = fn (array $xs) => implode('; ', array_map(fn ($x) => $x['name'].($x['ids'] ? ' ('.implode(', ', array_slice($x['ids'], 0, 3)).')' : '').' – '.$x['source'], $xs));
        $evidence = implode("\n", array_filter(array_map(fn ($x) => $x['evidence'], array_slice($bad, 0, 3))));
        $fix = 'Wdrożyć baner zgód z przyciskami „Akceptuję”, „Odrzucam” i „Ustawienia” (np. Cookiebot, CookieYes, Complianz), który blokuje narzędzia do czasu zgody, '
            .'i włączyć Google Consent Mode v2 (domyślnie „denied”). Skrypty dodane w kodzie przenieść do GTM albo oznaczyć jako blokowane przez baner.';

        if ($bad) {
            match ($t['banner']) {
                null => $this->add(self::G_CONSENT, 'pilne', 'Kody śledzące działają bez zgody – na stronie nie ma banera cookies',
                    'Na stronie działają: '.$list($bad).'. Nie znaleźliśmy banera, w którym odwiedzający mógłby wyrazić zgodę albo odmówić, więc narzędzia zapisują ciasteczka i wysyłają dane od pierwszego wejścia. '
                    .self::LAW_COOKIES.' W obecnej postaci strona nie spełnia tych wymogów – to ryzyko skargi do UODO.', $fix, $evidence ?: null),
                'info' => $this->add(self::G_CONSENT, 'pilne', 'Baner cookies tylko informuje – kody śledzące działają bez zgody',
                    'Na stronie jest komunikat o plikach cookies, ale bez możliwości odmowy (brak „Odrzucam” / „Ustawienia”), a jednocześnie działają: '.$list($bad).'. '
                    .'Komunikat „strona używa cookies” nie jest zgodą. '.self::LAW_COOKIES, $fix, $evidence ?: null, [], true),
                default => $this->add(self::G_CONSENT, 'pilne', 'Kody śledzące uruchamiają się przed wyrażeniem zgody',
                    'Strona ma baner zgód ('.($t['cmp'] ? implode(', ', $t['cmp']) : 'własny').'), ale te narzędzia są wstawione w kod jako zwykłe skrypty i startują od razu – zanim odwiedzający kliknie cokolwiek w banerze: '
                    .$list($bad).'. '.self::LAW_COOKIES,
                    'Podpiąć skrypty pod baner: przenieść je do GTM z wyzwalaczem po zgodzie / Consent Mode albo oznaczyć jako blokowane (np. type="text/plain" z kategorią zgody), włączyć Consent Mode v2.',
                    $evidence ?: null, [], $t['banner'] === 'own' || (bool) array_filter($bad, fn ($x) => $x['source'] !== 'kod strony')),
            };
        } elseif ($tools && $t['banner'] === 'info') {
            $this->add(self::G_CONSENT, 'srednie', 'Baner cookies bez możliwości odmowy',
                'Komunikat o cookies nie daje wyboru (brak „Odrzucam” / „Ustawienia”). Nie widać, by narzędzia startowały przed zgodą, ale sam komunikat nie jest zgodą w rozumieniu przepisów.',
                'Zastąpić komunikat banerem zgód z przyciskami „Akceptuję” i „Odrzucam” na tym samym poziomie.', null, [], true);
        }
        if ($unknown && ! $bad) {
            $this->add(self::G_CONSENT, 'drobne', 'Do sprawdzenia: czy narzędzia z Tag Managera czekają na zgodę',
                'Strona ma baner zgód, a narzędzia są uruchamiane przez Tag Managera lub wtyczkę: '.$list($unknown).'. Z kodu strony nie da się przesądzić, czy ich tagi czekają na zgodę – zależy to od ustawień kontenera (Consent Mode, wyzwalacze).',
                'W trybie incognito, przed kliknięciem w banerze, sprawdzić w Tag Assistant (lub w narzędziach przeglądarki → Sieć), czy nie wysyłają się zapytania do google-analytics.com, facebook.com itp.', null, [], true);
        }
        // Ciasteczka narzędzi ustawiane przez serwer przy pierwszym wejściu
        $serverCookies = array_values(array_unique(array_filter(array_merge(...array_values(array_map(fn ($p) => $p['cookies'] ?? [], $pages)) ?: [[]]), fn ($c) => preg_match(self::TRACKING_COOKIES, $c))));
        if ($serverCookies) {
            $this->add(self::G_CONSENT, 'pilne', 'Serwer zapisuje ciasteczka śledzące przy pierwszym wejściu',
                'Już pierwsza odpowiedź serwera ustawia ciasteczka narzędzi analitycznych/reklamowych: '.implode(', ', $serverCookies).'. Dzieje się to przed jakąkolwiek decyzją w banerze. '.self::LAW_COOKIES,
                'Wyłączyć ustawianie tych ciasteczek po stronie serwera (pomiar po stronie serwera / wtyczka) do czasu zgody.', 'Set-Cookie: '.implode(', ', $serverCookies));
        }

        // Piksel Facebooka w <noscript>
        foreach ($pages as $p) {
            if (preg_match('#<noscript[^>]*>(?:(?!</noscript>).)*facebook\.com/tr\?(?:(?!</noscript>).)*</noscript>#is', $p['raw'])) {
                $this->add(self::G_CONSENT, 'srednie', 'Piksel Facebooka w wersji bez JavaScript ładuje się bez zgody',
                    'Obrazek facebook.com/tr jest wstawiony bezpośrednio w kod strony (w znaczniku noscript). W tej wersji ładuje się bez względu na decyzję w banerze cookies.',
                    'Usunąć wersję noscript piksela albo warunkować ją zgodą (ustawienia wtyczki piksela / GTM).', '<noscript><img src="https://www.facebook.com/tr?id=…">', [], true);

                break;
            }
        }

        // Stary kod Universal Analytics
        if ($ua = array_values(array_filter($tools, fn ($x) => $x['name'] === 'Universal Analytics (stary kod)'))) {
            $this->add(self::G_CONSENT, 'drobne', 'Na stronie został stary kod Universal Analytics',
                'Google wyłączył Universal Analytics w 2023 r. – kod'.($ua[0]['ids'] ? ' '.implode(', ', $ua[0]['ids']) : '').' nie zbiera już danych, a nadal się ładuje (spowalnia stronę i może zapisywać ciasteczka).',
                'Usunąć stary kod (ga.js / analytics.js / UA-…); pomiar prowadzić w Google Analytics 4.', $ua[0]['evidence']);
        }

        // Osadzone filmy i mapy ładują treści (i ciasteczka) Google przed zgodą
        $embeds = [];
        foreach ($pages as $p) {
            if (preg_match_all('#<iframe\b[^>]*\ssrc\s*=\s*["\']([^"\']+)#i', $p['raw'], $m)) {
                foreach ($m[1] as $src) {
                    if (preg_match('#youtube\.com/embed#i', $src)) {
                        $embeds['YouTube (zwykły tryb, nie youtube-nocookie.com)'] = $src;
                    } elseif (preg_match('#google\.[a-z.]+/maps|maps\.google\.#i', $src)) {
                        $embeds['Mapy Google'] = $src;
                    } elseif (preg_match('#facebook\.com/plugins|instagram\.com/.+/embed|tiktok\.com/embed#i', $src)) {
                        $embeds['wtyczki Facebooka / Instagrama / TikToka'] = $src;
                    }
                }
            }
        }
        if ($embeds) {
            $this->add(self::G_CONSENT, 'drobne', 'Osadzone '.implode(', ', array_keys($embeds)).' ładują się przed zgodą',
                'Osadzone okienka zewnętrznych serwisów wczytują się od razu i mogą zapisywać ich ciasteczka, zanim odwiedzający podejmie decyzję w banerze.',
                'Filmy YouTube osadzać w trybie youtube-nocookie.com, a mapy i wtyczki społecznościowe pokazywać po zgodzie (większość banerów ma „zaślepkę” dla takich treści).',
                implode("\n", array_map(fn ($u) => mb_strimwidth($u, 0, 120, '…'), $embeds)), [], true);
        }

        // Czcionki Google pobierane z serwerów Google
        if (array_filter($pages, fn ($p) => preg_match('#fonts\.googleapis\.com|fonts\.gstatic\.com#i', $p['raw']))) {
            $this->add(self::G_CONSENT, 'drobne', 'Czcionki Google pobierane z serwerów Google',
                'Przy każdym wejściu przeglądarka pobiera czcionki z fonts.googleapis.com, przekazując Google adres IP odwiedzającego bez jego wiedzy. W Niemczech sądy uznały to za naruszenie RODO; w Polsce to rzadko podnoszone ryzyko, ale łatwe do usunięcia (i przyspiesza stronę).',
                'Wgrać czcionki na własny serwer (np. wtyczka OMGF albo opcja „lokalne czcionki” w motywie / Elementorze).');
        }
    }

    // ------------------------------------------------------------------ polityka prywatności, formularze, dane firmy

    private function privacyFindings(array $docs, array $t, array $forms, string $allPlain, bool $polish = true): void
    {
        $privacy = $docs['privacy'];
        $collects = $forms || $t['tools'];
        if ($privacy['status'] === 'missing') {
            $mentioned = (bool) preg_match('/polityk\w*\s+prywatno/iu', $allPlain);
            $this->add(self::G_PRIVACY, $collects ? 'pilne' : 'srednie', 'Brak polityki prywatności',
                'Na sprawdzonych podstronach nie ma linku do polityki prywatności'.($collects ? ', a strona zbiera dane ('.implode(', ', array_filter([$forms ? 'formularze' : null, $t['tools'] ? 'narzędzia analityczne/reklamowe' : null])).')' : '').'. '
                .'Każdy, kto zbiera dane osobowe, musi poinformować, kto jest administratorem, w jakim celu i jak długo je przetwarza oraz jakie prawa przysługują osobie (art. 13 RODO).',
                'Przygotować politykę prywatności (z informacją o cookies i używanych narzędziach) i podlinkować ją w stopce oraz przy formularzach.', null, [], $mentioned);

            return;
        }
        if ($privacy['status'] === 'broken') {
            $this->add(self::G_PRIVACY, 'pilne', 'Link do polityki prywatności prowadzi do błędu '.$privacy['http'],
                'Polityka prywatności jest podlinkowana, ale pod adresem jest strona błędu – formalnie odwiedzający nie ma dostępu do obowiązkowej informacji (art. 13 RODO).',
                'Przywrócić stronę polityki prywatności albo poprawić link.', $privacy['url'], [$privacy['url']]);

            return;
        }
        $text = $privacy['text'];
        if ($text === '') {
            return; // PDF albo nie udało się pobrać – nie oceniamy treści
        }
        $url = [$privacy['url']];
        $missing = [];
        foreach ($t['tools'] as $tool) {
            $re = self::MENTIONS[$tool['name']] ?? null;
            if ($re && ! preg_match($re, $text)) {
                $missing[] = $tool['name'];
            }
        }
        if ($missing) {
            $this->add(self::G_PRIVACY, 'srednie', 'Polityka prywatności nie opisuje narzędzi używanych na stronie',
                'Na stronie działają '.implode(', ', $missing).', a polityka prywatności o nich nie wspomina. Odwiedzający musi wiedzieć, komu przekazywane są jego dane (odbiorcy danych, przekazanie poza EOG – art. 13 RODO).',
                'Dopisać do polityki prywatności opis każdego narzędzia: cel, dostawcę, okres przechowywania i sposób wycofania zgody.', null, $url);
        }
        if (! preg_match('/cookie|ciasteczk/iu', $text) && in_array($docs['cookies']['status'], ['missing'], true)) {
            $this->add(self::G_PRIVACY, 'srednie', 'Brak informacji o plikach cookies',
                'Ani polityka prywatności, ani osobny dokument nie opisuje plików cookies – jakie są zapisywane, w jakim celu i jak zmienić zgodę.',
                'Dodać do polityki prywatności rozdział o cookies (lub osobną politykę cookies) i link „Ustawienia cookies” w stopce.', null, $url);
        }
        if (preg_match('/\bGIODO\b|Generaln\w+\s+Inspektor\w*\s+Ochrony|ustaw\w*\s+z\s+(?:dnia\s+)?29\s+sierpnia\s+1997/iu', $text, $m)) {
            $this->add(self::G_PRIVACY, 'srednie', 'Polityka prywatności powołuje się na nieaktualne przepisy',
                'W treści jest „'.$m[0].'” – to przepisy sprzed RODO (od 2018 r. organem jest Prezes UODO). Dokument prawdopodobnie nie był aktualizowany od lat i może nie spełniać obecnych wymogów.',
                'Zaktualizować politykę prywatności do RODO (podstawy prawne, prawa osób, skarga do Prezesa UODO).', $m[0], $url);
        } elseif (preg_match('/art\.?\s*173\s+(?:ustawy\s+)?(?:–\s*)?Prawa?\s+telekomunikacyjne\w*|art\.?\s*173\s+ustawy\s+z\s+(?:dnia\s+)?16\s+lipca\s+2004/iu', $text, $m)) {
            $this->add(self::G_PRIVACY, 'drobne', 'Odwołanie do uchylonego przepisu o cookies',
                'Polityka powołuje się na „'.$m[0].'”. Od 10 listopada 2024 r. zasady cookies reguluje art. 399 Prawa komunikacji elektronicznej.',
                'Zamienić odwołanie na art. 399 ustawy – Prawo komunikacji elektronicznej.', $m[0], $url);
        }
        if (! $polish) {
            return; // dalsze sprawdzenia czytają polski tekst dokumentu
        }
        if (! preg_match('/administrator/iu', $text)) {
            $this->add(self::G_PRIVACY, 'srednie', 'Polityka prywatności nie wskazuje administratora danych',
                'W treści polityki nie ma słowa „administrator” – nie wiadomo, kto odpowiada za dane (nazwa firmy, adres, kontakt). To podstawowy element obowiązku informacyjnego (art. 13 RODO).',
                'Dopisać na początku: administrator danych (pełna nazwa, adres, NIP) i kontakt w sprawie danych.', null, $url, true);
        }
    }

    /** Formularze zbierające dane osobowe (bez wyszukiwarek), zgrupowane – ten sam formularz na kilku podstronach liczy się raz. */
    private function personalForms(array $pages): array
    {
        $out = [];
        foreach ($pages as $role => $p) {
            foreach ($p['forms'] ?? [] as $f) {
                if (! empty($f['search'])) {
                    continue;
                }
                $inputs = array_filter($f['fields'], fn ($x) => ! in_array($x['type'], ['checkbox', 'radio', 'hidden'], true));
                $personal = array_filter($inputs, fn ($x) => in_array($x['type'], ['email', 'tel'], true) || preg_match('/mail|tel|phone|imi|name|nazw/iu', $x['name'].' '.$x['placeholder'].' '.$x['label']));
                if (! $personal) {
                    continue;
                }
                $sig = implode('|', array_map(fn ($x) => $x['type'].':'.$x['name'], $f['fields']));
                $out[$sig] ??= $f + ['roles' => [], 'urls' => []];
                $out[$sig]['roles'][] = $p['label'] ?? PageInspector::ROLES[$role] ?? $role;
                $out[$sig]['urls'][] = $p['final_url'] ?? $p['url'];
            }
        }

        return array_values($out);
    }

    private function formFindings(array $forms): void
    {
        $noInfo = $prechecked = $newsletter = [];
        foreach ($forms as $f) {
            $context = ($f['text'] ?? '').' '.implode(' ', $f['links'] ?? []).' '.implode(' ', array_map(fn ($x) => (string) $x['label'], $f['fields']));
            $checkboxes = array_filter($f['fields'], fn ($x) => $x['type'] === 'checkbox');
            if (! preg_match(self::RODO_INFO, $context)) {
                $noInfo[] = $f;
            }
            foreach ($checkboxes as $c) {
                if ($c['checked'] ?? false) {
                    $prechecked[] = ['form' => $f, 'label' => (string) $c['label']];
                }
            }
            $inputs = array_filter($f['fields'], fn ($x) => ! in_array($x['type'], ['checkbox', 'radio', 'hidden'], true));
            $isNewsletter = preg_match('/newsletter|subskryb|zapisz\s+si[eę]\s+(?:na|do)/iu', $context) || (count($inputs) === 1 && reset($inputs)['type'] === 'email');
            if ($isNewsletter && ! array_filter($checkboxes, fn ($c) => preg_match('/zgod|wyra[zż]am|handlow|marketing|newsletter|akceptuj/iu', (string) $c['label']))) {
                $newsletter[] = $f;
            }
        }
        $where = fn (array $fs) => implode(', ', array_unique(array_merge(...array_map(fn ($f) => $f['roles'], $fs))));
        $urls = fn (array $fs) => array_values(array_unique(array_merge(...array_map(fn ($f) => $f['urls'], $fs))));
        if ($noInfo) {
            $fields = implode(', ', array_unique(array_filter(array_map(fn ($x) => $x['label'] ?: $x['placeholder'] ?: $x['name'], array_filter($noInfo[0]['fields'], fn ($x) => ! in_array($x['type'], ['checkbox', 'radio'], true))))));
            $this->add(self::G_PRIVACY, 'srednie', 'Formularz bez informacji o przetwarzaniu danych osobowych',
                'Formularz ('.$where($noInfo).') zbiera dane'.($fields ? ' („'.mb_strimwidth($fields, 0, 120, '…').'”)' : '').', ale przy nim nie ma informacji, kto jest administratorem danych i po co je przetwarza, ani linku do polityki prywatności. '
                .'Obowiązek informacyjny trzeba spełnić w chwili zbierania danych (art. 13 RODO).',
                'Pod przyciskiem wysyłki dodać krótką klauzulę: „Administratorem danych jest … Dane przetwarzamy, aby odpowiedzieć na wiadomość. Więcej: Polityka prywatności” (z linkiem).', null, $urls($noInfo));
        }
        if ($prechecked) {
            $this->add(self::G_PRIVACY, 'pilne', 'Zgoda w formularzu zaznaczona z góry',
                'Pole zgody jest domyślnie zaznaczone: „'.mb_strimwidth($prechecked[0]['label'], 0, 160, '…').'”. Zgoda musi być wyrażona aktywnie – zaznaczone z góry pole nie jest ważną zgodą (RODO, wyrok TSUE C-673/17 Planet49).',
                'Usunąć domyślne zaznaczenie pola zgody w ustawieniach formularza.', mb_strimwidth($prechecked[0]['label'], 0, 200, '…'), $prechecked[0]['form']['urls']);
        }
        if ($newsletter) {
            $this->add(self::G_PRIVACY, 'srednie', 'Zapis na newsletter bez zgody na informacje handlowe',
                'Formularz zapisu ('.$where($newsletter).') nie ma pola zgody na otrzymywanie informacji handlowych e-mailem. Wysyłanie newslettera z ofertą wymaga uprzedniej zgody odbiorcy, którą trzeba umieć wykazać.',
                'Dodać niezaznaczone pole zgody na informacje handlowe (z linkiem do polityki prywatności) i potwierdzenie zapisu e-mailem (double opt-in).', null, $urls($newsletter), true);
        }
    }

    private function companyFindings(bool $shop, string $text): void
    {
        $nip = preg_match('/\bNIP\b[\s:.\-]*(?:PL\s*)?\d{3}[\s-]?\d{2,3}[\s-]?\d{2}[\s-]?\d{2,3}/iu', $text);
        $register = preg_match('/\bKRS\b[\s:.\-]*\d{10}|\bCEIDG\b|\bREGON\b[\s:.\-]*\d{9}/iu', $text);
        if (! $nip && ! $register) {
            $this->add(self::G_COMPANY, $shop ? 'srednie' : 'drobne', 'Na stronie nie ma NIP ani numeru w rejestrze firmy',
                'Na sprawdzonych podstronach'.($shop ? ' i w dokumentach' : '').' nie znaleźliśmy NIP, KRS ani REGON. Firma świadcząca usługi przez internet powinna podać pełną nazwę, adres i numery rejestrowe (art. 5 ustawy o świadczeniu usług drogą elektroniczną)'
                .($shop ? ', a sklep – przed zawarciem umowy z konsumentem także dane do kontaktu i reklamacji (art. 12 ustawy o prawach konsumenta).' : '.').' To też kwestia zaufania – klienci sprawdzają, kto stoi za stroną.',
                'Dodać w stopce i na stronie kontaktu: pełną nazwę firmy, adres, NIP oraz KRS albo informację o wpisie do CEIDG.', null, [], ! $shop);
        }
    }

    // ------------------------------------------------------------------ sklep

    private function shopFindings(array $docs, ?string $platform): void
    {
        $terms = $docs['terms'];
        $shopName = $platform ? 'Sklep ('.$platform.')' : 'Sklep internetowy';
        if ($terms['status'] === 'missing') {
            $this->add(self::G_SHOP, 'pilne', 'Brak regulaminu sklepu',
                $shopName.' musi udostępnić regulamin przed zawarciem umowy – w sposób umożliwiający jego pobranie i zapisanie (art. 8 ustawy o świadczeniu usług drogą elektroniczną). Na sprawdzonych podstronach nie ma do niego linku.',
                'Przygotować regulamin sklepu (zamówienia, płatności, dostawa, odstąpienie, reklamacje) i podlinkować go w stopce oraz w koszyku.');
        } elseif ($terms['status'] === 'broken') {
            $this->add(self::G_SHOP, 'pilne', 'Link do regulaminu prowadzi do błędu '.$terms['http'],
                'Regulamin jest podlinkowany, ale strona pod tym adresem nie działa – klient nie może się z nim zapoznać przed zakupem.', 'Przywrócić stronę regulaminu albo poprawić link.', $terms['url'], [$terms['url']]);
        }

        $missing = fn (string $k) => $docs[$k]['status'] === 'missing';
        if ($missing('returns')) {
            $this->add(self::G_SHOP, 'pilne', 'Brak informacji o prawie odstąpienia od umowy (14 dni)',
                'Nie znaleźliśmy strony o zwrotach ani zapisów o odstąpieniu od umowy. Konsument kupujący przez internet może odstąpić od umowy w ciągu 14 dni bez podania przyczyny, a sklep musi go o tym poinformować przed zakupem (art. 12 i 27 ustawy o prawach konsumenta). '
                .'Gdy tej informacji brakuje, termin na zwrot wydłuża się nawet do 12 miesięcy.',
                'Dodać stronę „Zwroty i odstąpienie od umowy” (termin, sposób, adres zwrotu, koszty odesłania, termin zwrotu pieniędzy) i rozdział w regulaminie.');
        }
        if ($missing('withdrawal_form')) {
            $this->add(self::G_SHOP, 'pilne', 'Brak wzoru formularza odstąpienia od umowy',
                'Sklep musi udostępnić konsumentowi wzór formularza odstąpienia od umowy (załącznik nr 2 do ustawy o prawach konsumenta, art. 12 ust. 1 pkt 9). Na stronie i w dokumentach nie znaleźliśmy ani formularza, ani linku do niego.',
                'Dodać formularz do pobrania (PDF/DOCX) na stronie zwrotów i w regulaminie oraz wysyłać go w potwierdzeniu zamówienia.');
        }
        if ($missing('complaints')) {
            $this->add(self::G_SHOP, 'srednie', 'Brak informacji o reklamacjach',
                'Nie widać, jak złożyć reklamację (adres, termin odpowiedzi – 14 dni, prawa konsumenta przy niezgodności towaru z umową). To element obowiązkowej informacji przed zawarciem umowy.',
                'Dodać rozdział o reklamacjach w regulaminie i krótką stronę „Reklamacje”.');
        }
        if ($missing('delivery')) {
            $this->add(self::G_SHOP, 'srednie', 'Brak informacji o dostawie i płatnościach',
                'Nie znaleźliśmy strony ani zapisów o kosztach i czasie dostawy oraz formach płatności. Klient musi je znać przed złożeniem zamówienia (art. 12 ustawy o prawach konsumenta) – ich brak to też częsty powód porzucania koszyka.',
                'Dodać stronę „Dostawa i płatności” z kosztami, czasem realizacji i metodami płatności, podlinkowaną w stopce i w koszyku.');
        }

        $text = $terms['text'];
        if ($text !== '') {
            if (preg_match('/r[eę]kojmi/iu', $text) && ! preg_match('/niezgodno\w*\s+(?:towaru|treści|usług\w*|z\s+umow)/iu', $text)) {
                $this->add(self::G_SHOP, 'srednie', 'Regulamin opiera reklamacje na rękojmi sprzed 2023 r.',
                    'Regulamin mówi o rękojmi, a nie o „niezgodności towaru z umową”. Od 1 stycznia 2023 r. prawa konsumenta przy wadliwym towarze reguluje ustawa o prawach konsumenta (rozdział 5a) – zapisy oparte na rękojmi są nieaktualne i mogą wprowadzać klienta w błąd.',
                    'Zaktualizować rozdział o reklamacjach do przepisów o zgodności towaru z umową.', null, [$terms['url']], true);
            }
            // „14 dni” musi dotyczyć odstąpienia (to samo zdanie / akapit), a nie np. terminu odpowiedzi na reklamację
            $days = '(?:14\s*dni|czternast\w*\s+dni|14-dniow\w*)';
            if (preg_match('/odst[aą]pi/iu', $text) && ! preg_match('/odst[aą]pi[^.\n]{0,300}?'.$days.'|'.$days.'[^.\n]{0,300}?odst[aą]pi/iu', $text)) {
                $this->add(self::G_SHOP, 'srednie', 'Regulamin nie podaje 14-dniowego terminu na odstąpienie od umowy',
                    'Regulamin wspomina o odstąpieniu od umowy, ale nie znaleźliśmy w nim terminu 14 dni. Termin musi być jasno podany.', 'Dopisać termin i sposób liczenia (od dnia otrzymania towaru).', null, [$terms['url']], true);
            }
            if (preg_match('/ec\.europa\.eu\/consumers\/odr|platform\w*\s+(?:internetowego\s+rozstrzygania\s+sporów|ODR)/iu', $text)) {
                $this->add(self::G_SHOP, 'drobne', 'Regulamin odsyła do zlikwidowanej platformy ODR',
                    'Unijna platforma ODR (ec.europa.eu/consumers/odr) została zamknięta 20 lipca 2025 r. – link i zapis o niej są już nieaktualne.',
                    'Usunąć zapis o platformie ODR; zostawić informację o pozasądowych sposobach rozwiązywania sporów (rzecznik konsumentów, inspekcja handlowa).', null, [$terms['url']]);
            }
        }
    }

    /** Omnibus: przy obniżce obok ceny musi być najniższa cena z 30 dni przed obniżką. */
    private function omnibus(array $pages, array $links, string $text): array
    {
        $checked = [];
        foreach ($pages as $p) {
            $checked[$p['final_url'] ?? $p['url']] = $p['raw'];
        }
        // karta produktu i strona promocji – jeśli nie było ich wśród sprawdzonych podstron
        $candidates = [];
        foreach ($links as $l) {
            $path = (string) parse_url($l['abs'], PHP_URL_PATH);
            if (! isset($candidates['product']) && preg_match('#/(?:produkt|product|products|p)/[^/]+/?$|/\d+-[\w-]+\.html$|/[\w-]+-p\d+\.html?$#i', $path)) {
                $candidates['product'] = $l['abs'];
            }
            if (! isset($candidates['promo']) && preg_match('/promocj|wyprzeda|\bsale\b|outlet|okazj|black[\s-]?friday/iu', $l['text'].' '.$path)) {
                $candidates['promo'] = $l['abs'];
            }
        }
        foreach ($candidates as $u) {
            if (! isset($checked[$u])) {
                $res = $this->get($u);
                if ($res['html'] !== '') {
                    $checked[$u] = $res['html'];
                }
            }
        }

        $promo = [];
        $lowest = [];
        foreach ($checked as $u => $html) {
            if (preg_match('~<(del|s|strike)\b[^>]*>(?:(?!</\1>).){0,600}?\d[\d\s.,]*(?:&nbsp;|\s|<[^>]+>)*(?:zł|pln|&#122;&#322;)~isu', $html, $m)
                || preg_match('#class=["\'][^"\']*(?:old-price|regular-price|price-old|price--old|compare-at|compare_at|was-price|price-before|price--compare|price-crossed|product-price-old|\bonsale\b|price--on-sale)#i', $html, $m)) {
                $promo[$u] = mb_strimwidth(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 120, '…');
                $lowest[$u] = (bool) preg_match('/najni[zż]sz\w*\s+cen/iu', $this->plain($html));
            }
        }
        $without = array_keys(array_filter($lowest, fn ($v) => ! $v));
        if ($without) {
            $this->add(self::G_OMNIBUS, 'pilne', 'Promocje bez informacji o najniższej cenie z 30 dni (Omnibus)',
                'Na '.(count($without) === 1 ? 'stronie' : count($without).' stronach').' widać obniżone ceny (przekreślona cena / oznaczenie promocji), ale nie ma obok informacji o najniższej cenie z 30 dni przed obniżką. '
                .'Od 1 stycznia 2023 r. jest to obowiązkowe przy każdej obniżce (art. 4 ust. 2 ustawy o informowaniu o cenach towarów i usług) – UOKiK i inspekcja handlowa nakładają za to kary.',
                'Włączyć wyświetlanie „Najniższa cena z 30 dni przed obniżką” przy każdej promocyjnej cenie (WooCommerce, PrestaShop, Shoper i IdoSell mają do tego wtyczki lub ustawienia).',
                implode("\n", array_map(fn ($u) => $u.($promo[$u] !== '' ? ' – '.$promo[$u] : ''), array_slice($without, 0, 3))), array_slice($without, 0, 5));
        }

        // Opinie: Omnibus wymaga informacji, czy i jak sklep sprawdza, że opinie pochodzą od kupujących
        $reviews = (bool) array_filter($checked, fn ($h) => preg_match('/aggregateRating|woocommerce-Reviews|class=["\'][^"\']*(?:product-review|reviews-list|review-item|star-rating)/i', $h));
        if ($reviews && ! preg_match('/opini\w*(?:[^.]{0,250})weryfik|weryfik\w*(?:[^.]{0,250})opini|opinie\s+(?:są\s+)?(?:pochodz|wystawiane\s+wy[lł][aą]cznie)/iu', $text)) {
            $this->add(self::G_OMNIBUS, 'srednie', 'Brak informacji, czy opinie o produktach są weryfikowane',
                'Sklep pokazuje opinie/oceny produktów, ale nie znaleźliśmy informacji, czy i jak sprawdza, że pochodzą od osób, które kupiły produkt. Od 2023 r. taka informacja jest obowiązkowa (ustawa o przeciwdziałaniu nieuczciwym praktykom rynkowym).',
                'Dodać w regulaminie lub przy opiniach zdanie, czy opinie są weryfikowane i w jaki sposób (np. „opinie mogą wystawiać tylko osoby, które kupiły produkt – sprawdzamy to po numerze zamówienia”).', null, [], true);
        }

        return ['checked' => count($checked), 'found' => (bool) $promo, 'lowest' => $promo ? ! $without : null, 'url' => array_key_first($promo)];
    }

    private function eaa(array $docs, string $text): void
    {
        if ($docs['accessibility']['status'] !== 'missing') {
            return;
        }
        $this->add(self::G_EAA, 'srednie', 'Brak informacji o dostępności sklepu (Europejski Akt o Dostępności)',
            'Od 28 czerwca 2025 r. sklepy internetowe muszą spełniać wymagania dostępności (obsługa klawiaturą, czytniki ekranu, kontrast, opisy obrazków) i opublikować informację, jak je spełniają – np. w regulaminie lub na osobnej stronie. '
            .'Zwolnione są mikroprzedsiębiorstwa (poniżej 10 osób i do 2 mln euro obrotu rocznie). Na stronie nie znaleźliśmy takiej informacji.',
            'Jeśli firma nie jest mikroprzedsiębiorstwem: przeprowadzić audyt dostępności (WCAG 2.1 AA), poprawić sklep i opublikować informację o dostępności z danymi kontaktowymi.', null, [], true);
    }

    // ------------------------------------------------------------------ pomocnicze

    private function detectShop(string $code, array $links, string $plain): array
    {
        $platforms = [
            'Shopify' => '#cdn\.shopify\.com|Shopify\.theme#i',
            'WooCommerce' => '#/plugins/woocommerce/|wc-ajax=|woocommerce-no-js|wc_add_to_cart_params#i',
            'PrestaShop' => '#var prestashop|prestashop\.urls|/modules/ps_#i',
            'Magento' => '#Magento_|x-magento-init#i',
            'IdoSell' => '#iai-shop|iaicdn\.com#i',
            'Shoper' => '#content=["\']Shoper|shoper\.pl/|/libraries/shoper#i',
        ];
        foreach ($platforms as $name => $re) {
            if (preg_match($re, $code)) {
                return [true, $name];
            }
        }
        $cart = (bool) array_filter($links, fn ($l) => preg_match('#/(?:koszyk|cart|checkout|zamowienie|kasa)(?:/|$|\?)#i', (string) parse_url($l['abs'], PHP_URL_PATH)));
        $addToCart = (bool) preg_match('/dodaj\s+do\s+koszyka|add\s+to\s+cart|do\s+koszyka/iu', $plain);

        return [$cart && $addToCart, null];
    }

    private function required(string $key, bool $shop, array $t, array $forms): bool
    {
        return match ($key) {
            'privacy' => true,
            'cookies' => (bool) $t['tools'],
            'terms', 'returns', 'withdrawal_form', 'complaints', 'delivery' => $shop,
            'accessibility' => false, // zależy od wielkości firmy
            default => false,
        };
    }

    /** Wszystkie linki z podstron (bez duplikatów), najpierw ze stopki – tam zwykle są dokumenty. */
    private function links(array $pages): array
    {
        $out = [];
        foreach ($pages as $p) {
            foreach ($p['links'] ?? [] as $l) {
                if ($l['abs'] && ! isset($out[$l['abs'].'|'.$l['text']])) {
                    $out[$l['abs'].'|'.$l['text']] = $l;
                }
            }
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => ($a['zone'] === 'footer' ? 0 : 1) <=> ($b['zone'] === 'footer' ? 0 : 1));

        return $out;
    }

    private function findLink(array $links, string $re, string $key): ?string
    {
        foreach ($links as $l) {
            $path = rawurldecode((string) parse_url($l['abs'], PHP_URL_PATH));
            if (! $l['internal'] && ! ($key === 'withdrawal_form' && preg_match('/\.(pdf|docx?)$/i', $path))) {
                continue;
            }
            if (in_array($key, ['returns', 'delivery', 'complaints'], true) && preg_match('#/(?:koszyk|cart|checkout|moje-konto|my-account)#i', $path)) {
                continue;
            }
            if (preg_match($re, $l['text']) || preg_match($re, str_replace(['-', '_', '/'], ' ', $path))) {
                return $l['abs'];
            }
        }

        return null;
    }

    private function executable(string $attrs): bool
    {
        if (preg_match('/\bdata-src\s*=/i', $attrs) && ! preg_match('/(?:^|\s)src\s*=/i', $attrs)) {
            return false; // źródło podstawiane dopiero po zgodzie
        }
        if (! preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $m)) {
            return true;
        }

        return in_array(strtolower($m[1]), ['text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript', 'javascript',
            // opóźnianie przez wtyczki wydajności (WP Rocket, LiteSpeed, Perfmatters, Flying Scripts, WP Meteor) – skrypt startuje
            // przy pierwszym ruchu myszy / przewinięciu, a nie po zgodzie, więc to nie jest blokada
            'text/rocketlazyloadscript', 'litespeed/javascript', 'pmdelayedscript', 'text/pmdelayedscript', 'delay', 'text/delay', 'text/flying-scripts', 'text/wpmeteor'], true);
    }

    /** Kod bez widocznego tekstu: znaczniki z atrybutami + zawartość skryptów (słowo „Cookiebot” w artykule nie oznacza banera). */
    private function codeOnly(string $html): string
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $scripts);
        preg_match_all('#<[a-z!][^>]*>#i', $html, $tags);

        return implode("\n", $tags[0])."\n".implode("\n", $scripts[1]);
    }

    private function plain(string $html): string
    {
        $t = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html);
        $t = html_entity_decode(strip_tags(preg_replace('#<(br|p|div|li|h[1-6]|tr|td)\b#i', "\n<$1", (string) $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $t)), 0, 300000);
    }

    private function gtmContainer(string $id): ?string
    {
        try {
            $r = Http::withUserAgent(self::UA)->timeout(15)->get('https://www.googletagmanager.com/gtm.js?id='.$id);

            return $r->successful() ? $r->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Dodatkowe podstrony (dokumenty, produkt) – pojedynczo i z przerwą, żeby nie wywołać blokady serwera. */
    private function get(string $url): array
    {
        if ($this->requests++ > 0 && ($delay = (int) config('adpilot.site_audit.delay_ms', 0)) > 0) {
            Sleep::for($delay)->milliseconds();
        }
        try {
            $r = Http::withUserAgent(self::UA)->withHeaders(['Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8'])->timeout(15)->connectTimeout(8)->get($url);
        } catch (Throwable) {
            return ['status' => 0, 'html' => '', 'blocked' => null];
        }
        if ($wall = BotWall::detectResponse($r)) {
            return ['status' => $r->status(), 'html' => '', 'blocked' => $wall];
        }

        return ['status' => $r->status(), 'html' => $r->successful() && str_contains(strtolower((string) $r->header('Content-Type')), 'html') ? $r->body() : '', 'blocked' => null];
    }

    private function add(string $group, string $sev, string $title, string $text, ?string $fix = null, ?string $evidence = null, array $urls = [], bool $verify = false): void
    {
        $this->findings[] = ['page' => 'site', 'section' => 'legal', 'group' => $group, 'sev' => $sev, 'title' => $title, 'text' => $text, 'fix' => $fix,
            'evidence' => $evidence, 'urls' => array_slice($urls, 0, 5), 'verify' => $verify, 'source' => 'auto'];
    }
}
