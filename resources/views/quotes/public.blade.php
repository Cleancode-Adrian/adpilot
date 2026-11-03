@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$quote->currency;
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $rec = ['monthly' => 'mies.', 'yearly' => 'rok'];
    $expired = $quote->isExpired();
    $open = $quote->status === 'sent' && ! $expired && ! $pdf;
@endphp
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, noai, noimageai">
    <meta name="referrer" content="no-referrer">
    <title>Oferta {{ $quote->number }} – {{ $quote->title }}</title>
    <style>
        :root { --surface: #fcfcfb; --card: #ffffff; --ink: #0b0b0b; --ink2: #52514e; --muted: #8a8984; --line: #e6e5e0; --brand: {{ $brand['color'] }}; }
        * { box-sizing: border-box; }
        body { font-family: {!! $pdf ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' !!};
               color: var(--ink); background: var(--surface); margin: 0; font-size: {{ $pdf ? '10px' : '15px' }}; line-height: 1.55; }
        .wrap { max-width: 880px; margin: 0 auto; padding: {{ $pdf ? '0' : '32px 20px 60px' }}; }
        h1 { font-size: {{ $pdf ? '20px' : '28px' }}; margin: 0 0 4px; }
        h2 { font-size: {{ $pdf ? '13px' : '18px' }}; margin: 26px 0 8px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
        .muted { color: var(--muted); } .ink2 { color: var(--ink2); }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: bottom; }
        .logo { max-height: 44px; max-width: 200px; margin-bottom: 8px; }
        .btn { display: inline-block; padding: 9px 16px; border-radius: 6px; background: var(--brand); color: #fff; text-decoration: none; font-size: 14px; border: 0; cursor: pointer; font-weight: 600; }
        .btn.secondary { background: transparent; color: var(--ink2); border: 1px solid var(--line); }
        .intro { white-space: pre-line; }
        .items th { text-align: left; color: var(--ink2); font-weight: 600; font-size: 0.85em; border-bottom: 1px solid var(--line); padding: 6px; }
        .items td { padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .desc { color: var(--ink2); font-size: 0.9em; white-space: pre-line; }
        .tag { display: inline-block; font-size: 0.75em; padding: 0 6px; border-radius: 8px; border: 1px solid var(--brand); color: var(--brand); margin-left: 4px; }
        .totals { margin-top: 12px; margin-left: auto; width: auto; min-width: 320px; }
        .totals td { padding: 4px 8px; }
        .totals .grand td { font-weight: 700; border-top: 1px solid var(--line); }
        .box { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 16px 18px; margin-top: 18px; }
        .status { padding: 10px 14px; border-radius: 8px; margin-top: 14px; }
        .ok { background: #0ca30c14; border: 1px solid #0ca30c66; color: #0a5d0a; }
        .bad { background: #d03b3b12; border: 1px solid #d03b3b55; color: #8a2424; }
        input[type=text], textarea { width: 100%; padding: 9px 10px; border: 1px solid var(--line); border-radius: 6px; font: inherit; margin: 6px 0 10px; }
        .footer { margin-top: 36px; color: var(--muted); font-size: 0.8em; }
        details summary { cursor: pointer; color: var(--ink2); margin-top: 12px; }
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
                <div class="muted">{{ $agency }} · Oferta {{ $quote->number }}</div>
                <h1>{{ $quote->title }}</h1>
                <div class="ink2">
                    dla: <strong>{{ $quote->client->name }}</strong> · {{ $quote->created_at->format('d.m.Y') }}
                    @if($quote->valid_until) · ważna do {{ \Carbon\Carbon::parse($quote->valid_until)->format('d.m.Y') }}@endif
                </div>
            </td>
            @unless($pdf)
                <td style="text-align:right"><a class="btn secondary" href="{{ $pdfUrl }}">Pobierz PDF</a></td>
            @endunless
        </tr>
    </table>

    @unless($pdf)
        @if(session('quote_success'))<div class="status ok">{{ session('quote_success') }}</div>@endif
        @if(session('quote_error'))<div class="status bad">{{ session('quote_error') }}</div>@endif
        @if($errors->any())<div class="status bad">{{ $errors->first() }}</div>@endif
    @endunless
    @if($quote->status === 'accepted')
        <div class="status ok">✓ Oferta zaakceptowana {{ $quote->decided_at?->format('d.m.Y H:i') }}{{ $quote->decided_name ? ' przez: '.$quote->decided_name : '' }}.</div>
    @elseif($quote->status === 'rejected')
        <div class="status bad">Oferta odrzucona {{ $quote->decided_at?->format('d.m.Y') }}.</div>
    @elseif($expired)
        <div class="status bad">Termin ważności oferty minął – skontaktuj się z nami, przygotujemy aktualną.</div>
    @endif

    @if($quote->intro)
        <p class="intro" style="margin-top:20px">{{ $quote->intro }}</p>
    @endif

    <h2>Zakres i cena</h2>
    <table class="items">
        <thead>
            <tr><th style="width:4%">Lp.</th><th>Usługa</th><th class="num">Ilość</th><th class="num">Cena netto</th><th class="num">Wartość netto</th></tr>
        </thead>
        <tbody>
            @foreach($quote->items as $n => $i)
                <tr>
                    <td class="muted">{{ $n + 1 }}.</td>
                    <td><strong>{{ $i->name }}</strong>@if($i->recurring)<span class="tag">{{ $i->recurring === 'monthly' ? 'co miesiąc' : 'co rok' }}</span>@endif
                        @if($i->description)<div class="desc">{{ $i->description }}</div>@endif</td>
                    <td class="num">{{ $qty($i->quantity) }} {{ $i->unit }}</td>
                    <td class="num">{{ $money($i->unit_price) }}</td>
                    <td class="num">{{ $money($i->quantity * $i->unit_price) }}{{ $i->recurring ? ' / '.$rec[$i->recurring] : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @foreach($totals as $key => $t)
            <tr><td class="ink2">{{ $t['label'] }} – netto</td><td class="num">{{ $money($t['net']) }}</td></tr>
            @if($quote->vat_rate !== null)
                <tr><td class="ink2">VAT {{ rtrim(rtrim(number_format($quote->vat_rate, 2, ',', ''), '0'), ',') }}%</td><td class="num">{{ $money($t['vat']) }}</td></tr>
            @endif
            <tr class="grand"><td>{{ $t['label'] }} – {{ $quote->vat_rate !== null ? 'brutto' : 'do zapłaty (zw. z VAT)' }}</td><td class="num">{{ $money($t['gross']) }}</td></tr>
        @endforeach
    </table>

    @if($quote->terms)
        <h2>Warunki</h2>
        <p class="intro ink2">{{ $quote->terms }}</p>
    @endif

    @if($open)
        <div class="box">
            <strong>Akceptacja oferty</strong>
            <p class="ink2" style="margin:4px 0 8px">Po akceptacji skontaktujemy się w sprawie startu prac.</p>
            <form method="post" action="{{ route('public.quote.decide', $quote->token) }}">
                @csrf
                <input type="hidden" name="decision" value="accept">
                <label>Imię i nazwisko<input type="text" name="name" required maxlength="150" value="{{ old('name') }}"></label>
                <label style="display:block;margin-bottom:12px"><input type="checkbox" name="agree" value="1" required> Akceptuję ofertę {{ $quote->number }} wraz z warunkami.</label>
                <button class="btn" type="submit">Akceptuję ofertę</button>
            </form>
            <details>
                <summary>Nie jestem zainteresowany / mam uwagi</summary>
                <form method="post" action="{{ route('public.quote.decide', $quote->token) }}" style="margin-top:8px">
                    @csrf
                    <input type="hidden" name="decision" value="reject">
                    <textarea name="reason" rows="3" maxlength="2000" placeholder="Co możemy zmienić? (opcjonalnie)"></textarea>
                    <button class="btn secondary" type="submit">Odrzuć ofertę</button>
                </form>
            </details>
        </div>
    @endif

    <div class="footer">
        {{ $agency }}{{ collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->isNotEmpty() ? ' · '.collect([$brand['email'], $brand['phone'], $brand['website']])->filter()->implode(' · ') : '' }}
    </div>
</div>
</body>
</html>
