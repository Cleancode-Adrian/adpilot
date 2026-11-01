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
            'openid',
            'email',
        ],
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
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

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ADPILOT_AI_MODEL', 'claude-opus-5-5'),
        'effort' => env('ADPILOT_AI_EFFORT', 'medium'),
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
];
