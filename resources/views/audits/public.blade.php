@php
    $a = $audit->areas ?? [];
    $tone = function ($v) {
        if ($v === null) return ['nie sprawdzono', '#8a8984'];
        return $v >= 90 ? ['bardzo dobrze', '#0a7d0a'] : ($v >= 70 ? ['dobrze', '#3b8a1c'] : ($v >= 50 ? ['do poprawy', '#b55a1a'] : ['słabo', '#b42f2f']));
    };
    [$label, $color] = $tone($audit->score);
    $host = preg_replace('#^https?://(www\.)?#', '', rtrim($audit->url, '/'));
    $brandColor = $brand['color'] ?: '#2a78d6';
    $sevLabel = ['critical' => 'Krytyczne', 'high' => 'Ważne', 'medium' => 'Średnie', 'low' => 'Drobne', 'fail' => 'Krytyczne', 'warn' => 'Do poprawy'];
    $sevColor = ['critical' => '#b42f2f', 'high' => '#b55a1a', 'medium' => '#8a6a00', 'low' => '#6b6a66', 'fail' => '#b42f2f', 'warn' => '#b55a1a'];
    $areaIntro = [
        'seo' => 'Czy Google dobrze rozumie i indeksuje stronę: tytuły i opisy, nagłówki, linki, błędy, mapa strony, dane strukturalne. Sprawdzono do '.config('adpilot.site_audit.prospect_pages').' podstron.',
        'speed' => 'Jak szybko strona ładuje się na telefonie. Wolna strona to mniej klientów i droższe reklamy – co sekunda opóźnienia ubywa odwiedzających.',
        'tracking' => 'Czy strona mierzy ruch i kontakty: Google Analytics, Menedżer tagów, piksel Meta, baner zgód (RODO). Bez tego reklamy działają „na ślepo”.',
        'security' => 'Elementy bezpieczeństwa widoczne publicznie (bez prób włamania): szyfrowanie, certyfikat, nagłówki ochronne, przestarzałe oprogramowanie.',
    ];
    $checks = fn ($k) => collect($a[$k]['checks'] ?? []);
    $problems = fn ($k) => $checks($k)->whereIn('status', ['fail', 'warn'])->sortBy(fn ($c) => $c['status'] === 'fail' ? 0 : 1)->values();
    $passed = fn ($k) => $checks($k)->where('status', 'ok')->pluck('label');
    $seoIssues = collect($a['seo']['issues'] ?? []);

    // Najważniejsze rekomendacje: najpoważniejsze problemy ze wszystkich dziedzin
    $rank = ['critical' => 0, 'fail' => 0, 'high' => 1, 'warn' => 2, 'medium' => 3, 'low' => 4];
    $top = collect()
        ->concat($seoIssues->map(fn ($i) => ['area' => 'SEO', 'sev' => $i['severity'], 'title' => $i['title'], 'fix' => $i['action']]))
        ->concat($problems('security')->map(fn ($c) => ['area' => 'Bezpieczeństwo', 'sev' => $c['status'], 'title' => $c['label'], 'fix' => $c['fix']]))
        ->concat($problems('tracking')->map(fn ($c) => ['area' => 'Pomiar', 'sev' => $c['status'], 'title' => $c['label'], 'fix' => $c['fix']]));
    $speedScore = $a['speed']['score'] ?? null;
    if ($speedScore !== null && $speedScore < 70) {
        $top->push(['area' => 'Szybkość', 'sev' => $speedScore < 50 ? 'fail' : 'warn', 'title' => 'Strona ładuje się zbyt wolno', 'fix' => 'Kompresja i nowoczesne formaty obrazów (WebP), cache strony, ograniczenie wtyczek i skryptów, szybszy hosting.']);
    }
    $top = $top->filter(fn ($x) => in_array($x['sev'], ['critical', 'fail', 'high', 'warn'], true))->sortBy(fn ($x) => $rank[$x['sev']] ?? 9)->take(6)->values();
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>Audyt strony {{ $host }} – {{ $agency }}</title>
    <style>
        @page { margin: 22mm 16mm 18mm 16mm; }
        * { box-sizing: border-box; }
        body { font-family: {!! $pdf ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' !!};
               color: #1a1a1a; background: {{ $pdf ? '#ffffff' : '#f6f6f4' }}; margin: 0; font-size: {{ $pdf ? '9.5px' : '15px' }}; line-height: 1.5; }
        .wrap { max-width: 880px; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '36px 40px 48px' }}; {{ $pdf ? '' : 'margin-top: 24px; margin-bottom: 24px; border-radius: 12px; border: 1px solid #e6e5e0;' }} }
        b, strong, h1, h2, h3 { font-weight: bold; }
        .muted { color: #8a8984; } .ink2 { color: #52514e; }
        table { width: 100%; border-collapse: collapse; }
        .brandbar { height: 5px; background: {{ $brandColor }}; margin-bottom: 14px; }
        .logo { max-height: 40px; max-width: 190px; }
        .kicker { color: {{ $brandColor }}; font-size: 0.8em; letter-spacing: 1px; text-transform: uppercase; font-weight: bold; }
        h1 { font-size: {{ $pdf ? '22px' : '30px' }}; margin: 2px 0 2px; }
        h2 { font-size: {{ $pdf ? '13px' : '19px' }}; margin: 0 0 4px; }
        .section { margin-top: {{ $pdf ? '16px' : '28px' }}; page-break-inside: auto; }
        .section-head { border-bottom: 2px solid #ecebe6; padding-bottom: 6px; margin-bottom: 8px; }
        .section-head td { vertical-align: bottom; }
        .score-big { font-size: {{ $pdf ? '40px' : '56px' }}; font-weight: bold; line-height: 1; }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 9px; font-size: 0.8em; font-weight: bold; color: #fff; }
        .bar-bg { background: #ecebe6; height: 8px; border-radius: 4px; }
        .bar { height: 8px; border-radius: 4px; }
        .summary td { padding: 4px 0; vertical-align: middle; }
        .card { border: 1px solid #ecebe6; border-radius: 8px; padding: 10px 12px; margin: 6px 0; page-break-inside: avoid; }
        .card .t { font-weight: bold; }
        .tag { display: inline-block; font-size: 0.75em; font-weight: bold; padding: 0 6px; border-radius: 7px; border: 1px solid; margin-right: 4px; }
        .fix { margin-top: 3px; color: #2b2b2b; }
        .fix b { color: {{ $brandColor }}; }
        .ok-list { color: #3b8a1c; font-size: 0.9em; margin-top: 6px; }
        .reco td { padding: 6px 6px; border-bottom: 1px solid #ecebe6; vertical-align: top; }
        .num { width: 22px; height: 22px; border-radius: 11px; background: {{ $brandColor }}; color: #fff; text-align: center; font-weight: bold; font-size: 10px; line-height: 12px; padding-top: 5px; }
        .cta { margin-top: 18px; background: {{ $brandColor }}; color: #fff; border-radius: 10px; padding: 14px 18px; page-break-inside: avoid; }
        .cta a { color: #fff; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; background: {{ $brandColor }}; color: #fff; text-decoration: none; font-size: 14px; }
        .metric td { width: 25%; padding: 4px; vertical-align: top; }
        .metric .box { border: 1px solid #ecebe6; border-radius: 8px; padding: 8px 10px; }
        .metric .v { font-size: {{ $pdf ? '15px' : '22px' }}; font-weight: bold; }
        @if($pdf)
            .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5px; color: #8a8984; border-top: 1px solid #ecebe6; padding-top: 4px; }
        @else
            .footer { margin-top: 28px; font-size: 0.8em; color: #8a8984; border-top: 1px solid #ecebe6; padding-top: 8px; }
        @endif
    </style>
</head>
<body>
@if($pdf)
    <div class="footer">{{ $agency }}{{ collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->isNotEmpty() ? ' · '.collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') : '' }} · Audyt {{ $host }} · {{ $audit->created_at->format('d.m.Y') }}</div>
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
                {{ $audit->created_at->format('d.m.Y') }}
            </td>
        </tr>
    </table>

    <div style="margin-top: 18px">
        <div class="kicker">Audyt strony internetowej</div>
        <h1>{{ $audit->name ?: $host }}</h1>
        <div class="ink2">{{ $host }}</div>
    </div>

    {{-- Wynik ogólny + dziedziny --}}
    <table style="margin-top: 14px">
        <tr>
            <td style="width: 30%; vertical-align: top">
                <div class="muted">Ocena ogólna</div>
                <div class="score-big" style="color: {{ $color }}">{{ $audit->score }}<span style="font-size: 0.35em; color: #8a8984">/100</span></div>
                <span class="pill" style="background: {{ $color }}">{{ $label }}</span>
            </td>
            <td style="vertical-align: top">
                <table class="summary">
                    @foreach($areas as $key => $name)
                        @php $s = $a[$key]['score'] ?? null; [$l, $c] = $tone($s); @endphp
                        <tr>
                            <td style="width: 34%"><strong>{{ $name }}</strong></td>
                            <td style="width: 46%"><div class="bar-bg"><div class="bar" style="width: {{ max(2, (int) $s) }}%; background: {{ $c }}"></div></div></td>
                            <td style="width: 20%; text-align: right"><strong style="color: {{ $c }}">{{ $s ?? '–' }}</strong> <span class="muted">{{ $s === null ? '' : $l }}</span></td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    {{-- Najważniejsze rekomendacje --}}
    @if($top->isNotEmpty())
        <div class="section">
            <div class="section-head"><h2>Najważniejsze rekomendacje</h2><span class="muted">Od tego warto zacząć – największy wpływ na wyniki i bezpieczeństwo.</span></div>
            <table class="reco">
                @foreach($top as $n => $r)
                    <tr>
                        <td style="width: 30px"><div class="num">{{ $n + 1 }}</div></td>
                        <td>
                            <span class="tag" style="color: {{ $sevColor[$r['sev']] }}; border-color: {{ $sevColor[$r['sev']] }}">{{ $sevLabel[$r['sev']] }}</span>
                            <span class="muted">{{ $r['area'] }}</span><br>
                            <strong>{{ $r['title'] }}</strong>
                            @if($r['fix'])<div class="fix">{{ $r['fix'] }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    {{-- Dziedziny --}}
    @foreach($areas as $key => $name)
        @php $s = $a[$key]['score'] ?? null; [$l, $c] = $tone($s); @endphp
        <div class="section">
            <table class="section-head">
                <tr>
                    <td><h2>{{ $name }}</h2></td>
                    <td style="text-align: right"><strong style="color: {{ $c }}; font-size: 1.3em">{{ $s ?? '–' }}</strong><span class="muted">/100 · {{ $l }}</span></td>
                </tr>
            </table>
            <p class="ink2" style="margin: 0 0 6px">{{ $areaIntro[$key] }}</p>

            @if(! empty($a[$key]['error']))
                <p class="muted">Nie udało się sprawdzić tej części (strona mogła zablokować automatyczne skanowanie).</p>
            @elseif($key === 'seo')
                @forelse($seoIssues->take($pdf ? 10 : 14) as $i)
                    <div class="card">
                        <span class="tag" style="color: {{ $sevColor[$i['severity']] }}; border-color: {{ $sevColor[$i['severity']] }}">{{ $sevLabel[$i['severity']] }}</span>
                        <span class="t">{{ $i['title'] }}</span>@if($i['scope'] === 'pages') <span class="muted">· {{ $i['count'] }} {{ $i['count'] === 1 ? 'podstrona' : 'podstron' }}</span>@endif
                        <div class="ink2">{{ $i['description'] }}</div>
                        <div class="fix"><b>Rekomendacja:</b> {{ $i['action'] }}</div>
                        @if(! empty($i['urls']) && $i['scope'] === 'pages')<div class="muted" style="font-size: 0.85em">np. {{ implode(', ', array_slice($i['urls'], 0, 2)) }}</div>@endif
                    </div>
                @empty
                    <p class="ok-list">✓ Nie znaleziono problemów SEO.</p>
                @endforelse
                @if($seoIssues->count() > ($pdf ? 10 : 14))<p class="muted">… oraz {{ $seoIssues->count() - ($pdf ? 10 : 14) }} drobniejszych uwag.</p>@endif
            @elseif($key === 'speed')
                @php $ps = $a['speed']['pagespeed'] ?? null; @endphp
                @if($ps)
                    <table class="metric"><tr>
                        <td><div class="box"><div class="muted">Google PageSpeed (telefon)</div><div class="v" style="color: {{ $tone($ps['performance'])[1] }}">{{ $ps['performance'] }}/100</div></div></td>
                        <td><div class="box"><div class="muted">Wczytanie głównej treści (LCP)</div><div class="v">{{ number_format($ps['lcp_s'], 1, ',', '') }} s</div><div class="muted">cel: do 2,5 s</div></div></td>
                        <td><div class="box"><div class="muted">Stabilność układu (CLS)</div><div class="v">{{ $ps['cls'] }}</div><div class="muted">cel: do 0,1</div></div></td>
                        <td><div class="box"><div class="muted">Blokowanie strony (TBT)</div><div class="v">{{ number_format($ps['tbt_ms'], 0, ',', ' ') }} ms</div><div class="muted">cel: do 200 ms</div></div></td>
                    </tr></table>
                @else
                    <table class="metric"><tr>
                        <td><div class="box"><div class="muted">Średni czas odpowiedzi serwera</div><div class="v">{{ ! empty($a['speed']['avg_ms']) ? number_format($a['speed']['avg_ms'], 0, ',', ' ').' ms' : '–' }}</div><div class="muted">cel: poniżej 600 ms</div></div></td>
                        <td colspan="3"></td>
                    </tr></table>
                @endif
                @if($speedScore !== null && $speedScore < 90)
                    <div class="fix"><b>Rekomendacja:</b> kompresja obrazów i format WebP, cache strony i przeglądarki, ograniczenie ciężkich wtyczek i skryptów zewnętrznych, leniwe ładowanie obrazów, w razie potrzeby szybszy hosting.</div>
                @endif
            @else
                @forelse($problems($key) as $p)
                    <div class="card">
                        <span class="tag" style="color: {{ $sevColor[$p['status']] }}; border-color: {{ $sevColor[$p['status']] }}">{{ $sevLabel[$p['status']] }}</span>
                        <span class="t">{{ $p['label'] }}</span>
                        <div class="ink2">{{ $p['details'] }}</div>
                        @if($p['fix'])<div class="fix"><b>Rekomendacja:</b> {{ $p['fix'] }}</div>@endif
                    </div>
                @empty
                    <p class="ok-list">✓ Nie znaleziono problemów.</p>
                @endforelse
                @if($passed($key)->isNotEmpty())
                    <div class="ok-list">✓ Działa dobrze: {{ $passed($key)->implode(', ') }}.</div>
                @endif
            @endif
        </div>
    @endforeach

    <div class="cta">
        <strong style="font-size: 1.2em">Chcesz, żebyśmy to poprawili?</strong><br>
        Przygotujemy plan i wycenę – od szybkości i SEO po pomiar reklam i bezpieczeństwo. Pierwsza konsultacja jest bezpłatna.<br>
        <strong>{{ collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') ?: $agency }}</strong>
    </div>

    @unless($pdf)
        <div class="footer">Audyt automatyczny wykonany {{ $audit->finished_at?->format('d.m.Y H:i') }} przez {{ $agency }}. Sprawdzono publicznie dostępne elementy strony.</div>
    @endunless
</div>
</body>
</html>
