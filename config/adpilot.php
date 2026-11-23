<?php

return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Developer token z konta MCC (Narzędzia > Centrum API)
        'ads_developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        // ID konta MCC bez myślników – wysyłane jako login-customer-id
        'ads_login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        // Google co kwartał wydaje nową wersję i wyłącza starsze – aktualizuj w .env
        'ads_api_version' => env('GOOGLE_ADS_API_VERSION', 'v26'),
        'scopes' => [
            'https://www.googleapis.com/auth/adwords',
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/webmasters.readonly', // Search Console (zakładka SEO)
            'openid',
            'email',
        ],
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'config_id' => env('META_CONFIG_ID'), // opcjonalnie: ID konfiguracji Facebook Login for Business
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        'scopes' => ['ads_read', 'business_management'],
        // Kolejność wyboru akcji traktowanej jako zakup
        'purchase_actions' => ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase'],
        // Kontakty: z każdej grupy liczymy pierwszą dostępną akcję (te same zdarzenia mają w Meta kilka nazw)
        'lead_action_groups' => [
            ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead'],
            ['complete_registration', 'omni_complete_registration', 'offsite_conversion.fb_pixel_complete_registration'],
            ['contact_total', 'contact', 'offsite_conversion.fb_pixel_contact'],
        ],
    ],

    // Bezpieczeństwo panelu
    'security' => [
        'require_2fa' => (bool) env('SECURITY_REQUIRE_2FA', true),           // administrator musi mieć weryfikację dwuetapową
        'vault_unlock_seconds' => (int) env('VAULT_UNLOCK_SECONDS', 120),      // po potwierdzeniu: tyle sekund widać hasła, potem znów pyta
        'csp' => env('SECURITY_CSP', 'enforce'),                               // enforce | report | off
    ],

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ADPILOT_AI_MODEL', 'claude-opus-5-5'),
        'effort' => env('ADPILOT_AI_EFFORT', 'medium'),
        'audit_effort' => env('ADPILOT_AUDIT_AI_EFFORT', 'high'), // przegląd treści w audycie strony – dokładność ważniejsza niż czas
    ],

    'pagespeed' => [
        'enabled' => (bool) env('PAGESPEED_ENABLED', true),
        'api_key' => env('PAGESPEED_API_KEY'),
    ],

    'agency' => [
        'name' => env('AGENCY_NAME', 'AdPilot'),
        'email' => env('AGENCY_EMAIL'),
    ],

    // Pakiet certyfikatów CA dla zapytań HTTP (gdy php.ini ma błędne curl.cainfo, np. lokalnie na Windows)
    'http_ca_bundle' => env('HTTP_CA_BUNDLE'),

    // Ile dni wstecz pobierać przy każdej synchronizacji (konwersje dopisują się z opóźnieniem)
    'sync_lookback_days' => (int) env('ADPILOT_SYNC_LOOKBACK', 30),
    'live_sync' => (bool) env('ADPILOT_LIVE_SYNC', true), // dane z reklam co godzinę (6:15–23:15)

    // Sprawdzanie zgód w prawdziwej przeglądarce (wymaga Chrome/Chromium na serwerze) – bez tego audyt ocenia zgody z samego kodu
    'browser_check' => [
        'enabled' => (bool) env('BROWSER_CHECK', false),
        'chrome' => env('CHROME_PATH'), // puste = szukaj w typowych miejscach (/usr/bin/google-chrome, /usr/bin/chromium…)
        'node' => env('NODE_BINARY', 'node'),
    ],

    // Audyt zbiorczy (lista stron audytowana po kolei)
    'audit_batch' => [
        'concurrency' => (int) env('AUDIT_BATCH_CONCURRENCY', 1), // ile stron z listy naraz (więcej ma sens tylko przy kilku workerach kolejki)
        'max_sites' => (int) env('AUDIT_BATCH_MAX_SITES', 150),
    ],

    // Audyt strony (SEO i techniczny)
    'site_audit' => [
        'max_pages' => (int) env('SITE_AUDIT_MAX_PAGES', 60),
        'max_external' => (int) env('SITE_AUDIT_MAX_EXTERNAL', 40),
        'prospect_pages' => (int) env('SITE_AUDIT_PROSPECT_PAGES', 30), // audyt dowolnej strony (potencjalni klienci)
        // Ile podstron naraz i przerwa między paczkami – łagodniej dla hostingów z limitem zapytań (inaczej pokazują ekran „weryfikacji”)
        'concurrency' => (int) env('SITE_AUDIT_CONCURRENCY', 3),
        'delay_ms' => (int) env('SITE_AUDIT_DELAY_MS', 400),
        // Przerwy (s) przed ponownym pobraniem, gdy serwer pokaże ekran weryfikacji / limit zapytań zamiast strony
        'retry_after' => [8, 20],
    ],
];
