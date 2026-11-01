<x-mail::message>
# Synchronizacja danych nie powiodła się

Klient: **{{ $i->client->name }}**
Połączenie: **{{ $i->provider === 'google' ? 'Google Ads / GA4' : 'Meta Ads' }}**

Błąd:
> {{ $i->last_error }}

Najczęstsze przyczyny: wygasły token (Meta – co ok. 60 dni), cofnięty dostęp do konta, zmiana uprawnień lub nieaktualna wersja API.
Dopóki połączenie nie zostanie naprawione, dane i rekomendacje dla tego klienta nie będą aktualizowane.

<x-mail::button :url="$url">
Połącz ponownie
</x-mail::button>
</x-mail::message>
