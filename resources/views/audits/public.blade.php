@php
    $a = $audit->areas ?? [];
    $r = $report;
    $tone = function ($v) {
        if ($v === null) return ['nie sprawdzono', '#8a8984'];
        return $v >= 90 ? ['bardzo dobrze', '#0a7d0a'] : ($v >= 70 ? ['dobrze', '#3b8a1c'] : ($v >= 50 ? ['do poprawy', '#b55a1a'] : ['słabo', '#b42f2f']));
    };
    [$label, $color] = $tone($audit->score);
    $host = preg_replace('#^https?://(www\.)?#', '', rtrim($audit->url, '/'));
    $brandColor = $brand['color'] ?: '#2a78d6';
    $navy = '#1f3a5f';
    $sevName = ['pilne' => 'PILNE', 'srednie' => 'ŚREDNIE', 'drobne' => 'DROBNE'];
    $sevBg = ['pilne' => '#c0392b', 'srednie' => '#d4880f', 'drobne' => '#3a6ea5'];
    $pl = fn (int $n, string $one, string $few, string $many) => $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    $c = $r['counts'];
    $date = ($audit->finished_at ?? $audit->created_at)->format('d.m.Y');
    $company = $r['company'] ?: $host;
    $effort = ['S' => 'S – do 2 dni pracy', 'M' => 'M – ok. 3–5 dni pracy', 'L' => 'L – powyżej tygodnia pracy'];
    $start = collect($r['plan']['now'])->concat($r['plan']['month'])->take(6);
    $contact = collect([$brand['email'], $brand['phone'], $brand['website']])->filter();
    // Raport wewnętrzny (audyt z listy stron): tylko błędy i to, co do poprawy – bez rzeczy „na zielono”, propozycji i reklamy
    $internal = $internal ?? false;
    $pageByRole = collect($r['pages'])->keyBy('role');
    $showSection = fn ($s) => ! $internal || $s['groups'] || $pageByRole->has($s['key']);
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>{{ $company }} – analiza strony – {{ $agency }}</title>
    <style>
        @page { margin: 18mm 16mm 18mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: {!! $pdf ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' !!};
               color: #1a1a1a; background: {{ $pdf ? '#ffffff' : '#f6f6f4' }}; margin: 0; font-size: {{ $pdf ? '9.3px' : '15px' }}; line-height: 1.5; }
        .wrap { max-width: 900px; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '36px 44px 48px' }}; {{ $pdf ? '' : 'margin-top: 24px; margin-bottom: 24px; border-radius: 12px; border: 1px solid #e6e5e0;' }} }
        b, strong, h1, h2, h3 { font-weight: bold; }
        a { color: {{ $navy }}; }
        .muted { color: #6b7280; } .small { font-size: 0.85em; }
        table { width: 100%; border-collapse: collapse; }
        .brandbar { height: 5px; background: {{ $brandColor }}; margin-bottom: 14px; }
        .logo { max-height: 40px; max-width: 190px; }
        h1 { color: {{ $navy }}; font-size: {{ $pdf ? '23px' : '32px' }}; line-height: 1.15; margin: 16px 0 6px; }
        .subtitle { color: #5b6573; font-size: 1.08em; }
        h2 { color: {{ $navy }}; font-size: {{ $pdf ? '15px' : '22px' }}; margin: {{ $pdf ? '18px' : '34px' }} 0 2px; page-break-after: avoid; }
        h3 { color: {{ $navy }}; font-size: {{ $pdf ? '11.5px' : '17px' }}; margin: {{ $pdf ? '10px' : '18px' }} 0 4px; page-break-after: avoid; }
        .url { color: #6b7280; font-size: 0.85em; word-break: break-all; margin-bottom: 4px; }
        .note { color: #6b7280; font-style: italic; font-size: 0.92em; margin: 6px 0; }
        .score-big { font-size: {{ $pdf ? '36px' : '52px' }}; font-weight: bold; line-height: 1; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 9px; font-size: 0.8em; font-weight: bold; color: #fff; }
        .bar-bg { background: #ecebe6; height: 7px; border-radius: 4px; }
        .bar { height: 7px; border-radius: 4px; }
        .summary td { padding: 3px 0; vertical-align: middle; }
        .count { text-align: center; color: #fff; padding: 8px 4px; border-radius: 6px; }
        .count .n { font-size: {{ $pdf ? '20px' : '28px' }}; font-weight: bold; line-height: 1.1; }
        .box { background: #f3f5f8; border-radius: 8px; padding: 10px 14px; margin-top: 12px; page-break-inside: avoid; }
        .toc td { padding: 3px 4px; border-bottom: 1px solid #eef0f3; }
        .find { margin: 0; border-bottom: 1px solid #e5e7eb; page-break-inside: avoid; }
        .find td { vertical-align: top; padding: 6px 8px; }
        .find td.sev { width: {{ $pdf ? '62px' : '78px' }}; color: #fff; font-weight: bold; font-size: 0.78em; text-align: center; padding-top: 8px; }
        .fid { color: #6b7280; font-weight: normal; }
        .verify { display: inline-block; font-size: 0.75em; font-weight: bold; color: #7a5600; background: #fff4d6; border: 1px solid #f0d58a; border-radius: 6px; padding: 0 5px; }
        .ev { font-family: {!! $pdf ? '"DejaVu Sans Mono", monospace' : 'ui-monospace, Consolas, monospace' !!}; font-size: 0.82em; background: #f5f6f8; color: #374151; padding: 3px 6px; border-radius: 4px; margin-top: 3px; white-space: pre-wrap; word-break: break-all; }
        .fix { margin-top: 3px; } .fix b { color: {{ $navy }}; }
        .plan td { padding: 4px 6px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
        .plan th { text-align: left; font-size: 0.8em; color: #6b7280; padding: 4px 6px; border-bottom: 1px solid #d9dde3; }
        .prop { margin: 4px 0 12px; page-break-inside: avoid; }
        .prop td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .prop td.k { width: 26%; background: #eef1f5; color: #5b6573; font-weight: bold; font-size: 0.9em; }
        .prop ol { margin: 0; padding-left: 16px; }
        .square { color: #2e8b57; }
        .check td { padding: 3px 4px; vertical-align: top; }
        .cta { margin-top: 18px; background: {{ $brandColor }}; color: #fff; border-radius: 10px; padding: 14px 18px; page-break-inside: avoid; }
        .cta a { color: #fff; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; background: {{ $brandColor }}; color: #fff; text-decoration: none; font-size: 14px; }
        .metric td { width: 25%; padding: 4px; vertical-align: top; }
        .metric .mbox { border: 1px solid #ecebe6; border-radius: 8px; padding: 6px 10px; }
        .metric .v { font-size: {{ $pdf ? '14px' : '20px' }}; font-weight: bold; }
        @if($pdf)
            .footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7.5px; color: #8a8984; border-top: 1px solid #ecebe6; padding-top: 4px; }
            .newpage { page-break-before: always; }
        @else
            .footer { margin-top: 28px; font-size: 0.8em; color: #8a8984; border-top: 1px solid #ecebe6; padding-top: 8px; }
        @endif
    </style>
</head>
<body>
@if($pdf)
    <div class="footer">{{ $company }} – analiza strony | {{ $date }} · {{ $agency }}{{ $contact->isNotEmpty() ? ' · '.$contact->implode(' · ') : '' }}</div>
@endif
<div class="wrap">
    <div class="brandbar"></div>
    <table>
        <tr>
            <td style="vertical-align: middle">
                @if($brand['logo_path'] && is_file($brand['logo_path']))
                    <img class="logo" src="{{ $pdf ? 'data:'.mime_content_type($brand['logo_path']).';base64,'.base64_encode(file_get_contents($brand['logo_path'])) : $brand['logo_url'] }}" alt="{{ $agency }}">
                @else
                    <strong style="font-size: 1.3em">{{ $agency }}</strong>
                @endif
            </td>
            <td style="text-align:right; vertical-align: middle" class="muted">
                @unless($pdf)<a class="btn" href="{{ $pdfUrl }}">Pobierz PDF</a><br>@endunless
                {{ $date }}
            </td>
        </tr>
    </table>

    <h1>{{ $company }} – szczegółowa analiza strony</h1>
    <div class="subtitle">{{ $host }}{{ $r['industry'] ? ' · '.$r['industry'] : '' }} · @if($internal)raport wewnętrzny: błędy i rzeczy do poprawy, najpierw na podstronach z frazami @else błędy w treści, w kodzie, różnice telefon/komputer i propozycje rozbudowy @endif · stan na {{ $date }}</div>

    {{-- ============ Podsumowanie ============ --}}
    <h2>Podsumowanie</h2>
    <table style="margin-top: 6px">
        <tr>
            <td style="width: 24%; vertical-align: top">
                <div class="muted">Ocena ogólna</div>
                <div class="score-big" style="color: {{ $color }}">{{ $audit->score }}<span style="font-size: 0.35em; color: #8a8984">/100</span></div>
                <span class="pill" style="background: {{ $color }}">{{ $label }}</span>
            </td>
            <td style="width: 44%; vertical-align: top; padding-right: 14px">
                <table class="summary">
                    @foreach($areas as $key => $name)
                        @php $s = $a[$key]['score'] ?? null; [$l, $cc] = $tone($s); @endphp
                        <tr>
                            <td style="width: 44%">{{ $name }}</td>
                            <td style="width: 38%"><div class="bar-bg"><div class="bar" style="width: {{ max(2, (int) $s) }}%; background: {{ $cc }}"></div></div></td>
                            <td style="width: 18%; text-align: right"><strong style="color: {{ $cc }}">{{ $s ?? '–' }}</strong></td>
                        </tr>
                    @endforeach
                </table>
            </td>
            <td style="vertical-align: top">
                <table>
                    <tr>
                        @foreach(['pilne', 'srednie', 'drobne'] as $sv)
                            <td style="padding: 0 2px"><div class="count" style="background: {{ $sevBg[$sv] }}"><div class="n">{{ $c[$sv] }}</div><div class="small">{{ mb_strtolower($sevName[$sv]) }}</div></div></td>
                        @endforeach
                    </tr>
                </table>
                @unless($internal)<div class="muted small" style="margin-top: 4px; text-align: center">{{ count($r['proposals']) }} {{ $pl(count($r['proposals']), 'propozycja', 'propozycje', 'propozycji') }} rozbudowy</div>@endunless
            </td>
        </tr>
    </table>

    <p style="margin: 10px 0 0">{{ $r['summary'] }}</p>

    @if($start->isNotEmpty())
        <div class="box">
            <strong style="color: {{ $navy }}">Od czego zacząć</strong>
            <table style="margin-top: 4px">
                @foreach($start as $it)
                    @php $sv = collect($r['plan']['now'])->contains('id', $it['id']) ? 'pilne' : 'srednie'; @endphp
                    <tr>
                        <td style="width: 34px; padding: 2px 0; vertical-align: top"><span class="fid">{{ $it['id'] }}</span></td>
                        <td style="padding: 2px 0"><span style="color: {{ $sevBg[$sv] }}; font-weight: bold">●</span> {{ $it['title'] }} <span class="muted small">· {{ $it['where'] }}</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if(! empty($r['strengths']) && ! $internal)
        <div style="margin-top: 10px"><strong style="color: #2e7d32">Co działa dobrze:</strong> {{ implode(' · ', $r['strengths']) }}</div>
    @endif

    {{-- ============ Spis treści ============ --}}
    <h3>Spis treści</h3>
    <table class="toc">
        <tr><td style="width: 30px">0.</td><td>@unless($pdf)<a href="#zakres">@endunless Zakres i metoda @unless($pdf)</a>@endunless</td><td></td></tr>
        @foreach(array_filter($r['sections'], $showSection) as $s)
            <tr>
                <td>{{ $s['num'] }}.</td>
                <td>@unless($pdf)<a href="#s{{ $s['num'] }}">@endunless{{ $s['title'] }}@unless($pdf)</a>@endunless</td>
                <td style="text-align: right; white-space: nowrap" class="small">
                    @foreach(['pilne', 'srednie', 'drobne'] as $sv)
                        @if($s['counts'][$sv])<span style="color: {{ $sevBg[$sv] }}; font-weight: bold">{{ $s['counts'][$sv] }} {{ mb_strtolower($sevName[$sv]) }}</span>&nbsp; @endif
                    @endforeach
                    @if(! array_sum($s['counts']))<span class="muted">bez uwag</span>@endif
                </td>
            </tr>
        @endforeach
        @php $n = count($r['sections']); @endphp
        @unless($internal)
            <tr><td>{{ $n + 1 }}.</td><td>Plan działań</td><td></td></tr>
            <tr><td>{{ $n + 2 }}.</td><td>Propozycje rozbudowy</td><td></td></tr>
        @endunless
        @if(! $internal || $r['checklist'])<tr><td>@unless($internal){{ $n + 3 }}.@endunless</td><td>Checklista do sprawdzenia na telefonie i komputerze</td><td></td></tr>@endif
        @if(array_filter($r['pages'], fn ($p) => ! empty($p['outline'])))<tr><td></td><td>Załącznik: struktura nagłówków</td><td></td></tr>@endif
    </table>

    {{-- ============ Zakres i metoda ============ --}}
    <h2 id="zakres">Zakres i metoda</h2>
    @if($r['pages'])
        <p style="margin: 4px 0">Przeanalizowane podstrony:</p>
        <table class="toc">
            @foreach($r['pages'] as $p)
                <tr>
                    <td style="width: 34%"><strong>{{ $p['label'] }}</strong></td>
                    <td class="url" style="margin: 0"><a href="{{ $p['url'] }}">{{ $p['url'] }}</a> @if(! $p['ok'])<span style="color: #c0392b">(błąd {{ $p['status'] ?: 'połączenia' }})</span>@endif</td>
                </tr>
            @endforeach
        </table>
    @endif
    @unless($internal)
    <p style="margin: 8px 0 4px">Dla każdej podstrony sprawdziliśmy:</p>
    <table class="check">
        <tr><td class="square" style="width: 14px">■</td><td><strong>treść</strong> widoczną dla klienta – błędy, sprzeczności, ceny, teksty po angielsku (także ukryte w kodzie: opisy obrazków, podpowiedzi pól, teksty dla czytników ekranu), język strony;</td></tr>
        <tr><td class="square">■</td><td><strong>linki i formularze</strong> – dokąd prowadzą kafelki i przyciski, linki telefoniczne, pola, zgody;</td></tr>
        <tr><td class="square">■</td><td><strong>strukturę nagłówków</strong> (H1–H6) – pełny spis w załączniku;</td></tr>
        <tr><td class="square">■</td><td><strong>kod</strong> – menu i nagłówek w wersji na telefon i komputer, skrypty śledzące, wersje plików, dostępność;</td></tr>
        <tr><td class="square">■</td><td><strong>zgodność z prawem</strong> – czy Google Analytics, Tag Manager, piksele reklamowe ładują się dopiero po zgodzie, baner cookies, polityka prywatności, informacje przy formularzach, dane firmy @if(! empty($r['legal']['shop'])), a w sklepie: regulamin, zwroty i wzór formularza odstąpienia, reklamacje, najniższa cena z 30 dni (Omnibus), dostępność (EAA)@endif;</td></tr>
        <tr><td class="square">■</td><td><strong>wydajność</strong> – Google PageSpeed na telefonie i komputerze, waga, wymiary i format obrazów, skrypty, czcionki, czas odpowiedzi serwera, kompresja i pamięć podręczna.</td></tr>
    </table>
    <p style="margin: 6px 0 4px">Dodatkowo cała strona przeszła sprawdzenie techniczne: do {{ $a['seo']['pages'] ?? config('adpilot.site_audit.prospect_pages') }} podstron pod kątem błędów i SEO technicznego, pomiar i zgody RODO oraz bezpieczeństwo.</p>
    <p class="note">Ograniczenie: audyt czyta kod strony, a nie jej wygląd na ekranie (style, animacje, rozwijane menu). Uwagi o różnicach telefon/komputer wynikają z budowy kodu – takie punkty są oznaczone „do potwierdzenia”, a na końcu dokumentu jest checklista do szybkiego sprawdzenia.@if($r['verify_count']) Punktów do potwierdzenia: {{ $r['verify_count'] }}.@endif</p>
    @endunless
    <p class="note">Oznaczenia: <span style="color: #c0392b; font-weight: bold">PILNE</span> – traci klientów lub pieniądze, wprowadza w błąd albo niesie ryzyko prawne; <span style="color: #d4880f; font-weight: bold">ŚREDNIE</span> – wyraźnie utrudnia decyzję lub kontakt; <span style="color: #3a6ea5; font-weight: bold">DROBNE</span> – kosmetyka i porządki.</p>
    @if(! empty($r['blocked']))
        <div class="box">
            <strong style="color: {{ $navy }}">Uwaga o zabezpieczeniu serwera.</strong>
            W trakcie audytu serwer strony zaczął pokazywać naszemu narzędziu ekran weryfikacji ({{ $r['blocked'] }}) zamiast treści – to ochrona hostingu przed automatami i nadmiarem zapytań.
            Klienci w zwykłej przeglądarce widzą stronę normalnie, więc nie traktujemy tego jako błędu. Elementów, których nie dało się pobrać, nie oceniamy{{ $r['pages'] ? '' : ' – dotyczy to szczegółowej analizy podstron' }}.
        </div>
    @elseif($r['inspection_error'])
        <p class="note">Szczegółowej analizy podstron nie udało się wykonać – raport zawiera wyniki sprawdzenia technicznego.</p>
    @endif

    {{-- ============ Sekcje ============ --}}
    @foreach(array_values(array_filter($r['sections'], $showSection)) as $s)
        <h2 id="s{{ $s['num'] }}" @if($pdf && $loop->first) class="newpage" @endif>{{ $s['num'] }}. {{ $s['title'] }}</h2>
        @if($s['subtitle'])<div class="muted">{{ $s['subtitle'] }}</div>@endif
        @if($s['url'])<div class="url"><a href="{{ $s['url'] }}">{{ $s['url'] }}</a></div>@endif

        @php $seo = $internal ? ($pageByRole[$s['key']]['seo'] ?? null) : null; @endphp
        @if($seo)
            @php
                $bad = '#c0392b';
                $tlc = $seo['title_len'] === 0 || $seo['title_len'] > 65 || ($seo['title_len'] < 30 && $seo['kind'] !== 'strona główna') ? $bad : null;
                $dlc = $seo['description_len'] === 0 || $seo['description_len'] > 170 || $seo['description_len'] < 70 ? $bad : null;
                $h1c = count($seo['h1']) !== 1 ? $bad : null;
            @endphp
            <table class="plan" style="margin-top: 6px">
                <tr><td style="width: 22%" class="muted">Typ / fraza (z H1 i tytułu)</td><td>{{ $seo['kind'] }}@if($seo['phrase']) · <strong>{{ $seo['phrase'] }}</strong>@endif
                    @if($seo['noindex'])<span style="color: {{ $bad }}; font-weight: bold"> · NOINDEX – niewidoczna w Google</span>@endif
                    @if($seo['canonical_other'])<span style="color: {{ $bad }}; font-weight: bold"> · canonical → {{ $seo['canonical_other'] }}</span>@endif</td></tr>
                <tr><td class="muted">Tytuł w Google</td><td>{{ $seo['title'] ?: '– brak –' }} <span class="small" style="color: {{ $tlc ?? '#6b7280' }}">({{ $seo['title_len'] }} zn.)</span></td></tr>
                <tr><td class="muted">Opis w Google</td><td>{{ $seo['description'] ?: '– brak –' }} <span class="small" style="color: {{ $dlc ?? '#6b7280' }}">({{ $seo['description_len'] }} zn.)</span></td></tr>
                <tr><td class="muted">Nagłówek H1</td><td @if($h1c) style="color: {{ $h1c }}" @endif>{{ $seo['h1'] ? '„'.implode('”, „', $seo['h1']).'”' : '– brak –' }}</td></tr>
                <tr><td class="muted">Treść</td><td>ok. {{ $seo['words'] }} słów @if($seo['links_in_content'] !== null)· linki z treści do innych podstron: {{ $seo['links_in_content'] }}@endif</td></tr>
            </table>
        @endif

        @if($s['key'] === 'perf')
            @php
                $pf = $r['performance'] ?? [];
                $mob = $pf['mobile'] ?? null; $desk = $pf['desktop'] ?? null;
                // [klucz, etykieta, cel, próg „dobrze”, próg „słabo”, format]
                $rows = [
                    ['performance', 'Wydajność (ocena Google)', '90–100', 90, 50, 'score'],
                    ['accessibility', 'Dostępność', '90–100', 90, 50, 'score'],
                    ['best_practices', 'Dobre praktyki', '90–100', 90, 50, 'score'],
                    ['seo', 'SEO (podstawy techniczne)', '90–100', 90, 50, 'score'],
                    ['fcp_s', 'Pierwszy element na ekranie (FCP)', 'do 1,8 s', 1.8, 3.0, 's'],
                    ['lcp_s', 'Główna treść na ekranie (LCP)', 'do 2,5 s', 2.5, 4.0, 's'],
                    ['si_s', 'Indeks szybkości', 'do 3,4 s', 3.4, 5.8, 's'],
                    ['tbt_ms', 'Blokowanie strony (TBT)', 'do 200 ms', 200, 600, 'ms'],
                    ['cls', 'Przesunięcia układu (CLS)', 'do 0,1', 0.1, 0.25, 'num'],
                    ['ttfb_ms', 'Odpowiedź serwera', 'do 600 ms', 600, 1800, 'ms'],
                    ['total_kb', 'Waga strony', 'do 2 MB', 2048, 4096, 'kb'],
                    ['requests', 'Liczba plików (zapytań)', 'do ok. 70', 70, 150, 'int'],
                ];
                $fmt = function ($v, $f) {
                    if ($v === null) return '–';
                    return match ($f) { 'score' => $v.'/100', 's' => number_format($v, 1, ',', '').' s', 'ms' => number_format($v, 0, ',', ' ').' ms',
                        'kb' => $v >= 1024 ? number_format($v / 1024, 1, ',', '').' MB' : $v.' KB', 'num' => str_replace('.', ',', (string) $v), default => (string) $v };
                };
                $col = function ($v, $good, $bad, $f) {
                    if ($v === null) return '#8a8984';
                    $ok = $f === 'score' ? $v >= $good : $v <= $good;
                    $poor = $f === 'score' ? $v < $bad : $v > $bad;
                    return $ok ? '#0a7d0a' : ($poor ? '#c0392b' : '#b55a1a');
                };
                $kbf = fn ($v) => $v >= 1024 ? number_format($v / 1024, 1, ',', '').' MB' : $v.' KB';
            @endphp
            @if($mob || $desk)
                <h3>Pomiar Google PageSpeed – telefon i komputer</h3>
                <table class="plan">
                    <tr><th>Wskaźnik</th><th style="width: 18%; text-align: right">Telefon</th><th style="width: 18%; text-align: right">Komputer</th><th style="width: 18%; text-align: right">Cel</th></tr>
                    @foreach($rows as [$k, $lbl, $goal, $good, $bad, $f])
                        @php $allGreen = collect([$mob[$k] ?? null, $desk[$k] ?? null])->filter(fn ($v) => $v !== null)->every(fn ($v) => $col($v, $good, $bad, $f) === '#0a7d0a'); @endphp
                        @if((($mob[$k] ?? null) !== null || ($desk[$k] ?? null) !== null) && ! ($internal && $allGreen))
                            <tr>
                                <td>{{ $lbl }}</td>
                                <td style="text-align: right; font-weight: bold; color: {{ $col($mob[$k] ?? null, $good, $bad, $f) }}">{{ $fmt($mob[$k] ?? null, $f) }}</td>
                                <td style="text-align: right; font-weight: bold; color: {{ $col($desk[$k] ?? null, $good, $bad, $f) }}">{{ $fmt($desk[$k] ?? null, $f) }}</td>
                                <td style="text-align: right" class="muted">{{ $goal }}</td>
                            </tr>
                        @endif
                    @endforeach
                </table>
                <div class="note">Pomiar laboratoryjny Google Lighthouse (telefon: symulacja średniej klasy smartfona i wolniejszego internetu). Wyniki mogą się różnić o kilka punktów między pomiarami.</div>
            @else
                <p class="note">Google PageSpeed był niedostępny w czasie audytu{{ ! empty($pf['avg_ms']) ? ' – średni czas odpowiedzi serwera przy przejściu po stronie: '.$pf['avg_ms'].' ms' : '' }}. Pozostałe pomiary poniżej pochodzą z naszego sprawdzenia kodu i plików.</p>
            @endif

            @php $perfPages = array_values(array_filter($pf['pages'] ?? [], fn ($ps) => ! $internal || ($ps['ttfb_ms'] ?? 0) > 600 || $ps['html_kb'] > 150 || $ps['dom'] > 1500 || ! $ps['compressed'])); @endphp
            @if($perfPages)
                <h3>Podstrony – kod i serwer</h3>
                <table class="plan">
                    <tr><th>Podstrona</th><th style="text-align: right">Odpowiedź serwera</th><th style="text-align: right">Kod HTML</th><th style="text-align: right">Elementy</th><th style="text-align: right">Pliki JS / CSS</th><th style="text-align: right">Zewn. usługi</th><th>Kompresja / cache</th></tr>
                    @foreach($perfPages as $ps)
                        <tr>
                            <td>{{ $ps['label'] }}</td>
                            <td style="text-align: right; color: {{ $col($ps['ttfb_ms'], 600, 1800, 'ms') }}">{{ $fmt($ps['ttfb_ms'], 'ms') }}</td>
                            <td style="text-align: right; color: {{ $col($ps['html_kb'], 150, 400, 'kb') }}">{{ $ps['html_kb'] }} KB</td>
                            <td style="text-align: right; color: {{ $col($ps['dom'], 1500, 3000, 'int') }}">{{ number_format($ps['dom'], 0, ',', ' ') }}</td>
                            <td style="text-align: right">{{ $ps['scripts'] }} / {{ $ps['styles'] }}</td>
                            <td style="text-align: right">{{ $ps['third_party'] }}</td>
                            <td class="muted">{{ $ps['compressed'] ?: 'brak kompresji' }} · {{ $ps['page_cache'] ?: 'brak śladów cache' }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @if(! empty($pf['images']))
                <h3>Najcięższe obrazy</h3>
                <table class="plan">
                    <tr><th style="width: 30%">Plik</th><th style="text-align: right">Waga</th><th style="text-align: right">Wymiary</th><th>Format</th><th>Co poprawić</th><th style="text-align: right">Oszczędność</th></tr>
                    @foreach(array_slice($pf['images'], 0, 10) as $img)
                        <tr>
                            <td style="word-break: break-all">{{ mb_strimwidth(urldecode(basename((string) parse_url($img['url'], PHP_URL_PATH))), 0, 48, '…') }}<div class="muted small">{{ implode(', ', $img['pages']) }}</div></td>
                            <td style="text-align: right; font-weight: bold; color: {{ $col($img['kb'], 150, 500, 'kb') }}">{{ $kbf($img['kb']) }}</td>
                            <td style="text-align: right">{{ $img['width'] ? $img['width'].'×'.$img['height'] : '–' }}</td>
                            <td>{{ $img['format'] }}</td>
                            <td class="small">{{ implode('; ', $img['issues']) ?: '–' }}</td>
                            <td style="text-align: right">{{ $img['saving_kb'] ? '~'.$kbf($img['saving_kb']) : '–' }}</td>
                        </tr>
                    @endforeach
                </table>
                @if(! empty($pf['totals']))
                    <div class="note">Sprawdzono {{ $pf['totals']['images_checked'] }} {{ $pl($pf['totals']['images_checked'], 'obraz', 'obrazy', 'obrazów') }} z najważniejszych podstron – razem {{ $kbf($pf['totals']['images_kb']) }}@if($pf['totals']['saving_kb'] > 50), szacowana oszczędność po optymalizacji: ok. {{ $kbf($pf['totals']['saving_kb']) }}@endif.</div>
                @endif
            @endif
        @endif

        @if($s['key'] === 'legal' && ! empty($r['legal']))
            @php
                $lg = $r['legal'];
                $okColor = fn ($ok) => $ok === true ? '#0a7d0a' : ($ok === false ? '#c0392b' : '#b55a1a');
                $docStatus = ['ok' => ['jest', true], 'link' => ['jest link', true], 'in_text' => ['opisane w regulaminie / polityce', true], 'broken' => ['link nie działa', false],
                    'missing' => ['nie znaleziono', false], 'unknown' => ['nie udało się sprawdzić', null]];
                $banner = match ($lg['banner']) { 'cmp' => 'baner zgód: '.implode(', ', $lg['cmp']).($lg['cmp_auto'] ? ' (automatyczne blokowanie skryptów)' : ''),
                    'own' => 'własny baner cookies (nie rozpoznano platformy)', 'info' => 'tylko komunikat „strona używa cookies” – bez możliwości odmowy', default => 'nie znaleziono banera cookies' };
            @endphp
            @php
                $tools = array_values(array_filter($lg['tools'], fn ($t) => ! $internal || $t['ok'] !== true));
                $docs = array_values(array_filter($lg['documents'], function ($d) use ($internal, $docStatus) {
                    $ok = $docStatus[$d['status']][1] ?? null;
                    return ! $internal || ($ok !== true && ! (! $d['required'] && $d['status'] === 'missing'));
                }));
            @endphp
            @if(! $internal || $tools)<h3>Narzędzia śledzące i zgody</h3>@endif
            @if($tools)
                <table class="plan">
                    <tr><th>Narzędzie</th><th>Identyfikator</th><th>Skąd</th><th>Kiedy się uruchamia</th></tr>
                    @foreach($tools as $tool)
                        <tr>
                            <td>{{ $tool['name'] }}</td>
                            <td style="word-break: break-all">{{ implode(', ', $tool['ids']) ?: '–' }}</td>
                            <td class="muted">{{ $tool['source'] }}</td>
                            <td style="font-weight: bold; color: {{ $okColor($tool['ok']) }}">{{ $tool['load'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @elseif(! $lg['tools'])
                <p class="note">Nie wykryto Google Analytics, Tag Managera ani pikseli reklamowych – strona nie mierzy ruchu (to osobny problem marketingowy, opisany w części „Kod i technika”).</p>
            @endif
            @if(! $internal || $tools || $lg['banner'] !== 'cmp')
                <div class="note">Baner: {{ $banner }}.@if($lg['consent_mode']) Kod strony ustawia Google Consent Mode z domyślną odmową.@endif
                    @if(! empty($lg['browser']) && $tools) Kolumna „Kiedy się uruchamia” pochodzi ze sprawdzenia w prawdziwej przeglądarce: stronę otwarto bez klikania w banerze i zapisano, które narzędzia wysłały dane lub zapisały ciasteczka.@endif</div>
            @endif

            @if($docs)
            <h3>Dokumenty i informacje wymagane prawem</h3>
            <table class="plan">
                <tr><th>Dokument</th><th>Stan</th><th>Adres</th></tr>
                @foreach($docs as $doc)
                    @php [$st, $stOk] = $docStatus[$doc['status']] ?? [$doc['status'], null]; if (! $doc['required'] && $doc['status'] === 'missing') { $stOk = null; } @endphp
                    <tr>
                        <td>{{ $doc['label'] }}@unless($doc['required'])<span class="muted small"> (jeśli dotyczy)</span>@endunless</td>
                        <td style="font-weight: bold; color: {{ $okColor($stOk) }}">{{ $st }}{{ $doc['status'] === 'broken' && $doc['http'] ? ' ('.$doc['http'].')' : '' }}</td>
                        <td class="small" style="word-break: break-all">{{ $doc['url'] ? preg_replace('#^https?://(www\.)?#', '', $doc['url']) : '–' }}</td>
                    </tr>
                @endforeach
            </table>
            @endif
            @if(! empty($lg['promo']))
                <div class="note">Omnibus: sprawdzono {{ $lg['promo']['checked'] }} {{ $pl($lg['promo']['checked'], 'stronę', 'strony', 'stron') }} sklepu – {{ $lg['promo']['found'] ? ($lg['promo']['lowest'] ? 'przy promocjach jest informacja o najniższej cenie z 30 dni.' : 'przy promocjach brakuje informacji o najniższej cenie z 30 dni.') : 'nie było na nich obniżonych cen.' }}</div>
            @endif
        @endif

        @forelse($s['groups'] as $g)
            <h3>{{ $g['title'] }}</h3>
            @foreach($g['findings'] as $f)
                <table class="find">
                    <tr>
                        <td class="sev" style="background: {{ $sevBg[$f['sev']] }}">{{ $sevName[$f['sev']] }}</td>
                        <td>
                            <strong><span class="fid">{{ $f['id'] }}</span> {{ $f['title'] }}{{ preg_match('/[.!?…:)”"]$/u', $f['title']) ? '' : '.' }}</strong>
                            {{ $f['text'] }}
                            @if($f['verify']) <span class="verify">do potwierdzenia</span>@endif
                            @if($f['evidence'])<div class="ev">{{ mb_strimwidth($f['evidence'], 0, 400, '…') }}</div>@endif
                            @if($f['fix'])<div class="fix"><b>Co zrobić:</b> {{ $f['fix'] }}</div>@endif
                            @if(! empty($f['urls']) && $s['key'] !== $f['page'])<div class="url" style="margin: 2px 0 0">{{ implode(' · ', array_slice($f['urls'], 0, 2)) }}</div>@endif
                        </td>
                    </tr>
                </table>
            @endforeach
        @empty
            <p class="muted">Nie znaleźliśmy tu problemów.</p>
        @endforelse
    @endforeach

    {{-- ============ Plan działań ============ --}}
    @unless($internal)
    <h2 @if($pdf) class="newpage" @endif>{{ $n + 1 }}. Plan działań</h2>
    <div class="muted">Wszystkie uwagi w kolejności wykonania. Numer odsyła do opisu w raporcie. „Treść” – zmiany w tekstach i grafikach (może je zrobić firma), „technika” – zmiany w ustawieniach i kodzie.</div>
    @foreach(['now' => ['Teraz – do tygodnia', 'pilne'], 'month' => ['W tym miesiącu', 'srednie'], 'later' => ['Przy okazji', 'drobne']] as $key => [$title, $sv])
        @if($r['plan'][$key])
            <h3><span style="color: {{ $sevBg[$sv] }}">●</span> {{ $title }} <span class="muted small">({{ count($r['plan'][$key]) }})</span></h3>
            <table class="plan">
                <tr><th style="width: 40px">Nr</th><th>Co poprawić</th><th style="width: 28%">Gdzie</th><th style="width: 70px">Kto</th></tr>
                @foreach($r['plan'][$key] as $it)
                    <tr>
                        <td class="fid">{{ $it['id'] }}</td>
                        <td>{{ $it['title'] }}@if($it['verify']) <span class="verify">do potwierdzenia</span>@endif</td>
                        <td class="muted">{{ $it['where'] }}</td>
                        <td class="muted">{{ $it['who'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach

    {{-- ============ Propozycje rozbudowy ============ --}}
    <h2 @if($pdf) class="newpage" @endif>{{ $n + 2 }}. Propozycje rozbudowy</h2>
    <div class="muted" style="margin-bottom: 6px">Pomysły, które połączą to, co strona już ma, w jedną ścieżkę – od pytania klienta do zgłoszenia.</div>
    @foreach($r['proposals'] as $p)
        <h3><span class="square">■</span> {{ $p['title'] }}</h3>
        <table class="prop">
            <tr><td class="k">Na czym polega</td><td>{{ $p['what'] }}</td></tr>
            @if(! empty($p['steps']))
                <tr><td class="k">Jak działa</td><td><ol>@foreach($p['steps'] as $st)<li>{{ $st }}</li>@endforeach</ol></td></tr>
            @endif
            <tr><td class="k">Co zyskuje firma</td><td>{{ $p['benefit'] }}</td></tr>
            <tr><td class="k">Co trzeba od klienta</td><td>{{ $p['needs_from_client'] }}</td></tr>
            <tr><td class="k">Wykonanie</td><td>{{ $p['implementation'] }}</td></tr>
            <tr><td class="k">Szacowany nakład</td><td>{{ $effort[$p['effort']] ?? $p['effort'] }}</td></tr>
        </table>
    @endforeach
    @endunless

    {{-- ============ Checklista ============ --}}
    @if(! $internal || $r['checklist'])
    <h2>@unless($internal){{ $n + 3 }}. @endunless Checklista do sprawdzenia na telefonie i komputerze</h2>
    <div class="muted">Punkty, których nie da się potwierdzić bez przeglądarki. Najprościej sprawdzić je na własnym telefonie i w Chrome (F12 → ikona telefonu → np. iPhone 12 oraz szerokość 1440 px), najlepiej w trybie incognito.</div>
    <table class="check" style="margin-top: 6px">
        @foreach($r['checklist'] as $item)
            <tr><td style="width: 18px" class="square">☐</td><td>{{ $item }}</td></tr>
        @endforeach
    </table>
    @endif

    <p style="margin-top: 14px"><strong>Podsumowanie:</strong>
        {{ $c['pilne'] }} {{ $pl($c['pilne'], 'błąd pilny', 'błędy pilne', 'błędów pilnych') }},
        {{ $c['srednie'] }} {{ $pl($c['srednie'], 'średni', 'średnie', 'średnich') }},
        {{ $c['drobne'] }} {{ $pl($c['drobne'], 'drobny', 'drobne', 'drobnych') }}@if($internal).@else,
        {{ count($r['proposals']) }} {{ $pl(count($r['proposals']), 'propozycja', 'propozycje', 'propozycji') }} rozbudowy.@endif</p>

    {{-- ============ Załącznik: struktura nagłówków ============ --}}
    @php $outlines = array_filter($r['pages'], fn ($p) => ! empty($p['outline']) && (! $internal || collect($p['outline'])->contains(fn ($h) => ! empty($h['problem'])))); @endphp
    @if($outlines)
        <h2 @if($pdf) class="newpage" @endif>Załącznik: struktura nagłówków</h2>
        <div class="muted">Nagłówki tworzą spis treści podstrony – czytają go czytniki ekranu, Google i asystenci AI. Poprawnie: jeden H1 (tytuł), pod nim H2 (działy), pod nimi H3 (podpunkty). Na czerwono zaznaczyliśmy miejsca do poprawy; szare to menu i stopka.</div>
        @foreach($outlines as $p)
            <h3>{{ $p['label'] }} <span class="muted small">· {{ count($p['outline']) }} {{ $pl(count($p['outline']), 'nagłówek', 'nagłówki', 'nagłówków') }}</span></h3>
            <table class="check">
                @foreach($p['outline'] as $h)
                    <tr>
                        <td style="padding-left: {{ ($h['level'] - 1) * 14 + 4 }}px; {{ $h['zone'] !== 'main' ? 'color: #9ca3af;' : '' }}">
                            <span style="display: inline-block; min-width: 22px; font-weight: bold; font-size: 0.8em; color: {{ $h['problem'] ? '#c0392b' : ($h['zone'] !== 'main' ? '#9ca3af' : $navy) }}">H{{ $h['level'] }}</span>
                            {{ $h['text'] }}
                            @if($h['problem'])<span style="color: #c0392b; font-size: 0.8em; font-weight: bold"> ← {{ $h['problem'] }}</span>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endif

    @unless($internal)
    <div class="cta">
        <strong style="font-size: 1.2em">Chcesz, żebyśmy to poprawili?</strong><br>
        Przygotujemy plan i wycenę – od poprawek w treści i formularzach po pomiar, bezpieczeństwo, szybkość i nowe funkcje. Pierwsza konsultacja jest bezpłatna.<br>
        <strong>{{ $contact->implode(' · ') ?: $agency }}</strong>
    </div>
    @endunless

    @unless($pdf)
        <div class="footer">Analiza wykonana {{ $audit->finished_at?->format('d.m.Y H:i') }} przez {{ $agency }}. Sprawdzono publicznie dostępne elementy strony.</div>
    @endunless
</div>
</body>
</html>
