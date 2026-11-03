@php
    $a = $audit->areas ?? [];
    $tone = function ($v) {
        if ($v === null) return ['–', '#8a8984'];
        return $v >= 90 ? ['bardzo dobrze', '#0a7d0a'] : ($v >= 70 ? ['dobrze', '#0a7d0a'] : ($v >= 50 ? ['do poprawy', '#a4471f'] : ['słabo', '#b42f2f']));
    };
    [$label, $color] = $tone($audit->score);
    $sev = ['critical' => ['Krytyczne', '#b42f2f'], 'high' => ['Ważne', '#a4471f'], 'medium' => ['Średnie', '#7a5600'], 'low' => ['Drobne', '#52514e']];
    $statusMark = ['fail' => ['✗', '#b42f2f'], 'warn' => ['!', '#a4471f'], 'ok' => ['✓', '#0a7d0a'], 'info' => ['i', '#8a8984']];
    $host = preg_replace('#^https?://(www\.)?#', '', rtrim($audit->url, '/'));
    $problems = fn ($checks) => collect($checks ?? [])->whereIn('status', ['fail', 'warn'])->sortBy(fn ($c) => $c['status'] === 'fail' ? 0 : 1)->values();
    $okCount = fn ($checks) => collect($checks ?? [])->where('status', 'ok')->count();
    $maxIssues = $pdf ? 8 : 12;
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>Audyt strony {{ $host }}</title>
    <style>
        :root { --surface: #fcfcfb; --card: #ffffff; --ink: #0b0b0b; --ink2: #52514e; --muted: #8a8984; --line: #e6e5e0; --brand: {{ $brand['color'] }}; }
        * { box-sizing: border-box; }
        body { font-family: {!! $pdf ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' !!};
               color: var(--ink); background: var(--surface); margin: 0; font-size: {{ $pdf ? '10px' : '15px' }}; line-height: 1.5; }
        .wrap { max-width: 900px; margin: 0 auto; padding: {{ $pdf ? '0' : '32px 20px 60px' }}; }
        h1 { font-size: {{ $pdf ? '20px' : '28px' }}; margin: 0 0 4px; }
        h2 { font-size: {{ $pdf ? '13px' : '19px' }}; margin: 26px 0 8px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
        .muted { color: var(--muted); } .ink2 { color: var(--ink2); }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: bottom; }
        .logo { max-height: 44px; max-width: 200px; margin-bottom: 8px; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; background: var(--brand); color: #fff; text-decoration: none; font-size: 14px; }
        .tiles td { width: 20%; vertical-align: top; padding: 4px; }
        .tile { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 12px; }
        .tile .label { color: var(--ink2); font-size: 0.85em; }
        .tile .value { font-size: {{ $pdf ? '18px' : '26px' }}; font-weight: 800; }
        .row { padding: 6px 0; border-bottom: 1px solid var(--line); page-break-inside: avoid; }
        .mark { display: inline-block; width: 18px; font-weight: 800; }
        .fix { color: var(--ink2); font-size: 0.85em; margin-left: 18px; }
        .cta { margin-top: 28px; background: var(--card); border: 2px solid var(--brand); border-radius: 10px; padding: 16px 20px; page-break-inside: avoid; }
        .footer { margin-top: 30px; color: var(--muted); font-size: 0.8em; }
    </style>
</head>
<body>
<div class="wrap">
    <table class="header">
        <tr>
            <td>
                @if($brand['logo_path'] && is_file($brand['logo_path']))
                    <img class="logo" src="{{ $pdf ? 'data:'.mime_content_type($brand['logo_path']).';base64,'.base64_encode(file_get_contents($brand['logo_path'])) : $brand['logo_url'] }}" alt="{{ $agency }}">
                @endif
                <div class="muted">{{ $agency }} · Audyt strony internetowej</div>
                <h1>{{ $audit->name ?: $host }}</h1>
                <div class="ink2">{{ $host }} · {{ $audit->created_at->format('d.m.Y') }}</div>
            </td>
            @unless($pdf)
                <td style="text-align:right"><a class="btn" href="{{ $pdfUrl }}">Pobierz PDF</a></td>
            @endunless
        </tr>
    </table>

    <h2>Wynik</h2>
    <table class="tiles">
        <tr>
            <td><div class="tile"><div class="label">Ocena ogólna</div><div class="value" style="color: {{ $color }}">{{ $audit->score }}<span class="muted" style="font-size:0.45em">/100</span></div><div style="color: {{ $color }}; font-weight:600">{{ $label }}</div></div></td>
            @foreach($areas as $key => $name)
                @php $s = $a[$key]['score'] ?? null; [$l, $c] = $tone($s); @endphp
                <td><div class="tile"><div class="label">{{ $name }}</div><div class="value" style="color: {{ $c }}">{{ $s ?? '–' }}</div><div style="color: {{ $c }}">{{ $s === null ? 'nie sprawdzono' : $l }}</div></div></td>
            @endforeach
        </tr>
    </table>

    <h2>SEO i technika</h2>
    @php $issues = collect($a['seo']['issues'] ?? []); @endphp
    @if($issues->isEmpty())
        <p class="ink2">{{ isset($a['seo']['error']) ? 'Nie udało się sprawdzić.' : 'Nie znaleziono problemów.' }}</p>
    @else
        @foreach($issues->take($maxIssues) as $i)
            <div class="row"><span class="mark" style="color: {{ $sev[$i['severity']][1] }}">{{ $i['severity'] === 'critical' || $i['severity'] === 'high' ? '!' : '·' }}</span><strong>{{ $i['title'] }}</strong>
                <span class="muted">· {{ $sev[$i['severity']][0] }}{{ $i['scope'] === 'pages' ? ' · '.$i['count'].' '.($i['count'] === 1 ? 'adres' : 'adresów') : '' }}</span>
                <div class="fix">{{ $i['description'] }}</div></div>
        @endforeach
        @if($issues->count() > $maxIssues)<p class="muted">… i {{ $issues->count() - $maxIssues }} innych uwag.</p>@endif
    @endif

    <h2>Szybkość</h2>
    @if(! empty($a['speed']['pagespeed']))
        @php $ps = $a['speed']['pagespeed']; @endphp
        <p>Google PageSpeed (telefon): <strong>{{ $ps['performance'] }}/100</strong> · największy element (LCP): <strong>{{ number_format($ps['lcp_s'], 1, ',', '') }} s</strong> (dobrze: do 2,5 s) · przesunięcia układu (CLS): <strong>{{ $ps['cls'] }}</strong></p>
    @else
        <p>Średni czas odpowiedzi serwera: <strong>{{ ! empty($a['speed']['avg_ms']) ? number_format($a['speed']['avg_ms'], 0, ',', ' ').' ms' : '–' }}</strong> <span class="muted">(dobrze: poniżej 600 ms)</span></p>
    @endif

    <h2>Pomiar i marketing</h2>
    <p class="muted" style="margin:0 0 6px">Czy strona mierzy ruch i kontakty z reklam (Google Analytics, Menedżer tagów, piksel Meta, zgody na cookies). Poprawnych elementów: {{ $okCount($a['tracking']['checks'] ?? []) }}.</p>
    @forelse($problems($a['tracking']['checks'] ?? [])->take($maxIssues) as $c)
        <div class="row"><span class="mark" style="color: {{ $statusMark[$c['status']][1] }}">{{ $statusMark[$c['status']][0] }}</span><strong>{{ $c['label'] }}</strong> <span class="muted">– {{ $c['details'] }}</span></div>
    @empty
        <p class="ink2">{{ isset($a['tracking']['error']) ? 'Nie udało się sprawdzić (strona mogła zablokować skanowanie).' : 'Pomiar wygląda poprawnie.' }}</p>
    @endforelse

    <h2>Bezpieczeństwo</h2>
    <p class="muted" style="margin:0 0 6px">Publicznie widoczne elementy bezpieczeństwa (bez prób włamania). Poprawnych elementów: {{ $okCount($a['security']['checks'] ?? []) }}.</p>
    @forelse($problems($a['security']['checks'] ?? []) as $c)
        <div class="row"><span class="mark" style="color: {{ $statusMark[$c['status']][1] }}">{{ $statusMark[$c['status']][0] }}</span><strong>{{ $c['label'] }}</strong> <span class="muted">– {{ $c['details'] }}</span></div>
    @empty
        <p class="ink2">Nie znaleziono problemów.</p>
    @endforelse

    <div class="cta">
        <strong>Chcesz, żebyśmy to poprawili?</strong><br>
        <span class="ink2">Przygotujemy plan i wycenę poprawek – od szybkości i SEO po pomiar reklam i bezpieczeństwo.</span><br>
        <strong>{{ collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') ?: $agency }}</strong>
    </div>

    <div class="footer">
        Audyt automatyczny wykonany {{ $audit->finished_at?->format('d.m.Y H:i') }} przez {{ $agency }}. Sprawdzono do {{ config('adpilot.site_audit.prospect_pages') }} podstron i publicznie dostępne elementy strony.
    </div>
</div>
</body>
</html>
