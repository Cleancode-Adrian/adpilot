<x-mail::message>
# Dzień dobry!

@if(count($integrationProblems))
## ⚠️ Problemy z połączeniami
@foreach($integrationProblems as $p)
- **{{ $p['client'] }}** – {{ $p['provider'] }}: {{ $p['details'] }}
@endforeach

@endif
@if(count($sites ?? []))
## 🌐 Strony klientów
@foreach($sites as $s)
- **[{{ $s['name'] }}]({{ route('clients.site', $s['id']) }})** – {{ $s['text'] }}
@endforeach

@endif
@if(count($wp ?? []))
## 🛡 WordPress – do sprawdzenia
@foreach($wp as $c)
**[{{ $c['name'] }}]({{ route('clients.wordpress', $c['id']) }})**
@foreach($c['items'] as $e)
- {{ $e['severity'] === 'critical' ? '🔴' : '🟠' }} {{ $e['title'] }}
@endforeach

@endforeach
@endif
@if(count($care ?? []))
## 🛠 Opieka nad stronami – do zrobienia
@foreach($care as $c)
**[{{ $c['name'] }}]({{ route('clients.care', $c['id']) }})**
@foreach($c['items'] as $t)
- {{ $t['overdue'] ? '⏰ PO TERMINIE' : 'do '.\Carbon\Carbon::parse($t['due_date'])->format('d.m') }} – {{ $t['title'] }}
@endforeach

@endforeach
@endif
@if(count($followups ?? []))
## 📞 Do kontaktu (sprzedaż)
@foreach($followups as $f)
- **{{ $f['who'] ?: '—' }}** – {{ $f['title'] }} (od {{ \Carbon\Carbon::parse($f['since'])->format('d.m') }})
@endforeach

@endif
@if(count($clients))
## Pilne zadania reklamowe
@foreach($clients as $c)
**{{ $c['name'] }}**
@foreach($c['items'] as $r)
- [{{ $r['severity'] === 'critical' ? 'KRYTYCZNE' : 'wysokie' }}] {{ $r['title'] }}
@endforeach

@endforeach
@else
Brak krytycznych zadań reklamowych – konta działają zgodnie z celami. ✅
@endif

<x-mail::button :url="$url">
Otwórz panel
</x-mail::button>

[Status systemu]({{ $statusUrl }})
</x-mail::message>
