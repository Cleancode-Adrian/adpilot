<x-mail::message>
# Dzień dobry{{ $quote->client->contact_name ? ', '.$quote->client->contact_name : '' }}!

przesyłamy ofertę **{{ $quote->title }}** (nr {{ $quote->number }}).

Ofertę można przejrzeć, pobrać jako PDF i zaakceptować online – jednym kliknięciem.
@if($quote->valid_until)
Oferta jest ważna do **{{ \Carbon\Carbon::parse($quote->valid_until)->format('d.m.Y') }}**.
@endif

<x-mail::button :url="$url">
Zobacz ofertę
</x-mail::button>

W razie pytań – po prostu odpisz na tę wiadomość.

Pozdrawiamy,<br>
{{ $agency }}
</x-mail::message>
