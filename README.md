# AdPilot – system do prowadzenia i raportowania kampanii Google Ads i Meta Ads

Panel dla agencji performance marketingu. Podajesz adres strony i podłączasz konta Google (Ads + GA4) oraz Meta. Każdy klient ma **cel reklam**: sprzedaż (zamówienia, ROAS), kontakty (formularze, rejestracje, telefony, koszt leada) lub oba. System:

1. **Audytuje pomiar na stronie**: GTM, GA4, tagi Google Ads, Meta Pixel, Consent Mode v2, CMP, Enhanced Conversions, zdarzenie purchase, SEO i PageSpeed. Kontenery GTM czyta publicznie, więc **nie potrzebuje dostępu do GTM klienta**.
2. **Codziennie pobiera dane**: kampanie, reklamy i wyszukiwane hasła z Google Ads, kampanie i reklamy z Meta, ruch i sprzedaż wg kanałów z GA4.
3. **Silnik reguł tworzy listę zadań**, np. przepalony budżet, ROAS poniżej celu, kampanie do skalowania, słabe i najlepsze reklamy, wypalenie kreacji, hasła do wykluczenia, rozbieżności Ads vs GA4, tempo budżetu, błędy pomiaru.
4. **Raporty**:
   - **dla Ciebie**: plan działań ułożony przez AI (Claude) z priorytetami,
   - **dla klienta**: strona www z tajnym linkiem + PDF z wynikami reklam, ruchem w sklepie, wykonanymi zadaniami i planem. Raport może co miesiąc wysyłać się automatycznie e-mailem.

Stack: Laravel 12 + React 19 (Inertia) + Tailwind 4, kolejki i harmonogram Laravel, MySQL, dompdf, Anthropic PHP SDK.

---

## Start lokalnie (5 minut)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan adpilot:create-admin twoj@email.pl "Imię Nazwisko"   # konto admina z losowym hasłem
php artisan adpilot:demo          # 2 klientów demo: sklep i biuro usługowe (kontakty)
composer dev                      # serwer + kolejka + harmonogram + vite
```

Otwórz http://localhost:8000 i zaloguj się. Publiczna rejestracja jest wyłączona – kolejne osoby dodajesz w **Administracja → Zespół** (dostają e-mail z linkiem do ustawienia hasła).

> Audyt, synchronizacja i raporty działają w kolejce, więc musi działać worker (`composer dev` uruchamia go automatycznie).

## Komendy

| Komenda | Co robi |
|---|---|
| `php artisan adpilot:create-admin {email} {imię}` | Tworzy/resetuje konto administratora z losowym hasłem |
| `php artisan adpilot:demo` | Tworzy 2 klientów demo (sprzedaż i kontakty) |
| `php artisan adpilot:digest --force` | Poranny e-mail z pilnymi zadaniami |
| `php artisan adpilot:backup` | Kopia bazy do storage/app/backups (14 ostatnich) |
| `php artisan adpilot:sync {id?}` | Synchronizacja danych + przeliczenie rekomendacji |
| `php artisan adpilot:monthly-reports` | Raporty za poprzedni miesiąc (i wysyłka, jeśli włączona) |
| `php artisan test` | Testy |

Harmonogram (`schedule:work` albo cron `* * * * * php artisan schedule:run`):
- codziennie 05:30: synchronizacja wszystkich klientów + reguły,
- poniedziałek 04:00: audyt trackingu,
- 2. dnia miesiąca 07:00: raporty miesięczne,
- 03:00: kopia bazy, 08:00: poranny e-mail z pilnymi zadaniami,
- co minutę / 5 min: sygnał „żyję” harmonogramu i kolejki (widoczny w **Status systemu**).

## Panel administratora

- **Status systemu**: co jest połączone i skonfigurowane (Google, Meta, AI, poczta, kolejka, cron, kopie, HTTPS) + przyciski „Wyślij testowy e-mail” i „Testuj AI”.
- **Ustawienia agencji**: nazwa, logo, kolor, dane kontaktowe, stopka raportu.
- **Zespół**: konta, uprawnienia administratora, poranny e-mail.

## Kampanie na kontakty (leady)

Cel „Kontakty” w ustawieniach klienta zmienia sposób liczenia: z GA4 pobierane są zdarzenia `generate_lead`, `form_submit`, `sign_up`, `contact`, `phone_click`, `click_to_call`, `submit_lead_form` (listę można zmienić per klient), z Meta – akcje Lead / CompleteRegistration / Contact. Panel i raport pokazują leady, koszt leada, wejścia na stronę i rodzaje kontaktów zamiast przychodu i ROAS. Audyt sprawdza mierzenie formularzy, klikalny telefon i zdarzenie Lead w Pixelu.

**Budżet dzienny** (np. 10 zł) wpisujesz w ustawieniach klienta – system pilnuje tempa wydatków (za dużo / za mało / zero wydatków). Sam budżet ustawiasz w Google Ads i Meta – AdPilot dane tylko odczytuje.

---

## Konfiguracja dostępów (jednorazowo dla agencji)

### Google Ads + GA4
1. [Google Cloud Console](https://console.cloud.google.com/): utwórz projekt i włącz **Google Ads API**, **Google Analytics Data API** oraz **Google Analytics Admin API**.
2. Ekran zgody OAuth: typ „Zewnętrzny”, zakresy `adwords` i `analytics.readonly`. Aby wyjść z trybu testowego, potrzebna jest weryfikacja aplikacji przez Google.
3. Dane logowania, potem identyfikator klienta OAuth (aplikacja internetowa). URI przekierowania: `https://TWOJA-DOMENA/oauth/google-callback`.
4. **Developer token**: na koncie menedżera (MCC) wybierz Narzędzia, a potem Centrum API. Złóż wniosek o *Basic access*, bo token testowy działa tylko na kontach testowych.
5. Uzupełnij `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN`, `GOOGLE_ADS_LOGIN_CUSTOMER_ID` (ID MCC bez myślników).
6. Najprościej jest podpiąć konta klientów pod swoje MCC i łączyć się swoim kontem Google.

Google wydaje nową wersję API kilka razy w roku i wyłącza starsze. Wersję ustawiasz w `GOOGLE_ADS_API_VERSION` (domyślnie `v26`).

### Meta Ads
1. [developers.facebook.com](https://developers.facebook.com/): utwórz aplikację typu **Firma** i dodaj **Marketing API** oraz **Facebook Login for Business**.
2. URI przekierowania: `https://TWOJA-DOMENA/oauth/meta-callback`.
3. Do kont klientów potrzebne są **weryfikacja firmy** i **App Review** dla `ads_read` (oraz `business_management`).
4. `.env`: `META_APP_ID`, `META_APP_SECRET`, `META_GRAPH_VERSION`.
5. Token Meta jest ważny ok. 60 dni. Po wygaśnięciu kliknij „Połącz ponownie” w zakładce Integracje.

### AI (komentarze w raportach)
`ANTHROPIC_API_KEY` z console.anthropic.com. Domyślny model: `claude-opus-5-5`. Bez klucza raporty też powstają, tylko komentarz pochodzi z szablonu.

---

## Wdrożenie na VPS (Docker)

Wymagania: VPS z 2 GB RAM, Docker + Docker Compose, domena.

```bash
git clone <repo> adpilot && cd adpilot
cp .env.example .env
# w .env ustaw:
#   APP_ENV=production, APP_DEBUG=false, APP_URL=https://twoja-domena.pl
#   DB_CONNECTION=mysql, DB_HOST=db, DB_DATABASE=adpilot, DB_USERNAME=adpilot,
#   DB_PASSWORD=..., DB_ROOT_PASSWORD=...
#   QUEUE_CONNECTION=database, SESSION_DRIVER=database
#   MAIL_* (np. SMTP z Twojej poczty / Postmark / Mailgun), klucze Google/Meta/Anthropic
docker compose up -d --build
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache && docker compose exec app php artisan route:cache
```

Aplikacja działa na porcie `8080`. HTTPS najprościej dostaniesz przez Caddy na hoście:

```
twoja-domena.pl {
    reverse_proxy 127.0.0.1:8080
}
```

Kontenery: `app` (PHP-FPM), `web` (nginx), `worker` (kolejka), `scheduler` (harmonogram), `db` (MySQL 8.4).

**Aktualizacja:** `git pull && docker compose up -d --build && docker compose exec app php artisan migrate --force`

**Kopie zapasowe:** `docker compose exec db mysqldump -u root -p adpilot > backup.sql` (najlepiej codziennie z crona).

Pierwsze konto na serwerze: `docker compose exec app php artisan adpilot:create-admin twoj@email.pl "Imię Nazwisko"`.

---

## Jak rozbudować

- **Nowa reguła**: dodaj klasę w `app/Services/Analysis/Rules/` (dziedziczy po `Rule`) i dopisz ją w `RuleEngine::rules()`.
- **Nowe źródło danych** (np. TikTok Ads, Microsoft Ads): nowy konektor w `app/Services/Connectors/`, zapis przez `MetricsWriter`, wywołanie w `ClientSync`.
- **Wygląd raportu dla klienta**: `resources/views/reports/client.blade.php` (ten sam widok obsługuje www i PDF).

## RODO

System przechowuje wyłącznie dane zagregowane (koszty, kliknięcia, sesje, transakcje), bez danych osobowych klientów sklepu. Tokeny OAuth są szyfrowane w bazie (`APP_KEY`). Z każdym klientem podpisz umowę powierzenia przetwarzania danych.
