@php
    $issues = collect($audit->issues ?? []);
    $s = $audit->summary ?? [];
    $score = (int) $audit->score;
    [$label, $color] = $score >= 90 ? ['bardzo dobrze', '#0a7d0a'] : ($score >= 70 ? ['dobrze', '#0a7d0a'] : ($score >= 50 ? ['do poprawy', '#a4471f'] : ['słabo', '#b42f2f']));
    $sev = [
        'critical' => ['Krytyczne', '#b42f2f', 'Wymagają szybkiej reakcji – realnie szkodzą widoczności lub odwiedzającym.'],
        'high' => ['Ważne', '#a4471f', 'Wyraźnie obniżają skuteczność strony.'],
        'medium' => ['Średnie', '#7a5600', 'Warto poprawić przy najbliższych pracach.'],
        'low' => ['Drobne', '#52514e', 'Dobre praktyki – małe usprawnienia.'],
    ];
    $maxUrls = $pdf ? 5 : 10;
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>Audyt strony – {{ $client->name }} – {{ $audit->created_at->format('d.m.Y') }}</title>
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
        .tiles td { width: 25%; vertical-align: top; padding: 4px; }
        .tile { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 12px; }
        .tile .label { color: var(--ink2); font-size: 0.85em; }
        .tile .value { font-size: {{ $pdf ? '15px' : '22px' }}; font-weight: 700; }
        .score { font-size: {{ $pdf ? '34px' : '52px' }}; font-weight: 800; line-height: 1; }
        .issue { background: var(--card); border: 1px solid var(--line); border-left: 4px solid var(--c); border-radius: 0 8px 8px 0; padding: 10px 14px; margin: 8px 0; page-break-inside: avoid; }
        .issue .t { font-weight: 700; }
        .issue .tag { display: inline-block; font-size: 0.75em; padding: 1px 7px; border-radius: 10px; border: 1px solid var(--c); color: var(--c); margin-right: 6px; }
        .issue ul { margin: 6px 0 0; padding-left: 16px; color: var(--ink2); font-size: 0.85em; word-break: break-all; }
        .fix { margin-top: 4px; }
        .footer { margin-top: 36px; color: var(--muted); font-size: 0.8em; }
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
                <div class="muted">{{ $agency }} · Audyt strony – SEO i technika</div>
                <h1>{{ $client->name }}</h1>
                <div class="ink2">{{ preg_replace('#^https?://#', '', $audit->url) }} · {{ $audit->created_at->format('d.m.Y') }}</div>
            </td>
            @unless($pdf)
                <td style="text-align:right"><a class="btn" href="{{ $pdfUrl }}">Pobierz PDF</a></td>
            @endunless
        </tr>
    </table>

    <h2>Wynik</h2>
    <table class="tiles">
        <tr>
            <td><div class="tile"><div class="label">Ocena strony</div><div class="score" style="color: {{ $color }}">{{ $score }}<span class="muted" style="font-size:0.4em">/100</span></div><div style="color: {{ $color }}; font-weight:600">{{ $label }}</div></div></td>
            <td><div class="tile"><div class="label">Sprawdzone podstrony</div><div class="value">{{ $audit->pages_crawled }}</div><div class="muted">{{ ($s['sitemap_urls'] ?? 0) ? 'mapa strony: '.$s['sitemap_urls'].' adresów' : 'brak mapy strony' }}</div></div></td>
            <td><div class="tile"><div class="label">Średni czas odpowiedzi</div><div class="value">{{ ! empty($s['avg_ms']) ? number_format($s['avg_ms'], 0, ',', ' ').' ms' : '–' }}</div><div class="muted">{{ ($s['https'] ?? false) ? 'HTTPS ✓' : 'bez HTTPS' }}</div></div></td>
            <td><div class="tile"><div class="label">Dostępność (30 dni)</div><div class="value">{{ $uptime !== null ? number_format($uptime, 2, ',', ' ').'%' : '–' }}</div><div class="muted">{{ $uptime !== null ? 'monitoring co 5 min' : 'monitoring niewłączony' }}</div></div></td>
        </tr>
    </table>

    @if($issues->isEmpty())
        <p>Nie znaleziono problemów – strona jest w bardzo dobrej kondycji.</p>
    @endif

    @foreach($sev as $key => [$name, $c, $desc])
        @php $list = $issues->where('severity', $key); @endphp
        @if($list->isNotEmpty())
            <h2>{{ $name }} ({{ $list->count() }})</h2>
            <p class="muted" style="margin:0 0 6px">{{ $desc }}</p>
            @foreach($list as $i)
                <div class="issue" style="--c: {{ $c }}">
                    <div class="t"><span class="tag">{{ $name }}</span>{{ $i['title'] }}@if($i['scope'] === 'pages') <span class="muted">· {{ $i['count'] }} {{ $i['count'] === 1 ? 'adres' : 'adresów' }}</span>@endif</div>
                    <div class="ink2">{{ $i['description'] }}</div>
                    <div class="fix"><strong>Rekomendacja:</strong> {{ $i['action'] }}</div>
                    @if(! empty($i['urls']))
                        <ul>
                            @foreach(array_slice($i['urls'], 0, $maxUrls) as $u)
                                <li>{{ $u }}</li>
                            @endforeach
                            @if($i['count'] > $maxUrls)<li>… i {{ $i['count'] - $maxUrls }} więcej</li>@endif
                        </ul>
                    @endif
                </div>
            @endforeach
        @endif
    @endforeach

    @if($brand['report_footer'])
        <p class="ink2" style="margin-top:28px">{{ $brand['report_footer'] }}</p>
    @endif
    <div class="footer">
        {{ collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') }}<br>
        Audyt wykonany automatycznie {{ $audit->finished_at?->format('d.m.Y H:i') }} przez {{ $agency }}. Sprawdzono do {{ config('adpilot.site_audit.max_pages') }} podstron.
    </div>
</div>
</body>
</html>
