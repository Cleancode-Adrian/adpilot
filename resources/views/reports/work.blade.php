@php
    $items = collect($d['items'] ?? []);
    $month = \App\Services\Reports\WorkReportBuilder::monthLabel($report->period_start);
    $showTime = (bool) ($d['show_time'] ?? false);
    $total = (int) $items->sum(fn ($i) => (int) ($i['minutes'] ?? 0));
    $fmt = fn (int $m) => $m >= 60 ? intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60).' min' : '') : $m.' min';
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>Raport z prac – {{ $d['client']['name'] }} – {{ $month }}</title>
    <style>
        :root { --surface: #fcfcfb; --card: #ffffff; --ink: #0b0b0b; --ink2: #52514e; --muted: #8a8984; --line: #e6e5e0; --brand: {{ $brand['color'] }}; }
        * { box-sizing: border-box; }
        body { font-family: {!! $pdf ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' !!};
               color: var(--ink); background: var(--surface); margin: 0; font-size: {{ $pdf ? '10px' : '15px' }}; line-height: 1.55; }
        .wrap { max-width: 860px; margin: 0 auto; padding: {{ $pdf ? '0' : '32px 20px 60px' }}; }
        h1 { font-size: {{ $pdf ? '20px' : '28px' }}; margin: 0 0 4px; }
        h2 { font-size: {{ $pdf ? '13px' : '19px' }}; margin: 28px 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
        .muted { color: var(--muted); }
        .ink2 { color: var(--ink2); }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: bottom; }
        .logo { max-height: 44px; max-width: 200px; margin-bottom: 8px; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; background: var(--brand); color: #fff; text-decoration: none; font-size: 14px; }
        .summary { background: var(--card); border: 1px solid var(--line); border-left: 4px solid var(--brand); border-radius: 0 8px 8px 0; padding: 4px 18px; }
        .summary p { margin: 10px 0; }
        .count { display: inline-block; background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 8px 14px; margin-top: 14px; }
        .count strong { font-size: {{ $pdf ? '15px' : '22px' }}; }
        .items td { padding: 10px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .items th { text-align: left; color: var(--ink2); font-weight: 600; font-size: 0.85em; border-bottom: 1px solid var(--line); padding: 6px; }
        .date { white-space: nowrap; color: var(--ink2); width: 1%; }
        .check { color: var(--brand); font-weight: 700; width: 1%; }
        .title { font-weight: 600; }
        .desc { color: var(--ink2); white-space: pre-line; margin-top: 2px; }
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
                <div class="muted">{{ $agency }} · Raport z prac</div>
                <h1>{{ $d['client']['name'] }}</h1>
                <div class="ink2">Miesiąc: <strong>{{ $month }}</strong> <span class="muted">· {{ preg_replace('#^https?://#', '', $d['client']['website_url'] ?? '') }}</span></div>
            </td>
            @unless($pdf)
                <td style="text-align:right"><a class="btn" href="{{ $pdfUrl }}">Pobierz PDF</a></td>
            @endunless
        </tr>
    </table>

    <div class="count">Wykonane prace: <strong>{{ $items->count() }}</strong>@if($showTime && $total) &nbsp;·&nbsp; Czas pracy: <strong>{{ $fmt($total) }}</strong>@endif</div>

    @if($noteHtml)
        <h2>Podsumowanie</h2>
        <div class="summary">{!! $noteHtml !!}</div>
    @endif

    <h2>Lista wykonanych prac</h2>
    @if($items->isEmpty())
        <p class="muted">Brak pozycji.</p>
    @else
        <table class="items">
            <thead>
                <tr><th></th><th>Data</th><th>Zakres prac</th>@if($showTime)<th style="text-align:right">Czas</th>@endif</tr>
            </thead>
            <tbody>
                @foreach($items as $i)
                    <tr>
                        <td class="check">✓</td>
                        <td class="date">{{ $i['date'] ? \Carbon\Carbon::parse($i['date'])->format('d.m.Y') : '' }}</td>
                        <td>
                            <div class="title">{{ $i['title'] }}</div>
                            @if(trim($i['description'] ?? '') !== '')
                                <div class="desc">{{ $i['description'] }}</div>
                            @endif
                        </td>
                        @if($showTime)<td class="date" style="text-align:right">{{ ! empty($i['minutes']) ? $fmt((int) $i['minutes']) : '' }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if($brand['report_footer'])
        <p class="ink2" style="margin-top:28px">{{ $brand['report_footer'] }}</p>
    @endif
    <div class="footer">
        {{ collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') }}<br>
        Raport przygotowany {{ \Carbon\Carbon::parse($report->updated_at)->format('d.m.Y') }} przez {{ $agency }}.
    </div>
</div>
</body>
</html>
