<x-mail::message>
# {{ $title }}

@foreach($lines as $line)
{{ $line }}<br>
@endforeach

@if($action)
**{{ $action }}**
@endif

<x-mail::button :url="$url">
Ustawienia bezpieczeństwa
</x-mail::button>

To automatyczna wiadomość bezpieczeństwa z panelu {{ $agency }}.
</x-mail::message>
