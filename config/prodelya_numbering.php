<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prodelya Core Numbering Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines numbering formats and rules for documents in the
    | Prodelya Core system. Each tenant can have their own sequences.
    |
    */

    'document_types' => [
        'quote' => [
            'name' => 'Teklif',
            'prefix' => 'TK',
            'format' => 'TK-{YYYY}-{SEQ4}',
            'sequence_length' => 4,
            'reset_period' => 'yearly',
            'description' => 'Teklif belge numarası formatı',
        ],
        'order' => [
            'name' => 'Sipariş',
            'prefix' => 'SP',
            'format' => 'SP-{YYYY}-{SEQ4}',
            'sequence_length' => 4,
            'reset_period' => 'yearly',
            'description' => 'Sipariş belge numarası formatı',
        ],
    ],

    'order_families' => [
        'promotion' => [
            'name' => 'Promosyon',
            'description' => 'Promosyon sipariş ailesi',
            'numbering_prefix' => 'PR',
        ],
        'print' => [
            'name' => 'Matbaa',
            'description' => 'Matbaa sipariş ailesi',
            'numbering_prefix' => 'MT',
        ],
    ],

    'format_placeholders' => [
        '{YYYY}' => '4 haneli yıl (2024)',
        '{YY}' => '2 haneli yıl (24)',
        '{MM}' => '2 haneli ay (01-12)',
        '{DD}' => '2 haneli gün (01-31)',
        '{SEQ4}' => '4 haneli sıra numarası (0001)',
        '{SEQ}' => 'Sıra numarası (ayarlanan uzunlukta)',
        '{TENANT}' => 'Tenant kodu',
        '{FAMILY}' => 'Sipariş ailesi kodu',
    ],

    'reset_periods' => [
        'yearly' => [
            'name' => 'Yıllık',
            'description' => 'Her yıl başında sıfırlanır',
        ],
        'monthly' => [
            'name' => 'Aylık',
            'description' => 'Her ay başında sıfırlanır',
        ],
        'never' => [
            'name' => 'Hiçbir Zaman',
            'description' => 'Asla sıfırlanmaz',
        ],
    ],

    'validation_rules' => [
        'min_sequence_length' => 2,
        'max_sequence_length' => 10,
        'required_placeholders' => ['{SEQ}'],
        'unique_per_tenant' => true,
        'year_based_uniqueness' => true,
    ],

    'number_generation' => [
        'cache_enabled' => true,
        'cache_ttl' => 3600, // 1 hour
        'batch_size' => 10, // Pre-generate numbers in batches
        'retry_attempts' => 3,
        'lock_timeout' => 30, // seconds
    ],

    'examples' => [
        'quote_2024' => 'TK-2024-0001',
        'order_2024' => 'SP-2024-0001',
        'quote_monthly' => 'TK-202401-0001',
        'order_with_family' => 'SP-PR-2024-0001',
    ],

    'defaults' => [
        'sequence_length' => 4,
        'reset_period' => 'yearly',
        'start_number' => 1,
        'auto_reset' => true,
    ],
];
