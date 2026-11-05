<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>Zgłoszenie – {{ $client?->name ?? $agency?->name }}</title>
    <style>
        :root { --surface: #fcfcfb; --card: #ffffff; --ink: #0b0b0b; --ink2: #52514e; --muted: #8a8984; --line: #e6e5e0; --brand: {{ $brand['color'] }}; --bad: #b42f2f; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--surface); margin: 0; font-size: 15px; line-height: 1.55; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 32px 20px 60px; }
        h1 { font-size: 26px; margin: 0 0 4px; }
        .muted { color: var(--muted); } .ink2 { color: var(--ink2); }
        .logo { max-height: 44px; max-width: 200px; margin-bottom: 10px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-top: 18px; }
        label { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 4px; }
        input[type=text], input[type=email], input[type=tel], select, textarea { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: #fff; }
        textarea { min-height: 140px; resize: vertical; }
        .row { display: grid; gap: 0 12px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 560px) { .row { grid-template-columns: 1fr; } }
        .check { display: flex; gap: 8px; align-items: center; font-weight: 400; margin-top: 16px; }
        .btn { display: inline-block; margin-top: 18px; padding: 10px 18px; border-radius: 8px; background: var(--brand); color: #fff; border: 0; font: inherit; font-weight: 600; cursor: pointer; }
        .err { color: var(--bad); font-size: 13px; margin-top: 3px; }
        .ok { border-left: 4px solid var(--brand); }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .foot { margin-top: 28px; font-size: 13px; }
    </style>
</head>
<body>
<div class="wrap">
    @if($brand['logo_url'])
        <img class="logo" src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}">
    @endif
    <div class="muted">{{ $brand['name'] }} · Zgłoszenie zmiany lub problemu</div>
    <h1>{{ $client ? $client->name : 'Zgłoszenie dla '.$brand['name'] }}</h1>
    @if($client)<div class="ink2">{{ preg_replace('#^https?://#', '', $client->website_url) }}</div>@endif

    @if($sent)
        <div class="card ok">
            <strong>Dziękujemy – zgłoszenie przyjęte.</strong>
            <p class="ink2">Zajmiemy się nim{{ isset($due) ? ' do '.\Carbon\Carbon::parse($due)->format('d.m.Y') : '' }}. Jeśli to coś bardzo pilnego (strona nie działa) – zadzwoń{{ $brand['phone'] ? ': '.$brand['phone'] : '' }}.</p>
            <p><a href="{{ request()->url() }}">Wyślij kolejne zgłoszenie</a></p>
        </div>
    @else
        <form class="card" method="post" action="{{ request()->url() }}">
            @csrf
            <div class="hp" aria-hidden="true"><label>Strona www <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            @if($agency)
                <label for="client_id">Której strony dotyczy?</label>
                <select id="client_id" name="client_id" required>
                    <option value="">— wybierz stronę —</option>
                    @foreach($sites as $s)
                        <option value="{{ $s->id }}" @selected(old('client_id') == $s->id)>{{ $s->name }} ({{ preg_replace('#^https?://#', '', $s->website_url) }})</option>
                    @endforeach
                </select>
                @error('client_id')<div class="err">{{ $message }}</div>@enderror
            @endif

            <label for="title">Temat</label>
            <input id="title" type="text" name="title" value="{{ old('title') }}" required maxlength="200" placeholder="np. Zmiana cennika na stronie Oferta">
            @error('title')<div class="err">{{ $message }}</div>@enderror

            <label for="description">Opis – co, gdzie i jak ma wyglądać</label>
            <textarea id="description" name="description" required maxlength="5000" placeholder="Wklej link do podstrony, nowy tekst, opisz problem…">{{ old('description') }}</textarea>
            @error('description')<div class="err">{{ $message }}</div>@enderror

            <div class="row">
                <div>
                    <label for="name">Imię i nazwisko</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="120">
                    @error('name')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="email">E-mail (do odpowiedzi)</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" maxlength="190">
                    @error('email')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>
            <label for="phone">Telefon (opcjonalnie)</label>
            <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="40">

            <label class="check"><input type="checkbox" name="urgent" value="1" @checked(old('urgent'))> Pilne – na następny dzień roboczy</label>

            <button class="btn" type="submit">Wyślij zgłoszenie</button>
        </form>
    @endif

    <p class="muted foot">{{ collect([$brand['name'], $brand['email'], $brand['phone']])->filter()->implode(' · ') }}</p>
</div>
</body>
</html>
