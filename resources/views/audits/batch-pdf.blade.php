@php
    $tone = fn ($v) => $v === null ? '#8a8984' : ($v >= 90 ? '#0a7d0a' : ($v >= 70 ? '#3b8a1c' : ($v >= 50 ? '#b55a1a' : '#b42f2f')));
    $brandColor = $brand['color'] ?: '#2a78d6';
    $navy = '#1f3a5f';
    $sevName = ['pilne' => 'PILNE', 'srednie' => 'ŚREDNIE', 'drobne' => 'DROBNE'];
    $sevBg = ['pilne' => '#c0392b', 'srednie' => '#d4880f', 'drobne' => '#3a6ea5'];
    $pl = fn (int $n, string $one, string $few, string $many) => $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    $agency = $brand['name'];
    $date = ($batch->finished_at ?? now())->format('d.m.Y');
    $done = collect($sites)->where('state', 'done');
    $ranking = $done->sortBy('score')->values();
    $other = collect($sites)->where('state', '!=', 'done');
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>{{ $batch->name }}</title>
    <style>
        @page { margin: 16mm 14mm 16mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; color: #1a1a1a; font-size: 8.6px; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        h1 { color: {{ $navy }}; font-size: 21px; margin: 10px 0 4px; }
        h2 { color: {{ $navy }}; font-size: 14px; margin: 16px 0 3px; page-break-after: avoid; }
        h3 { color: {{ $navy }}; font-size: 11px; margin: 12px 0 2px; page-break-after: avoid; }
        .muted { color: #6b7280; }
        .brandbar { height: 4px; background: {{ $brandColor }}; margin-bottom: 10px; }
        .tiles td { width: 20%; padding: 3px; }
        .tile { border: 1px solid #e1e0da; padding: 5px 8px; }
        .tile .v { font-size: 17px; font-weight: bold; }
        .list th { text-align: left; font-size: 7.6px; color: #6b7280; padding: 3px 4px; border-bottom: 1px solid #c9ced6; }
        .list td { padding: 3px 4px; border-bottom: 1px solid #e9ecf0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .sev { color: #fff; font-weight: bold; font-size: 7px; text-align: center; padding: 1px 3px; }
        .site { border-top: 1.5px solid {{ $navy }}; margin-top: 12px; padding-top: 2px; }
        .find td { padding: 3px 5px; border-bottom: 1px solid #e9ecf0; vertical-align: top; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7px; color: #8a8984; border-top: 1px solid #e1e0da; padding-top: 3px; }
    </style>
</head>
<body>
<div class="footer">{{ $batch->name }} – audyt zbiorczy | {{ $date }} · {{ $agency }}</div>
<div class="brandbar"></div>
<strong style="font-size: 12px">{{ $agency }}</strong>
<h1>{{ str_contains(mb_strtolower($batch->name), 'audyt') ? $batch->name : $batch->name.' – audyt zbiorczy' }}</h1>
<div class="muted">{{ $done->count() }} {{ $pl($done->count(), 'strona', 'strony', 'stron') }} · treść, kod, zgodność z prawem, wydajność, bezpieczeństwo · stan na {{ $date }}</div>

<h2>Podsumowanie</h2>
<table class="tiles">
    <tr>
        <td><div class="tile"><div class="muted">Średnia ocena</div><div class="v" style="color: {{ $tone($stats['avg_score']) }}">{{ $stats['avg_score'] ?? '–' }}/100</div></div></td>
        <td><div class="tile"><div class="muted">Pilne</div><div class="v" style="color: {{ $sevBg['pilne'] }}">{{ $stats['counts']['pilne'] }}</div></div></td>
        <td><div class="tile"><div class="muted">Średnie</div><div class="v" style="color: {{ $sevBg['srednie'] }}">{{ $stats['counts']['srednie'] }}</div></div></td>
        <td><div class="tile"><div class="muted">Drobne</div><div class="v" style="color: {{ $sevBg['drobne'] }}">{{ $stats['counts']['drobne'] }}</div></div></td>
        <td><div class="tile"><div class="muted">Do potwierdzenia</div><div class="v">{{ $stats['verify'] }}</div></div></td>
    </tr>
</table>
<p class="muted" style="margin: 5px 0">
    Ranking stron, najczęstsze problemy, a dalej każda strona: sprawy pilne (z opisem) i średnie (z zaleceniem). Drobne uwagi i pełne opisy
    są w pełnych raportach poszczególnych stron ({{ $reportUrl }}).
    PILNE – traci klientów lub pieniądze, wprowadza w błąd albo niesie ryzyko prawne; ŚREDNIE – wyraźnie utrudnia decyzję lub kontakt.
    Audyt czyta kod strony – punkty „do potwierdzenia” warto sprawdzić w przeglądarce.
</p>

@if($ranking->isNotEmpty())
    <h2>Strony – od najsłabszej</h2>
    <table class="list">
        <tr>
            <th>Strona</th><th class="num">Ocena</th>
            @foreach($areas as $label)<th class="num">{{ $label }}</th>@endforeach
            <th class="num">Pilne</th><th class="num">Średnie</th><th class="num">Drobne</th>
        </tr>
        @foreach($ranking as $s)
            <tr>
                <td>{{ $s['label'] }}@if($s['company'])<span class="muted"> · {{ \Illuminate\Support\Str::limit($s['company'], 40) }}</span>@endif</td>
                <td class="num" style="font-weight: bold; color: {{ $tone($s['score']) }}">{{ $s['score'] }}</td>
                @foreach($areas as $k => $label)<td class="num" style="color: {{ $tone($s['areas'][$k] ?? null) }}">{{ $s['areas'][$k] ?? '–' }}</td>@endforeach
                <td class="num" style="font-weight: bold; color: {{ $sevBg['pilne'] }}">{{ $s['counts']['pilne'] ?? 0 }}</td>
                <td class="num">{{ $s['counts']['srednie'] ?? 0 }}</td>
                <td class="num muted">{{ $s['counts']['drobne'] ?? 0 }}</td>
            </tr>
        @endforeach
    </table>
    @if($other->isNotEmpty())
        <p class="muted">Bez wyniku: {{ $other->map(fn ($s) => $s['label'].' ('.($s['state'] === 'failed' ? 'audyt nieudany' : 'nie sprawdzono').')')->implode('; ') }}.</p>
    @endif
@endif

@if($common)
    <h2>Najczęstsze problemy</h2>
    <table class="list">
        <tr><th style="width: 46px">Ważność</th><th>Problem</th><th class="num">Stron</th><th style="width: 44%">Których</th></tr>
        @foreach($common as $c)
            <tr>
                <td class="sev" style="background: {{ $sevBg[$c['sev']] }}">{{ $sevName[$c['sev']] }}</td>
                <td>{{ $c['title'] }}</td>
                <td class="num"><strong>{{ $c['count'] }}</strong> z {{ $stats['sites'] }}</td>
                <td class="muted">{{ implode(', ', array_slice($c['sites'], 0, 10)) }}{{ count($c['sites']) > 10 ? ' i '.(count($c['sites']) - 10).' innych' : '' }}</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>Strona po stronie</h2>
@foreach($done as $s)
    <div class="site">
        <table>
            <tr>
                <td><h3 style="margin-top: 4px">{{ $loop->iteration }}. {{ $s['label'] }}</h3></td>
                <td style="text-align: right; font-size: 12px; font-weight: bold; color: {{ $tone($s['score']) }}">{{ $s['score'] }}/100</td>
            </tr>
        </table>
        <div class="muted">
            @foreach($areas as $k => $label){{ $label }}: {{ $s['areas'][$k] ?? '–' }}{{ $loop->last ? '' : ' · ' }}@endforeach
            | uwagi: {{ $s['counts']['pilne'] ?? 0 }} pilnych, {{ $s['counts']['srednie'] ?? 0 }} średnich, {{ $s['counts']['drobne'] ?? 0 }} drobnych
        </div>
        <div class="muted">{{ preg_replace('#^https?://#', '', $s['url']) }}@if(count($s['pages']) > 1) · sprawdzone podstrony: {{ collect($s['pages'])->map(fn ($p) => $p['label'].($p['ok'] ? '' : ' (nie działa)'))->implode(', ') }}@endif</div>
        @if($s['summary'])<p style="margin: 3px 0">{{ $s['summary'] }}</p>@endif
        @if($s['pilne'])
            <div style="font-weight: bold; color: {{ $sevBg['pilne'] }}; margin-top: 4px">Pilne</div>
            <table class="find">
                @foreach($s['pilne'] as $f)
                    <tr>
                        <td style="width: 26px" class="muted">{{ $f['id'] }}</td>
                        <td>
                            <strong>{{ $f['title'] }}</strong>{{ $f['verify'] ? ' (do potwierdzenia)' : '' }}
                            <div>{{ \Illuminate\Support\Str::limit($f['text'], 520) }}</div>
                            @if($f['fix'])<div><strong>Co zrobić:</strong> {{ \Illuminate\Support\Str::limit($f['fix'], 320) }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @else
            <div class="muted" style="margin-top: 2px">Bez spraw pilnych.</div>
        @endif
        @if($s['srednie'])
            <div style="font-weight: bold; color: {{ $sevBg['srednie'] }}; margin-top: 4px">Średnie</div>
            <table class="find">
                @foreach($s['srednie'] as $f)
                    <tr>
                        <td style="width: 26px" class="muted">{{ $f['id'] }}</td>
                        <td>
                            <strong>{{ $f['title'] }}</strong>{{ $f['verify'] ? ' (do potwierdzenia)' : '' }}
                            @if($f['fix'])<div>Co zrobić: {{ \Illuminate\Support\Str::limit($f['fix'], 260) }}</div>@endif
                        </td>
                        <td style="width: 22%" class="muted">{{ $f['section'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endforeach
</body>
</html>
