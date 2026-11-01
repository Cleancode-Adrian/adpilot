<?php

return [
    'required' => 'Pole :attribute jest wymagane.',
    'email' => 'Pole :attribute musi być poprawnym adresem e-mail.',
    'string' => 'Pole :attribute musi być tekstem.',
    'numeric' => 'Pole :attribute musi być liczbą.',
    'boolean' => 'Pole :attribute musi mieć wartość tak/nie.',
    'date' => 'Pole :attribute musi być poprawną datą.',
    'after_or_equal' => 'Pole :attribute musi być datą równą lub późniejszą niż :date.',
    'confirmed' => 'Hasła nie są identyczne.',
    'unique' => 'Taki :attribute już istnieje.',
    'in' => 'Wybrana wartość pola :attribute jest nieprawidłowa.',
    'image' => 'Pole :attribute musi być obrazem.',
    'mimes' => 'Pole :attribute musi być plikiem typu: :values.',
    'size' => ['string' => 'Pole :attribute musi mieć :size znaki.'],
    'min' => ['numeric' => 'Pole :attribute musi wynosić co najmniej :min.', 'string' => 'Pole :attribute musi mieć co najmniej :min znaków.'],
    'max' => ['numeric' => 'Pole :attribute nie może przekraczać :max.', 'string' => 'Pole :attribute może mieć maksymalnie :max znaków.', 'file' => 'Plik :attribute może mieć maksymalnie :max KB.'],
    'current_password' => 'Podane hasło jest nieprawidłowe.',
    'lowercase' => 'Pole :attribute musi być pisane małymi literami.',
    'regex' => 'Pole :attribute ma nieprawidłowy format.',
    'password' => [
        'letters' => 'Hasło musi zawierać co najmniej jedną literę.',
        'mixed' => 'Hasło musi zawierać małe i wielkie litery.',
        'numbers' => 'Hasło musi zawierać co najmniej jedną cyfrę.',
        'symbols' => 'Hasło musi zawierać co najmniej jeden znak specjalny.',
        'uncompromised' => 'To hasło wyciekło w sieci – wybierz inne.',
    ],

    'attributes' => [
        'name' => 'nazwa', 'email' => 'e-mail', 'password' => 'hasło', 'website' => 'adres strony', 'website_url' => 'adres strony',
        'phone' => 'telefon', 'message' => 'wiadomość', 'currency' => 'waluta', 'target_roas' => 'docelowy ROAS', 'target_cpa' => 'docelowy CPA',
        'monthly_budget' => 'budżet miesięczny', 'contact_email' => 'e-mail do raportów', 'from' => 'data od', 'to' => 'data do', 'consent' => 'zgoda',
        'logo' => 'logo', 'brand_color' => 'kolor marki',
    ],
];
