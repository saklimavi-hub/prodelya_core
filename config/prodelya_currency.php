<?php

return [
    'supported_currencies' => ['TRY', 'USD', 'EUR'],

    'base_currency' => 'TRY',

    'display_labels' => [
        'TRY' => 'TL',
        'USD' => 'USD',
        'EUR' => 'EUR',
    ],

    'symbol_mapping' => [
        'TRY' => '₺',
        'USD' => '$',
        'EUR' => '€',
    ],

    'money_precision' => 2,
    'rate_precision' => 8,
    'calculation_precision' => 12,

    'default_rate_source' => 'tcmb',
    'default_rate_type' => 'forex_selling',

    'fallback_lookback_days' => 7,
    'stale_warning_threshold' => 2,
    'hard_fail_threshold' => 7,

    'providers' => [
        'tcmb' => [
            'enabled' => env('PRODELYA_TCMB_ENABLED', true),
            'endpoint_template' => env('PRODELYA_TCMB_ENDPOINT', 'https://www.tcmb.gov.tr/kurlar/{year_month}/{date}.xml'),
            'allowed_hosts' => [
                'www.tcmb.gov.tr',
                'tcmb.gov.tr',
            ],
            'supported_rate_types' => [
                'forex_buying' => 'ForexBuying',
                'forex_selling' => 'ForexSelling',
                'banknote_buying' => 'BanknoteBuying',
                'banknote_selling' => 'BanknoteSelling',
            ],
            'timeout_seconds' => (int) env('PRODELYA_TCMB_TIMEOUT', 10),
            'retry_times' => (int) env('PRODELYA_TCMB_RETRY_TIMES', 2),
            'retry_sleep_ms' => (int) env('PRODELYA_TCMB_RETRY_SLEEP_MS', 250),
        ],
    ],

    'sync' => [
        'schedule' => [
            'enabled' => env('PRODELYA_CURRENCY_SYNC_ENABLED', false),
            'time' => env('PRODELYA_CURRENCY_SYNC_TIME', '07:30'),
            'timezone' => env('PRODELYA_CURRENCY_SYNC_TIMEZONE', 'Europe/Istanbul'),
        ],
    ],
];
