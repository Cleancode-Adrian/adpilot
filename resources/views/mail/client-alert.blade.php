<x-mail::message>
# {{ $title }}

@foreach($lines as $line)
{{ $line }}<br>
@endforeach

@if($action)
**{{ $action }}**
@endif

<x-mail::button :url="$url">
Otwórz w panelu
</x-mail::button>

{{ $agency }}
</x-mail::message>
