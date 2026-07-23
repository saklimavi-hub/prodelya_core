<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prodelya Core Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for the Prodelya Core SaaS system.
    | It defines system-wide settings, defaults, and constants.
    |
    */

    'system' => [
        'name' => 'Prodelya Core',
        'version' => '1.0.0',
        'timezone' => 'Europe/Istanbul',
        'locale' => 'tr',
        'currency' => 'TL',
    ],

    'tenant' => [
        'default_status' => 'active',
        'default_locale' => 'tr',
        'default_currency' => 'TL',
        'default_timezone' => 'Europe/Istanbul',
        'default_number_format_locale' => 'tr_TR',
    ],

    'order_families' => [
        'promotion' => [
            'name' => 'Promosyon',
            'description' => 'Promosyon ürünleri teklif ve siparişleri',
            'modes' => [
                'product_sale_print' => 'Ürün Satışı + Baskı',
                'print_service_only' => 'Sadece Baskı Hizmeti',
            ],
        ],
        'print' => [
            'name' => 'Matbaa',
            'description' => 'Matbaa teklif ve siparişleri',
            'modes' => [
                'product_sale_print' => 'Ürün Satışı + Baskı',
                'print_service_only' => 'Sadece Baskı Hizmeti',
            ],
        ],
    ],

    'document_types' => [
        'quote' => [
            'name' => 'Teklif',
            'prefix' => 'TK',
            'format' => 'TK-{YYYY}-{SEQ4}',
        ],
        'order' => [
            'name' => 'Sipariş',
            'prefix' => 'SP',
            'format' => 'SP-{YYYY}-{SEQ4}',
        ],
    ],

    'numbering' => [
        'sequence_length' => 4,
        'reset_period' => 'yearly',
        'zero_pad' => true,
    ],

    'financial_visibility' => [
        'protected_fields' => [
            'subtotal',
            'vat_total',
            'grand_total',
            'profit_margin',
            'cost_price',
            'supplier_price',
        ],
        'financial_permissions' => [
            'view_order_finance_summary',
            'view_sales_prices',
            'view_quote_totals',
            'view_profit_margin',
            'view_customer_balance',
            'view_payment_details',
            'view_actual_costs',
        ],
    ],

    'audit' => [
        'log_financial_access' => true,
        'log_permission_violations' => true,
        'log_module_changes' => true,
        'retention_days' => 365,
    ],

    'security' => [
        'require_approval_for_orders' => true,
        'allow_negative_stock' => false,
        'max_login_attempts' => 5,
        'session_timeout' => 480, // minutes
    ],

    'features' => [
        'customer_portal' => false,
        'supplier_portal' => false,
        'api_access' => false,
        'advanced_reports' => false,
        'web_quote_widget' => false,
        'promotion_intermediate_element_enabled' => env('PRODELYA_PROMOTION_INTERMEDIATE_ELEMENT_ENABLED', false),
    ],
];
