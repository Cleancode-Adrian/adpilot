@php
    $cur = $d['currency'];
    $money = fn ($v) => $v === null ? '–' : number_format((float) $v, 2, ',', ' ').' '.$cur;
    $fmt = fn (int $m) => $m >= 60 ? intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60).' min' : '') : $m.' min';
    $t = $d['totals'];
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Rozliczenie – {{ $agency->name }} – {{ $d['month_label'] }}</title>
    <style>
        :root { --ink: #0b0b0b; --ink2: #52514e; --muted: #8a8984; --line: #e6e5e0; --brand: {{ $brand['color'] }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; color: #0b0b0b; margin: 0; font-size: 10px; line-height: 1.5; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 13px; margin: 22px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #e6e5e0; }
        .muted { color: #8a8984; }
        .ink2 { color: #52514e; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: bottom; }
        .logo { max-height: 44px; max-width: 200px; margin-bottom: 8px; }
        .list th { text-align: left; font-weight: 600; color: #52514e; border-bottom: 1px solid #e6e5e0; padding: 5px 6px; }
        .list td { border-bottom: 1px solid #f0efea; padding: 5px 6px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .tiles td { width: 25%; padding: 0 6px 0 0; }
        .tile { border: 1px solid #e6e5e0; border-top: 3px solid {{ $brand['color'] }}; border-radius: 6px; padding: 8px 10px; }
        .tile .label { color: #8a8984; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        .tile .value { font-size: 15px; font-weight: 700; }
        .total td { font-weight: 700; border-top: 2px solid #0b0b0b; border-bottom: 0; }
        .tag { font-size: 8px; color: #8a8984; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if($brand['logo_path'] && is_file($brand['logo_path']))
                    <img class="logo" src="data:{{ mime_content_type($brand['logo_path']) }};base64,{{ base64_encode(file_get_contents($brand['logo_path'])) }}" alt="">
                @endif
                <div class="muted">{{ $brand['name'] }} · Zestawienie prac do rozliczenia</div>
                <h1>{{ $agency->name }} – {{ $d['month_label'] }}</h1>
                @if($agency->tax_id)<div class="ink2">NIP: {{ $agency->tax_id }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="tiles" style="margin-top: 14px">
        <tr>
            <td><div class="tile"><div class="label">Wykonane prace</div><div class="value">{{ $t['tasks'] }}</div></div></td>
            <td><div class="tile"><div class="label">Czas pracy</div><div class="value">{{ $fmt($t['minutes']) }}</div></div></td>
            <td><div class="tile"><div class="label">Wartość prac</div><div class="value">{{ $money($t['work']) }}</div></div></td>
            <td><div class="tile"><div class="label">Abonamenty i odnowienia</div><div class="value">{{ $money($t['subscriptions']) }}</div></div></td>
        </tr>
    </table>
    <p class="ink2">
        Kwoty netto. Wartość pracy: kwota ustalona dla zadania, a gdy jej brak – czas pracy × stawka
        {{ $d['rate'] ? $money($d['rate']).'/h' : '(stawka godzinowa nie została ustalona)' }}.
    </p>

    @if(count($d['sites']))
        <h2>Podsumowanie wg stron</h2>
        <table class="list">
            <tr><th>Strona</th><th class="num">Prace</th><th class="num">Czas</th><th class="num">Wartość</th></tr>
            @foreach($d['sites'] as $s)
                <tr><td>{{ $s['client'] }}</td><td class="num">{{ $s['tasks'] }}</td><td class="num">{{ $fmt($s['minutes']) }}</td><td class="num">{{ $money($s['value']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>Wykonane prace</h2>
    @if(count($d['tasks']))
        <table class="list">
            <tr><th>Data</th><th>Strona</th><th>Zadanie</th><th class="num">Czas</th><th class="num">Wartość</th></tr>
            @foreach($d['tasks'] as $r)
                <tr>
                    <td style="white-space:nowrap">{{ \Carbon\Carbon::parse($r['done_at'])->format('d.m') }}</td>
                    <td>{{ $r['client'] }}</td>
                    <td>{{ $r['title'] }}@if($r['note'])<div class="ink2">{{ \Illuminate\Support\Str::limit($r['note'], 160) }}</div>@endif</td>
                    <td class="num">{{ $r['minutes'] ? $fmt($r['minutes']) : '–' }}</td>
                    <td class="num">{{ $money($r['value']) }}@if($r['source'] === 'time')<div class="tag">wg stawki</div>@endif</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="3">Razem prace</td><td class="num">{{ $fmt($t['minutes']) }}</td><td class="num">{{ $money($t['work']) }}</td></tr>
        </table>
    @else
        <p class="muted">Brak wykonanych prac w tym miesiącu.</p>
    @endif

    @if(count($d['subscriptions']))
        <h2>Abonamenty i odnowienia w tym miesiącu</h2>
        <table class="list">
            <tr><th>Termin</th><th>Strona</th><th>Pozycja</th><th>Okres</th><th class="num">Kwota</th></tr>
            @foreach($d['subscriptions'] as $b)
                <tr>
                    <td style="white-space:nowrap">{{ \Carbon\Carbon::parse($b['next_date'])->format('d.m') }}</td>
                    <td>{{ $b['client'] }}</td>
                    <td>{{ $b['type'] }}: {{ $b['name'] }}</td>
                    <td>{{ $b['period'] }}</td>
                    <td class="num">{{ $b['amount'] === null ? '–' : number_format((float) $b['amount'], 2, ',', ' ').' '.$b['currency'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table class="list" style="margin-top: 18px">
        <tr class="total"><td>Razem do rozliczenia (prace + abonamenty)</td><td class="num">{{ $money($t['work'] + $t['subscriptions']) }}</td></tr>
    </table>

    <p class="muted" style="margin-top: 24px">
        {{ collect([$brand['name'], $brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') }}<br>
        Zestawienie przygotowane {{ now()->format('d.m.Y') }}.
    </p>
</body>
</html>
