@php
    $k = $d['kpis'];
    $cur = $d['client']['currency'];
    $money = fn ($v, $dec = 0) => number_format((float) $v, $dec, ',', ' ') . ' ' . $cur;
    $num = fn ($v, $dec = 0) => number_format((float) $v, $dec, ',', ' ');
    $pct = fn ($v) => $v === null ? '–' : number_format($v * 100, 0, ',', ' ') . '%';
    // Zmiana: kolor tylko dla tekstu + strzałka (nigdy sam kolor)
    $delta = function ($d, $inverse = false) {
        if ($d === null) return '<span class="muted">brak porównania</span>';
        $good = $inverse ? $d < 0 : $d > 0;
        $arrow = $d > 0 ? '▲' : ($d < 0 ? '▼' : '■');
        return '<span class="' . ($good ? 'up' : 'down') . '">' . $arrow . ' ' . ($d > 0 ? '+' : '') . number_format($d, 1, ',', '') . '%</span> <span class="muted">vs poprz. okres</span>';
    };
    $ads = $k['ads'];
    $an = $k['analytics'];
    $channels = collect($d['channels']);
    $maxSessions = max(1, $channels->max('sessions'));
    $campaigns = collect($d['campaigns'])->filter(fn ($c) => $c['cost'] > 0);
    $maxCost = max(1, $campaigns->max('cost'));
    $platforms = $k['platforms'];
    $pname = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads'];
    $goal = $d['client']['goal'] ?? 'sales';
    $leads = $goal === 'leads';
    $convLabel = $leads ? 'Leady' : ($goal === 'mixed' ? 'Konwersje' : 'Zamówienia');
    $eventNames = ['generate_lead' => 'formularze', 'form_submit' => 'wysłane formularze', 'sign_up' => 'rejestracje', 'contact' => 'kontakt', 'phone_click' => 'kliknięcia w telefon', 'click_to_call' => 'kliknięcia w telefon', 'submit_lead_form' => 'formularze'];
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>Raport – {{ $d['client']['name'] }} – {{ $report->period_start->format('d.m.Y') }}–{{ $report->period_end->format('d.m.Y') }}</title>
    <style>
        :root { --surface: #fcfcfb; --card: #ffffff; --ink: #0b0b0b; --ink2: #52514e; --muted: #8a8984; --line: #e6e5e0;
                --google: #2a78d6; --meta: #eb6834; --bar: #2a78d6; --brand: {{ $brand['color'] }}; --good: #0a7d0a; --bad: #b42f2f; }
        * { box-sizing: border-box; }
        body { font-family: {!! $pdf ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' !!};
               color: var(--ink); background: var(--surface); margin: 0; font-size: {{ $pdf ? '10px' : '15px' }}; line-height: 1.5; }
        .wrap { max-width: 960px; margin: 0 auto; padding: {{ $pdf ? '0' : '32px 20px 60px' }}; }
        h1 { font-size: {{ $pdf ? '20px' : '28px' }}; margin: 0 0 4px; }
        h2 { font-size: {{ $pdf ? '13px' : '19px' }}; margin: 28px 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
        .muted { color: var(--muted); }
        .ink2 { color: var(--ink2); }
        .up { color: var(--good); font-weight: 600; }
        .down { color: var(--bad); font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        .tiles td { width: 25%; vertical-align: top; padding: 4px; }
        .tile { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 12px; }
        .tile .label { color: var(--ink2); font-size: 0.85em; }
        .tile .value { font-size: {{ $pdf ? '16px' : '24px' }}; font-weight: 700; margin: 2px 0; }
        .tile .delta { font-size: 0.8em; }
        .data th { text-align: left; color: var(--ink2); font-weight: 600; font-size: 0.85em; border-bottom: 1px solid var(--line); padding: 6px 6px; }
        .data td { padding: 6px 6px; border-bottom: 1px solid var(--line); vertical-align: middle; }
        .num { text-align: right; white-space: nowrap; }
        .bar-track { width: 100%; }
        .bar { height: 10px; background: var(--bar); border-radius: 0 4px 4px 0; }
        .dot { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 6px; }
        .note { border-left: 4px solid var(--brand); background: var(--card); padding: 10px 16px; border-radius: 0 8px 8px 0; white-space: pre-line; }
        .logo { max-height: 44px; max-width: 200px; margin-bottom: 8px; }
        .summary { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 4px 18px; }
        .summary h2 { border: 0; margin-top: 14px; font-size: {{ $pdf ? '12px' : '17px' }}; }
        .tag { display: inline-block; font-size: 0.75em; padding: 1px 7px; border-radius: 10px; background: #efeee9; color: var(--ink2); }
        .header td { vertical-align: bottom; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; background: var(--brand); color: #fff; text-decoration: none; font-size: 14px; }
        .footer { margin-top: 36px; color: var(--muted); font-size: 0.8em; }
        ul { padding-left: 18px; margin: 6px 0; }
        li { margin: 3px 0; }
        @media (max-width: 700px) { .tiles td { display: block; width: 100%; } .hide-sm { display: none; } }
        @if($pdf) .page-break { page-break-before: always; } @endif
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
                <div class="muted">{{ $agency }} · Raport z działań reklamowych</div>
                <h1>{{ $d['client']['name'] }}</h1>
                <div class="ink2">Okres: <strong>{{ $report->period_start->format('d.m.Y') }} – {{ $report->period_end->format('d.m.Y') }}</strong>
                    <span class="muted">(porównanie: {{ \Carbon\Carbon::parse($k['period']['prev_from'])->format('d.m') }} – {{ \Carbon\Carbon::parse($k['period']['prev_to'])->format('d.m.Y') }})</span></div>
            </td>
            @unless($pdf)
                <td style="text-align:right"><a class="btn" href="{{ $pdfUrl }}">Pobierz PDF</a></td>
            @endunless
        </tr>
    </table>

    <h2>Najważniejsze wyniki reklam</h2>
    @if($leads)
    <table class="tiles">
        <tr>
            <td><div class="tile"><div class="label">Wydatki na reklamy</div><div class="value">{{ $money($ads['cost']['value']) }}</div><div class="delta">{!! $delta($ads['cost']['delta']) !!}</div></div></td>
            <td><div class="tile"><div class="label">Pozyskane kontakty (leady)</div><div class="value">{{ $num($ads['conversions']['value']) }}</div><div class="delta">{!! $delta($ads['conversions']['delta']) !!}</div></div></td>
            <td><div class="tile"><div class="label">Koszt pozyskania kontaktu</div><div class="value">{{ $ads['cpa']['value'] ? $money($ads['cpa']['value'], 2) : '–' }}</div><div class="delta">{!! $delta($ads['cpa']['delta'], true) !!}</div></div></td>
            <td><div class="tile"><div class="label">Kliknięcia w reklamy</div><div class="value">{{ $num($ads['clicks']['value']) }}</div><div class="delta">{!! $delta($ads['clicks']['delta']) !!}</div></div></td>
        </tr>
        <tr>
            <td><div class="tile"><div class="label">Wejścia na stronę (wszystkie źródła)</div><div class="value">{{ $k['has_analytics'] ? $num($an['sessions']['value']) : '–' }}</div><div class="delta">{!! $k['has_analytics'] ? $delta($an['sessions']['delta']) : '' !!}</div></div></td>
            <td><div class="tile"><div class="label">Formularze, rejestracje, telefony</div><div class="value">{{ $k['has_analytics'] ? $num($an['leads']['value']) : '–' }}</div><div class="delta">{!! $k['has_analytics'] ? $delta($an['leads']['delta']) : '' !!}</div></div></td>
            <td><div class="tile"><div class="label">Średni koszt kliknięcia</div><div class="value">{{ $money($ads['cpc']['value'], 2) }}</div><div class="delta">{!! $delta($ads['cpc']['delta'], true) !!}</div></div></td>
            <td><div class="tile"><div class="label">CTR (klikalność)</div><div class="value">{{ number_format($ads['ctr']['value'], 2, ',', '') }}%</div><div class="delta">{!! $delta($ads['ctr']['delta']) !!}</div></div></td>
        </tr>
    </table>
    @if(count($k['events'] ?? []))
        <p class="ink2">Rodzaje kontaktów (Google Analytics):
            @foreach($k['events'] as $e){{ $loop->first ? '' : ', ' }}{{ $eventNames[$e['event_name']] ?? $e['event_name'] }}: <strong>{{ $num($e['count']) }}</strong>@endforeach
        </p>
    @endif
    <p class="muted" style="font-size:0.8em">Lead = osoba, która wysłała formularz, zarejestrowała się lub zadzwoniła. Koszt pozyskania kontaktu = wydatki ÷ liczba leadów.</p>
    @else
    <table class="tiles">
        <tr>
            <td><div class="tile"><div class="label">Wydatki na reklamy</div><div class="value">{{ $money($ads['cost']['value']) }}</div><div class="delta">{!! $delta($ads['cost']['delta']) !!}</div></div></td>
            <td><div class="tile"><div class="label">Przychód z reklam</div><div class="value">{{ $money($ads['conversion_value']['value']) }}</div><div class="delta">{!! $delta($ads['conversion_value']['delta']) !!}</div></div></td>
            <td><div class="tile"><div class="label">ROAS (zwrot z reklam)</div><div class="value">{{ $pct($ads['roas']['value']) }}</div><div class="delta">{!! $delta($ads['roas']['delta']) !!}</div></div></td>
            <td><div class="tile"><div class="label">Zamówienia z reklam</div><div class="value">{{ $num($ads['conversions']['value']) }}</div><div class="delta">{!! $delta($ads['conversions']['delta']) !!}</div></div></td>
        </tr>
        <tr>
            <td><div class="tile"><div class="label">Koszt pozyskania zamówienia</div><div class="value">{{ $ads['cpa']['value'] ? $money($ads['cpa']['value'], 2) : '–' }}</div><div class="delta">{!! $delta($ads['cpa']['delta'], true) !!}</div></div></td>
            <td><div class="tile"><div class="label">Kliknięcia</div><div class="value">{{ $num($ads['clicks']['value']) }}</div><div class="delta">{!! $delta($ads['clicks']['delta']) !!}</div></div></td>
            <td><div class="tile"><div class="label">Średni koszt kliknięcia</div><div class="value">{{ $money($ads['cpc']['value'], 2) }}</div><div class="delta">{!! $delta($ads['cpc']['delta'], true) !!}</div></div></td>
            <td><div class="tile"><div class="label">CTR (klikalność)</div><div class="value">{{ number_format($ads['ctr']['value'], 2, ',', '') }}%</div><div class="delta">{!! $delta($ads['ctr']['delta']) !!}</div></div></td>
        </tr>
    </table>
    <p class="muted" style="font-size:0.8em">ROAS = przychód przypisany reklamom ÷ koszt reklam. 500% oznacza 5 zł przychodu z każdej złotówki wydanej na reklamy.</p>
    @endif

    <h2>Podział na platformy</h2>
    <table class="data">
        <tr><th>Platforma</th><th class="num">Wydatki</th><th class="num">Kliknięcia</th><th class="num">{{ $convLabel }}</th>@unless($leads)<th class="num">Przychód</th><th class="num">ROAS</th>@endunless<th class="num">Koszt {{ $leads ? 'leada' : 'konwersji' }}</th></tr>
        @foreach($platforms as $key => $p)
            @if($p['cost'] > 0)
                <tr>
                    <td><span class="dot" style="background: var(--{{ $key === 'google_ads' ? 'google' : 'meta' }})"></span>{{ $pname[$key] }}</td>
                    <td class="num">{{ $money($p['cost']) }}</td>
                    <td class="num">{{ $num($p['clicks']) }}</td>
                    <td class="num">{{ $num($p['conversions']) }}</td>
                    @unless($leads)
                    <td class="num">{{ $money($p['conversion_value']) }}</td>
                    <td class="num"><strong>{{ $pct($p['roas']) }}</strong></td>
                    @endunless
                    <td class="num">{{ $p['cpa'] ? $money($p['cpa'], 2) : '–' }}</td>
                </tr>
            @endif
        @endforeach
    </table>
    <p class="muted" style="font-size:0.8em">Każda platforma liczy {{ $leads ? 'kontakty' : 'zamówienia' }} według własnego modelu (np. Meta uwzględnia też wyświetlenia reklamy bez kliknięcia), dlatego suma może być wyższa niż {{ $leads ? 'liczba zapytań, które faktycznie otrzymałeś' : 'liczba zamówień w sklepie' }}.</p>

    @if($report->note)
        <h2>Słowo od specjalisty</h2>
        <div class="note">{{ $report->note }}</div>
    @endif

    @if($summary)
        <h2>Komentarz specjalisty</h2>
        <div class="summary">{!! $summary !!}</div>
    @endif

    @if($k['has_analytics'])
        <h2 @if($pdf) class="page-break" @endif>{{ $leads ? 'Ruch na stronie (Google Analytics 4)' : 'Ruch i sprzedaż w sklepie (Google Analytics 4)' }}</h2>
        <table class="tiles">
            <tr>
                <td><div class="tile"><div class="label">Sesje</div><div class="value">{{ $num($an['sessions']['value']) }}</div><div class="delta">{!! $delta($an['sessions']['delta']) !!}</div></div></td>
                <td><div class="tile"><div class="label">Użytkownicy</div><div class="value">{{ $num($an['users']['value']) }}</div><div class="delta">{!! $delta($an['users']['delta']) !!}</div></div></td>
                @if($leads)
                <td><div class="tile"><div class="label">Kontakty ze strony</div><div class="value">{{ $num($an['leads']['value']) }}</div><div class="delta">{!! $delta($an['leads']['delta']) !!}</div></div></td>
                <td><div class="tile"><div class="label">Konwersja strony</div><div class="value">{{ number_format($an['lead_cvr']['value'], 2, ',', '') }}%</div><div class="delta">{!! $delta($an['lead_cvr']['delta']) !!}</div></div></td>
                @else
                <td><div class="tile"><div class="label">Transakcje</div><div class="value">{{ $num($an['transactions']['value']) }}</div><div class="delta">{!! $delta($an['transactions']['delta']) !!}</div></div></td>
                <td><div class="tile"><div class="label">Przychód sklepu</div><div class="value">{{ $money($an['revenue']['value']) }}</div><div class="delta">{!! $delta($an['revenue']['delta']) !!}</div></div></td>
                @endif
            </tr>
        </table>
        @unless($leads)
        <p class="ink2">Współczynnik konwersji sklepu: <strong>{{ number_format($an['cvr']['value'], 2, ',', '') }}%</strong> · średnia wartość zamówienia: <strong>{{ $money($an['aov']['value']) }}</strong></p>
        @endunless

        <h3 style="font-size:1em;margin:18px 0 6px">Skąd przychodzą odwiedzający</h3>
        <table class="data">
            <tr><th>Kanał</th><th style="width:30%" class="hide-sm">Sesje</th><th class="num">Sesje</th>@if($leads)<th class="num">Konwersje</th>@else<th class="num">Transakcje</th><th class="num">Przychód</th>@endif<th class="num">Konwersja</th></tr>
            @foreach($channels->take(10) as $ch)
                <tr>
                    <td>{{ $ch['channel'] }}</td>
                    <td class="hide-sm"><div class="bar-track"><div class="bar" style="width: {{ max(1, round($ch['sessions'] / $maxSessions * 100)) }}%"></div></div></td>
                    <td class="num">{{ $num($ch['sessions']) }}</td>
                    @if($leads)
                    <td class="num">{{ $num($ch['key_events'] ?? 0) }}</td>
                    <td class="num">{{ number_format($ch['key_cvr'] ?? 0, 2, ',', '') }}%</td>
                    @else
                    <td class="num">{{ $num($ch['transactions']) }}</td>
                    <td class="num">{{ $money($ch['revenue']) }}</td>
                    <td class="num">{{ number_format($ch['cvr'], 2, ',', '') }}%</td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    <h2 @if($pdf && ! $k['has_analytics']) class="page-break" @endif>Kampanie</h2>
    <table class="data">
        <tr><th>Kampania</th><th style="width:22%" class="hide-sm">Wydatki</th><th class="num">Wydatki</th><th class="num">{{ $convLabel }}</th>@if($leads)<th class="num">Koszt leada</th>@else<th class="num">Przychód</th><th class="num">ROAS</th>@endif</tr>
        @foreach($campaigns->take(15) as $c)
            <tr>
                <td><span class="dot" style="background: var(--{{ $c['platform'] === 'google_ads' ? 'google' : 'meta' }})"></span>{{ $c['name'] }} <span class="muted" style="font-size:0.8em">{{ $pname[$c['platform']] ?? '' }}</span></td>
                <td class="hide-sm"><div class="bar-track"><div class="bar" style="width: {{ max(1, round($c['cost'] / $maxCost * 100)) }}%; background: var(--{{ $c['platform'] === 'google_ads' ? 'google' : 'meta' }})"></div></div></td>
                <td class="num">{{ $money($c['cost']) }}</td>
                <td class="num">{{ $num($c['conversions'], $leads ? 0 : 1) }}</td>
                @if($leads)
                <td class="num"><strong>{{ $c['cpa'] ? $money($c['cpa'], 2) : '–' }}</strong></td>
                @else
                <td class="num">{{ $money($c['conversion_value']) }}</td>
                <td class="num"><strong>{{ $pct($c['roas']) }}</strong></td>
                @endif
            </tr>
        @endforeach
    </table>

    @if(count($d['top_ads']))
        <h2>Najskuteczniejsze reklamy</h2>
        <table class="data">
            <tr><th>Reklama</th><th>Kampania</th><th class="num">Wydatki</th><th class="num">{{ $convLabel }}</th><th class="num">{{ $leads ? 'Koszt leada' : 'ROAS' }}</th></tr>
            @foreach($d['top_ads'] as $a)
                <tr><td>{{ $a['name'] }}</td><td class="ink2">{{ $a['campaign_name'] }}</td><td class="num">{{ $money($a['cost']) }}</td><td class="num">{{ $num($a['conversions'], $leads ? 0 : 1) }}</td><td class="num"><strong>{{ $leads ? ($a['cpa'] ? $money($a['cpa'], 2) : '–') : $pct($a['roas']) }}</strong></td></tr>
            @endforeach
        </table>
    @endif

    @if(count($d['done']))
        <h2>Co zrobiliśmy w tym okresie</h2>
        <ul>
            @foreach(array_slice($d['done'], 0, 12) as $r)
                <li>{{ $r['title'] }}</li>
            @endforeach
        </ul>
    @endif

    @if(count($d['open']))
        <h2>Plan na kolejny okres</h2>
        <ul>
            @foreach(collect($d['open'])->take(8) as $r)
                <li>{{ $r['title'] }} @if($r['kind'] === 'opportunity')<span class="tag">szansa</span>@endif</li>
            @endforeach
        </ul>
    @endif

    @if($d['tracking'])
        <h2>Jakość pomiaru</h2>
        <p>Wynik audytu pomiaru: <strong>{{ $d['tracking']['score'] }}/100</strong> <span class="muted">({{ \Carbon\Carbon::parse($d['tracking']['date'])->format('d.m.Y') }})</span></p>
        @if(count($d['tracking']['issues']))
            <p class="ink2">Do poprawy: {{ implode(', ', $d['tracking']['issues']) }}.</p>
        @endif
    @endif

    @if($brand['report_footer'])
        <p class="ink2" style="margin-top:28px">{{ $brand['report_footer'] }}</p>
    @endif
    <div class="footer">
        {{ collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') }}<br>
        Raport wygenerowany {{ \Carbon\Carbon::parse($d['generated_at'])->format('d.m.Y H:i') }} przez {{ $agency }}. Dane: Google Ads, Meta Ads, Google Analytics 4.
    </div>
</div>
</body>
</html>
