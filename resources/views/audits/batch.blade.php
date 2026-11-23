@php
    $tone = function ($v) {
        if ($v === null) return '#8a8984';
        return $v >= 90 ? '#0a7d0a' : ($v >= 70 ? '#3b8a1c' : ($v >= 50 ? '#b55a1a' : '#b42f2f'));
    };
    $brandColor = $brand['color'] ?: '#2a78d6';
    $navy = '#1f3a5f';
    $sevName = ['pilne' => 'PILNE', 'srednie' => 'ŚREDNIE', 'drobne' => 'DROBNE'];
    $sevBg = ['pilne' => '#c0392b', 'srednie' => '#d4880f', 'drobne' => '#3a6ea5'];
    $pl = fn (int $n, string $one, string $few, string $many) => $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    $agency = $brand['name'];
    $date = ($batch->finished_at ?? now())->format('d.m.Y');
    $doneSites = collect($sites)->where('state', 'done');
    $ranking = $doneSites->sortBy('score')->values();
    $contact = collect([$brand['email'], $brand['phone'], $brand['website']])->filter();
    $anchor = fn (string $label) => 's-'.\Illuminate\Support\Str::slug($label);
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>{{ $batch->name }} – audyt zbiorczy – {{ $agency }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1a1a1a; background: #f6f6f4; margin: 0; font-size: 15px; line-height: 1.5; }
        .wrap { max-width: 1000px; margin: 24px auto; background: #fff; padding: 36px 44px 48px; border-radius: 12px; border: 1px solid #e6e5e0; }
        a { color: {{ $navy }}; }
        .muted { color: #6b7280; } .small { font-size: 0.85em; }
        table { width: 100%; border-collapse: collapse; }
        .brandbar { height: 5px; background: {{ $brandColor }}; margin-bottom: 14px; }
        .logo { max-height: 40px; max-width: 190px; }
        h1 { color: {{ $navy }}; font-size: 30px; line-height: 1.15; margin: 16px 0 6px; }
        h2 { color: {{ $navy }}; font-size: 22px; margin: 34px 0 4px; }
        h3 { color: {{ $navy }}; font-size: 17px; margin: 16px 0 4px; }
        .subtitle { color: #5b6573; font-size: 1.05em; }
        .note { color: #6b7280; font-style: italic; font-size: 0.92em; margin: 6px 0; }
        .tiles td { width: 20%; padding: 4px; vertical-align: top; }
        .tile { border: 1px solid #ecebe6; border-radius: 8px; padding: 8px 12px; }
        .tile .v { font-size: 26px; font-weight: bold; line-height: 1.2; }
        .list th { text-align: left; font-size: 0.8em; color: #6b7280; padding: 5px 6px; border-bottom: 1px solid #d9dde3; }
        .list td { padding: 5px 6px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .sev { display: inline-block; min-width: 62px; text-align: center; color: #fff; font-weight: bold; font-size: 0.72em; border-radius: 4px; padding: 1px 6px; }
        .site { border-top: 2px solid {{ $navy }}; margin-top: 28px; padding-top: 4px; page-break-inside: auto; }
        .site h2 { margin-top: 8px; }
        .score { font-size: 26px; font-weight: bold; }
        .find { border-bottom: 1px solid #e5e7eb; }
        .find td { vertical-align: top; padding: 6px 8px; }
        .find td.s { width: 78px; color: #fff; font-weight: bold; font-size: 0.78em; text-align: center; padding-top: 8px; }
        .fid { color: #6b7280; font-weight: normal; }
        .verify { display: inline-block; font-size: 0.75em; font-weight: bold; color: #7a5600; background: #fff4d6; border: 1px solid #f0d58a; border-radius: 6px; padding: 0 5px; }
        .fix { margin-top: 3px; } .fix b { color: {{ $navy }}; }
        .btn { display: inline-block; padding: 7px 13px; border-radius: 6px; background: {{ $brandColor }}; color: #fff; text-decoration: none; font-size: 14px; }
        .btn.ghost { background: #fff; color: {{ $navy }}; border: 1px solid #cfd5dd; }
        .footer { margin-top: 28px; font-size: 0.8em; color: #8a8984; border-top: 1px solid #ecebe6; padding-top: 8px; }
        @media print {
            body { background: #fff; font-size: 11px; }
            .wrap { border: 0; margin: 0; padding: 0; max-width: none; }
            .noprint { display: none !important; }
            .site { page-break-before: always; }
            h2, h3 { page-break-after: avoid; }
            .find, .list tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brandbar"></div>
    <table>
        <tr>
            <td style="vertical-align: middle">
                @if($brand['logo_url'])
                    <img class="logo" src="{{ $brand['logo_url'] }}" alt="{{ $agency }}">
                @else
                    <strong style="font-size: 1.3em">{{ $agency }}</strong>
                @endif
            </td>
            <td style="text-align: right; vertical-align: middle" class="muted">
                <span class="noprint">
                    <a class="btn ghost" href="{{ $csvUrl }}">Arkusz wszystkich uwag (Excel)</a>
                    <a class="btn ghost" href="{{ $pdfUrl }}">PDF (skrót)</a>
                    <a class="btn" href="#" id="print">Drukuj / zapisz PDF</a><br>
                </span>
                {{ $date }}
            </td>
        </tr>
    </table>

    <h1>{{ $batch->name }} – audyt zbiorczy</h1>
    <div class="subtitle">
        {{ $doneSites->count() }} {{ $pl($doneSites->count(), 'strona', 'strony', 'stron') }} · treść, kod, zgodność z prawem, wydajność, bezpieczeństwo · stan na {{ $date }}
    </div>
    @if($batch->status !== 'done')
        <p class="note">Audyt jeszcze trwa: sprawdzono {{ $progress['done'] + $progress['failed'] }} z {{ $progress['total'] }} stron. Odśwież tę stronę później.</p>
    @endif

    {{-- ============ Podsumowanie ============ --}}
    <h2>Podsumowanie</h2>
    <table class="tiles">
        <tr>
            <td><div class="tile"><div class="muted small">Średnia ocena</div><div class="v" style="color: {{ $tone($stats['avg_score']) }}">{{ $stats['avg_score'] ?? '–' }}<span style="font-size: 0.5em; color: #8a8984">/100</span></div></div></td>
            <td><div class="tile"><div class="muted small">Pilne</div><div class="v" style="color: {{ $sevBg['pilne'] }}">{{ $stats['counts']['pilne'] }}</div></div></td>
            <td><div class="tile"><div class="muted small">Średnie</div><div class="v" style="color: {{ $sevBg['srednie'] }}">{{ $stats['counts']['srednie'] }}</div></div></td>
            <td><div class="tile"><div class="muted small">Drobne</div><div class="v" style="color: {{ $sevBg['drobne'] }}">{{ $stats['counts']['drobne'] }}</div></div></td>
            <td><div class="tile"><div class="muted small">Do potwierdzenia</div><div class="v">{{ $stats['verify'] }}</div></div></td>
        </tr>
    </table>
    <p class="note">
        Oznaczenia: <span style="color: #c0392b; font-weight: bold">PILNE</span> – traci klientów lub pieniądze, wprowadza w błąd albo niesie ryzyko prawne;
        <span style="color: #d4880f; font-weight: bold">ŚREDNIE</span> – wyraźnie utrudnia decyzję lub kontakt;
        <span style="color: #3a6ea5; font-weight: bold">DROBNE</span> – kosmetyka i porządki.
        W tym dokumencie przy każdej stronie są sprawy pilne (z opisem) i średnie (w skrócie). Drobne i pełne opisy – w pełnym raporcie strony i w arkuszu.
        Audyt czyta kod strony, a nie jej wygląd na ekranie – punkty oznaczone „do potwierdzenia” warto sprawdzić w przeglądarce.
    </p>

    {{-- ============ Ranking ============ --}}
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
                    <td><a href="#{{ $anchor($s['label']) }}">{{ $s['label'] }}</a>@if($s['company'])<span class="muted small"> · {{ $s['company'] }}</span>@endif</td>
                    <td class="num" style="font-weight: bold; color: {{ $tone($s['score']) }}">{{ $s['score'] }}</td>
                    @foreach($areas as $k => $label)<td class="num" style="color: {{ $tone($s['areas'][$k] ?? null) }}">{{ $s['areas'][$k] ?? '–' }}</td>@endforeach
                    <td class="num" style="font-weight: bold; color: {{ $sevBg['pilne'] }}">{{ $s['counts']['pilne'] ?? 0 }}</td>
                    <td class="num">{{ $s['counts']['srednie'] ?? 0 }}</td>
                    <td class="num muted">{{ $s['counts']['drobne'] ?? 0 }}</td>
                </tr>
            @endforeach
        </table>
        @php $other = collect($sites)->where('state', '!=', 'done'); @endphp
        @if($other->isNotEmpty())
            <p class="note">Bez wyniku:
                @foreach($other as $s){{ $s['label'] }} ({{ $s['state'] === 'failed' ? 'audyt nieudany'.($s['error'] ? ' – '.\Illuminate\Support\Str::limit($s['error'], 110) : '') : 'jeszcze nie sprawdzono' }}){{ $loop->last ? '.' : '; ' }}@endforeach
            </p>
        @endif
    @endif

    {{-- ============ Najczęstsze problemy ============ --}}
    @if($common)
        <h2>Najczęstsze problemy</h2>
        <div class="muted">Te same rzeczy na wielu stronach – zwykle da się je naprawić jednym sposobem na wszystkich.</div>
        <table class="list" style="margin-top: 6px">
            <tr><th style="width: 74px">Ważność</th><th>Problem</th><th class="num">Stron</th><th style="width: 42%">Których</th></tr>
            @foreach($common as $c)
                <tr>
                    <td><span class="sev" style="background: {{ $sevBg[$c['sev']] }}">{{ $sevName[$c['sev']] }}</span></td>
                    <td>{{ $c['title'] }}</td>
                    <td class="num"><strong>{{ $c['count'] }}</strong> z {{ $stats['sites'] }}</td>
                    <td class="small muted">{{ implode(', ', array_slice($c['sites'], 0, 14)) }}{{ count($c['sites']) > 14 ? ' i '.(count($c['sites']) - 14).' innych' : '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- ============ Strony ============ --}}
    @foreach($doneSites as $s)
        <div class="site" id="{{ $anchor($s['label']) }}">
            <table>
                <tr>
                    <td>
                        <h2>{{ $loop->iteration }}. {{ $s['label'] }}</h2>
                        <div class="muted">{{ $s['company'] ? $s['company'].' · ' : '' }}<a href="{{ $s['url'] }}" rel="noreferrer noopener" target="_blank">{{ preg_replace('#^https?://#', '', $s['url']) }}</a></div>
                    </td>
                    <td style="text-align: right; vertical-align: bottom; white-space: nowrap">
                        <span class="score" style="color: {{ $tone($s['score']) }}">{{ $s['score'] }}<span style="font-size: 0.5em; color: #8a8984">/100</span></span>
                    </td>
                </tr>
            </table>
            <div class="small" style="margin-top: 4px">
                @foreach($areas as $k => $label)
                    {{ $label }}: <strong style="color: {{ $tone($s['areas'][$k] ?? null) }}">{{ $s['areas'][$k] ?? '–' }}</strong>{{ $loop->last ? '' : ' · ' }}
                @endforeach
                &nbsp;|&nbsp; uwagi: <strong style="color: {{ $sevBg['pilne'] }}">{{ $s['counts']['pilne'] ?? 0 }} pilnych</strong>, {{ $s['counts']['srednie'] ?? 0 }} średnich, {{ $s['counts']['drobne'] ?? 0 }} drobnych
                <span class="noprint">&nbsp;|&nbsp; <a href="{{ $s['public_url'] }}" target="_blank" rel="noopener">pełny raport</a> · <a href="{{ $s['pdf_url'] }}">PDF</a></span>
            </div>
            @if($s['summary'])<p style="margin: 6px 0">{{ $s['summary'] }}</p>@endif
            @if(count($s['pages']) > 1)
                <div class="small muted">Sprawdzone podstrony:
                    @foreach($s['pages'] as $p){{ $p['label'] }}@unless($p['ok']) (nie działa)@endunless{{ $loop->last ? '' : ' · ' }}@endforeach
                </div>
            @endif

            @if($s['pilne'])
                <h3>Pilne</h3>
                @foreach($s['pilne'] as $f)
                    <table class="find">
                        <tr>
                            <td class="s" style="background: {{ $sevBg['pilne'] }}">PILNE</td>
                            <td>
                                <strong><span class="fid">{{ $f['id'] }}</span> {{ $f['title'] }}{{ preg_match('/[.!?…:)”"]$/u', $f['title']) ? '' : '.' }}</strong>
                                {{ $f['text'] }}
                                @if($f['verify']) <span class="verify">do potwierdzenia</span>@endif
                                @if($f['fix'])<div class="fix"><b>Co zrobić:</b> {{ $f['fix'] }}</div>@endif
                                <div class="small muted">{{ $f['section'] }}{{ $f['page_url'] ? ' · '.preg_replace('#^https?://#', '', $f['page_url']) : '' }}</div>
                            </td>
                        </tr>
                    </table>
                @endforeach
            @else
                <p class="note">Bez spraw pilnych.</p>
            @endif

            @if($s['srednie'])
                <h3>Średnie</h3>
                <table class="list">
                    @foreach($s['srednie'] as $f)
                        <tr>
                            <td style="width: 44px" class="muted small">{{ $f['id'] }}</td>
                            <td>
                                <strong>{{ $f['title'] }}</strong>@if($f['verify']) <span class="verify">do potwierdzenia</span>@endif
                                @if($f['fix'])<div class="small"><b>Co zrobić:</b> {{ $f['fix'] }}</div>@endif
                            </td>
                            <td style="width: 24%" class="small muted">{{ $f['section'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach

    <div class="footer">
        {{ $batch->name }} – audyt zbiorczy · {{ $date }} · {{ $agency }}{{ $contact->isNotEmpty() ? ' · '.$contact->implode(' · ') : '' }}
        @if($brand['report_footer'])<br>{{ $brand['report_footer'] }}@endif
    </div>
</div>
{{-- skrypt z nonce – polityka CSP panelu nie pozwala na onclick w kodzie --}}
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    document.getElementById('print')?.addEventListener('click', function (e) { e.preventDefault(); window.print(); });
</script>
</body>
</html>
