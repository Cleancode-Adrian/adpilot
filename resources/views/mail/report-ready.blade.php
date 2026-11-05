<x-mail::message :brand="$agency" :brand-url="$brandUrl">
# Dzień dobry,

@if($report->type === 'work')
przesyłamy podsumowanie prac wykonanych dla **{{ $client->name }}** w miesiącu {{ \App\Services\Reports\WorkReportBuilder::monthLabel($report->period_start) }}.

W raporcie znajdziesz listę wykonanych zadań wraz z opisem.
@else
przygotowaliśmy raport z działań reklamowych dla **{{ $client->name }}** za okres
{{ $report->period_start->format('d.m.Y') }} – {{ $report->period_end->format('d.m.Y') }}.

Znajdziesz w nim wyniki kampanii Google Ads i Meta Ads, ruch na stronie oraz plan na kolejny okres.
@endif

<x-mail::button :url="$url">
Zobacz raport online
</x-mail::button>

[Pobierz PDF]({{ $pdfUrl }})

W razie pytań – po prostu odpisz na tę wiadomość.

Pozdrawiamy,<br>
{{ $agency }}
</x-mail::message>
