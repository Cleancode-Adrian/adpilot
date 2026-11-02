<x-mail::message>
# {{ $event->title }}

**Strona:** {{ $client->name }} ({{ preg_replace('#^https?://#', '', $client->website_url) }})<br>
**Kiedy:** {{ $event->occurred_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
@if(! empty($event->details['user']))<br>**Użytkownik:** {{ $event->details['user'] }}@endif
@if(! empty($event->details['ip']))<br>**IP:** {{ $event->details['ip'] }}@endif
@if(! empty($event->details['target_email']))<br>**E-mail nowego konta:** {{ $event->details['target_email'] }}@endif
@if(! empty($event->details['how']))<br>**Jak:** {{ $event->details['how'] }}@endif
@if(! empty($event->details['file']))<br>**Plik:** {{ $event->details['file'] }} {{ $event->details['where'] ?? '' }}@endif

@if(! empty($event->details['hint']))
{{ $event->details['hint'] }}
@endif

@if(! empty($event->details['files']))
@foreach(array_slice($event->details['files'], 0, 15) as $f)
- `{{ $f }}`
@endforeach
@endif

<x-mail::button :url="$url">
Sprawdź w panelu
</x-mail::button>

Jeśli to nie Ty ani nikt z zespołu – działaj od razu: zmień hasła administratorów, usuń nieznane konta i sprawdź pliki.

{{ $agency }}
</x-mail::message>
