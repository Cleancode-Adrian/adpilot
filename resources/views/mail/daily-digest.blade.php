<x-mail::message>
# Dzień dobry!

@if(count($integrationProblems))
## ⚠️ Problemy z połączeniami
@foreach($integrationProblems as $p)
- **{{ $p['client'] }}** – {{ $p['provider'] }}: {{ $p['details'] }}
@endforeach

@endif
@if(count($clients))
## Pilne zadania
@foreach($clients as $c)
**{{ $c['name'] }}**
@foreach($c['items'] as $r)
- [{{ $r['severity'] === 'critical' ? 'KRYTYCZNE' : 'wysokie' }}] {{ $r['title'] }}
@endforeach

@endforeach
@else
Brak krytycznych i pilnych zadań – konta działają zgodnie z celami. ✅
@endif

<x-mail::button :url="$url">
Otwórz panel
</x-mail::button>

[Status systemu]({{ $statusUrl }})
</x-mail::message>
