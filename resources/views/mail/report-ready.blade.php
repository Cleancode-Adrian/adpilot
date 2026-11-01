<x-mail::message>
# Dzień dobry,

przygotowaliśmy raport z działań reklamowych dla **{{ $client->name }}** za okres
{{ $report->period_start->format('d.m.Y') }} – {{ $report->period_end->format('d.m.Y') }}.

Znajdziesz w nim wyniki kampanii Google Ads i Meta Ads, ruch na stronie oraz plan na kolejny okres.

<x-mail::button :url="$url">
Zobacz raport online
</x-mail::button>

[Pobierz PDF]({{ $pdfUrl }})

W razie pytań – po prostu odpisz na tę wiadomość.

Pozdrawiamy,<br>
{{ \App\Models\Setting::brand()['name'] }}
</x-mail::message>
