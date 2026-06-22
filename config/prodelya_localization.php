<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prodelya Core Localization Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines localization settings for the Prodelya Core system.
    | It includes language, currency, number formatting, and regional settings.
    |
    */

    'supported_locales' => [
        'tr' => [
            'name' => 'Türkçe',
            'native_name' => 'Türkçe',
            'code' => 'tr',
            'region' => 'TR',
            'currency' => 'TL',
            'number_format_locale' => 'tr_TR',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'datetime_format' => 'd.m.Y H:i',
            'decimal_separator' => ',',
            'thousands_separator' => '.',
        ],
        'en' => [
            'name' => 'English',
            'native_name' => 'English',
            'code' => 'en',
            'region' => 'US',
            'currency' => 'USD',
            'number_format_locale' => 'en_US',
            'date_format' => 'm/d/Y',
            'time_format' => 'g:i A',
            'datetime_format' => 'm/d/Y g:i A',
            'decimal_separator' => '.',
            'thousands_separator' => ',',
        ],
    ],

    'currencies' => [
        'TL' => [
            'name' => 'Türk Lirası',
            'symbol' => '₺',
            'code' => 'TRY',
            'precision' => 2,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'symbol_position' => 'after', // before or after
            'format' => '1.234,56 ₺',
        ],
        'USD' => [
            'name' => 'US Dollar',
            'symbol' => '$',
            'code' => 'USD',
            'precision' => 2,
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'symbol_position' => 'before',
            'format' => '$1,234.56',
        ],
        'EUR' => [
            'name' => 'Euro',
            'symbol' => '€',
            'code' => 'EUR',
            'precision' => 2,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'symbol_position' => 'after',
            'format' => '1.234,56 €',
        ],
    ],

    'number_formats' => [
        'tr_TR' => [
            'name' => 'Türkçe (Türkiye)',
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'currency_format' => '1.234,56 TL',
            'number_format' => '1.234,567',
            'percentage_format' => '%1,2',
        ],
        'en_US' => [
            'name' => 'English (United States)',
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'currency_format' => '$1,234.56',
            'number_format' => '1,234.567',
            'percentage_format' => '1.2%',
        ],
        'de_DE' => [
            'name' => 'Deutsch (Deutschland)',
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'currency_format' => '1.234,56 €',
            'number_format' => '1.234,567',
            'percentage_format' => '1,2 %',
        ],
    ],

    'date_formats' => [
        'd.m.Y' => '31.12.2024',
        'd/m/Y' => '31/12/2024',
        'Y-m-d' => '2024-12-31',
        'm/d/Y' => '12/31/2024',
        'd M Y' => '31 Dec 2024',
        'd F Y' => '31 December 2024',
    ],

    'time_formats' => [
        'H:i' => '23:59',
        'H:i:s' => '23:59:59',
        'g:i A' => '11:59 PM',
        'g:i:s A' => '11:59:59 PM',
    ],

    'timezones' => [
        'Europe/Istanbul' => [
            'name' => 'İstanbul',
            'offset' => '+03:00',
            'dst' => false,
        ],
        'Europe/Berlin' => [
            'name' => 'Berlin',
            'offset' => '+01:00',
            'dst' => true,
        ],
        'America/New_York' => [
            'name' => 'New York',
            'offset' => '-05:00',
            'dst' => true,
        ],
        'Asia/Dubai' => [
            'name' => 'Dubai',
            'offset' => '+04:00',
            'dst' => false,
        ],
    ],

    'character_encoding' => [
        'database' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'http_headers' => 'UTF-8',
        'html_meta' => 'UTF-8',
        'supported_characters' => 'çÇğĞıİöÖşŞüÜ',
    ],

    'translation_files' => [
        'languages' => [
            'tr' => 'resources/lang/tr',
            'en' => 'resources/lang/en',
        ],
        'fallback_language' => 'tr',
        'auto_detect' => true,
        'session_key' => 'locale',
    ],

    'currency_conversion' => [
        'base_currency' => 'TL',
        'conversion_api' => 'tcmb', // Turkish Central Bank or other
        'update_frequency' => 'daily', // hourly, daily, weekly
        'cache_duration' => 86400, // 24 hours in seconds
        'precision' => 4,
    ],

    'regional_settings' => [
        'TR' => [
            'country' => 'Türkiye',
            'phone_code' => '+90',
            'tax_office_required' => true,
            'tax_number_format' => 'TCKN or VKN',
            'postal_code_format' => '5 digits',
            'date_format' => 'd.m.Y',
            'currency' => 'TL',
        ],
        'US' => [
            'country' => 'United States',
            'phone_code' => '+1',
            'tax_office_required' => false,
            'tax_number_format' => 'EIN',
            'postal_code_format' => 'ZIP+4',
            'date_format' => 'm/d/Y',
            'currency' => 'USD',
        ],
        'DE' => [
            'country' => 'Germany',
            'phone_code' => '+49',
            'tax_office_required' => true,
            'tax_number_format' => 'Steuernummer',
            'postal_code_format' => '5 digits',
            'date_format' => 'd.m.Y',
            'currency' => 'EUR',
        ],
    ],

    'validation_rules' => [
        'tax_number' => [
            'TR' => 'required|regex:/^[0-9]{10,11}$/', // TCKN or VKN
            'US' => 'required|regex:/^[0-9]{9}$/', // EIN
            'DE' => 'required|regex:/^[0-9]{11}$/', // Steuernummer
        ],
        'phone' => [
            'TR' => 'regex:/^(\+90|0)?[0-9]{10}$/',
            'US' => 'regex:/^(\+1)?[0-9]{10}$/',
            'DE' => 'regex:/^(\+49)?[0-9]{10,15}$/',
        ],
        'postal_code' => [
            'TR' => 'regex:/^[0-9]{5}$/',
            'US' => 'regex:/^[0-9]{5}(-[0-9]{4})?$/',
            'DE' => 'regex:/^[0-9]{5}$/',
        ],
    ],

    'ui_settings' => [
        'rtl_support' => false,
        'font_family' => 'Arial, Helvetica, sans-serif',
        'text_direction' => 'ltr',
        'decimal_separator_display' => true,
        'currency_symbol_display' => true,
    ],

    'email_templates' => [
        'default_language' => 'tr',
        'auto_translate' => false,
        'fallback_template' => 'tr',
        'supported_languages' => ['tr', 'en'],
    ],

    'pdf_settings' => [
        'font' => 'DejaVu Sans',
        'font_size' => 12,
        'encoding' => 'UTF-8',
        'unicode_support' => true,
        'turkish_characters' => true,
    ],
];
