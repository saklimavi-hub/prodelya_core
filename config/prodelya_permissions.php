<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prodelya Core Permissions Configuration
    |--------------------------------------------------------------------------
    |
    | This file defines all available permissions in the Prodelya Core system.
    | Permissions are grouped by functional areas for better organization.
    |
    */

    'permissions' => [
        // Financial Visibility Permissions
        'financial' => [
            'view_order_finance_summary' => [
                'name' => 'Sipariş Finans Özetini Görüntüle',
                'description' => 'Sipariş toplamları ve finans özetini görüntüleme yetkisi',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'view_sales_prices' => [
                'name' => 'Satış Fiyatlarını Görüntüle',
                'description' => 'Ürün satış fiyatlarını görüntüleme yetkisi',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'view_quote_totals' => [
                'name' => 'Teklif Tutarlarını Görüntüle',
                'description' => 'Teklif toplamlarını görüntüleme yetkisi',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'view_profit_margin' => [
                'name' => 'Kar Marjını Görüntüle',
                'description' => 'Kar marjı ve kâr/zarar bilgilerini görüntüleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'view_customer_balance' => [
                'name' => 'Müşteri Bakiyesini Görüntüle',
                'description' => 'Müşteri cari hesap bakiyelerini görüntüleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'view_payment_details' => [
                'name' => 'Ödeme Detaylarını Görüntüle',
                'description' => 'Ödeme planı ve ödeme bilgilerini görüntüleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'view_actual_costs' => [
                'name' => 'Gerçek Maliyetleri Görüntüle',
                'description' => 'Ürün maliyetleri ve giderleri görüntüleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'manage_payments' => [
                'name' => 'Tahsilat Kayıtlarını Yönet',
                'description' => 'Tahsilat ve ödeme kayıtlarını ekleme/düzenleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'mark_payments_received' => [
                'name' => 'Ödendi İşaretle',
                'description' => 'Tahsilat kayıtlarını ödendi olarak işaretleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'view_current_account_transactions' => [
                'name' => 'Cari Hareketlerini Görüntüle',
                'description' => 'Cari hareket listesi, bakiye ve özet bilgilerini görüntüleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'manage_current_account_transactions' => [
                'name' => 'Cari Hareketlerini Yönet',
                'description' => 'Manuel cari hareket oluşturma ve düzenleme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
            'cancel_current_account_transactions' => [
                'name' => 'Cari Hareket İptal Et',
                'description' => 'Cari hareketleri fiziksel silmeden iptal etme',
                'category' => 'financial',
                'risk_level' => 'high',
            ],
        ],

        // Cost Management Permissions
        'costs' => [
            'enter_product_supply_costs' => [
                'name' => 'Ürün Temin Maliyeti Gir',
                'description' => 'Ürün temin maliyetlerini girme/güncelleme',
                'category' => 'costs',
                'risk_level' => 'medium',
            ],
            'enter_material_costs' => [
                'name' => 'Maliyet Malzemesi Gir',
                'description' => 'Üretim malzeme maliyetlerini girme',
                'category' => 'costs',
                'risk_level' => 'medium',
            ],
            'enter_process_costs' => [
                'name' => 'İşlem Maliyeti Gir',
                'description' => 'Üretim işlem maliyetlerini girme',
                'category' => 'costs',
                'risk_level' => 'medium',
            ],
            'enter_preparation_costs' => [
                'name' => 'Hazırlık Maliyeti Gir',
                'description' => 'Hazırlık maliyetlerini girme',
                'category' => 'costs',
                'risk_level' => 'medium',
            ],
            'approve_actual_costs' => [
                'name' => 'Gerçek Maliyetleri Onayla',
                'description' => 'Maliyetlerin onaylanması',
                'category' => 'costs',
                'risk_level' => 'medium',
            ],
        ],

        // Management Permissions
        'management' => [
            'manage_tenant_settings' => [
                'name' => 'Tenant Ayarlarını Yönet',
                'description' => 'Tenant ayarlarını değiştirme yetkisi',
                'category' => 'management',
                'risk_level' => 'high',
            ],
            'manage_users' => [
                'name' => 'Kullanıcıları Yönet',
                'description' => 'Kullanıcı ekleme, düzenleme, silme',
                'category' => 'management',
                'risk_level' => 'medium',
            ],
            'manage_roles' => [
                'name' => 'Rolleri Yönet',
                'description' => 'Rol oluşturma ve yetki yönetimi',
                'category' => 'management',
                'risk_level' => 'high',
            ],
            'manage_modules' => [
                'name' => 'Modülleri Yönet',
                'description' => 'Aktif modülleri yönetme',
                'category' => 'management',
                'risk_level' => 'high',
            ],
        ],

        // Order Management Permissions
        'orders' => [
            'create_quotes' => [
                'name' => 'Teklif Oluştur',
                'description' => 'Yeni teklifler oluşturma',
                'category' => 'orders',
                'risk_level' => 'low',
            ],
            'edit_quotes' => [
                'name' => 'Teklif Düzenle',
                'description' => 'Mevcut teklifleri düzenleme',
                'category' => 'orders',
                'risk_level' => 'low',
            ],
            'delete_quotes' => [
                'name' => 'Teklif Sil',
                'description' => 'Teklifleri silme',
                'category' => 'orders',
                'risk_level' => 'medium',
            ],
            'approve_quotes' => [
                'name' => 'Teklif Onayla',
                'description' => 'Teklifleri onaylama',
                'category' => 'orders',
                'risk_level' => 'medium',
            ],
            'create_orders' => [
                'name' => 'Sipariş Oluştur',
                'description' => 'Yeni siparişler oluşturma',
                'category' => 'orders',
                'risk_level' => 'low',
            ],
            'edit_orders' => [
                'name' => 'Sipariş Düzenle',
                'description' => 'Mevcut siparişleri düzenleme',
                'category' => 'orders',
                'risk_level' => 'low',
            ],
            'delete_orders' => [
                'name' => 'Sipariş Sil',
                'description' => 'Siparişleri silme',
                'category' => 'orders',
                'risk_level' => 'medium',
            ],
            'approve_orders' => [
                'name' => 'Sipariş Onayla',
                'description' => 'Siparişleri onaylama',
                'category' => 'orders',
                'risk_level' => 'medium',
            ],
            'convert_quote_to_order' => [
                'name' => 'Teklifi Siparişe Çevir',
                'description' => 'Teklifleri siparişe dönüştürme',
                'category' => 'orders',
                'risk_level' => 'medium',
            ],
        ],

        // Customer Management Permissions
        'customers' => [
            'view_customers' => [
                'name' => 'Müşterileri Görüntüle',
                'description' => 'Müşteri listesini görüntüleme',
                'category' => 'customers',
                'risk_level' => 'low',
            ],
            'create_customers' => [
                'name' => 'Müşteri Oluştur',
                'description' => 'Yeni müşteriler ekleme',
                'category' => 'customers',
                'risk_level' => 'low',
            ],
            'edit_customers' => [
                'name' => 'Müşteri Düzenle',
                'description' => 'Müşteri bilgilerini düzenleme',
                'category' => 'customers',
                'risk_level' => 'low',
            ],
            'delete_customers' => [
                'name' => 'Müşteri Sil',
                'description' => 'Müşterileri silme',
                'category' => 'customers',
                'risk_level' => 'medium',
            ],
        ],

        // Product Management Permissions
        'products' => [
            'view_products' => [
                'name' => 'Ürünleri Görüntüle',
                'description' => 'Ürün kataloğunu görüntüleme',
                'category' => 'products',
                'risk_level' => 'low',
            ],
            'manage_product_data_hub' => [
                'name' => 'Ürün Veri Merkezini Yönet',
                'description' => 'Tedarikçi veri senkronizasyonu',
                'category' => 'products',
                'risk_level' => 'medium',
            ],
            'manage_advanced_catalog' => [
                'name' => 'Gelişmiş Katalogu Yönet',
                'description' => 'Tenant ürün katalogu yönetimi',
                'category' => 'products',
                'risk_level' => 'medium',
            ],
            'manage_stock' => [
                'name' => 'Stok Yönetimi',
                'description' => 'Stok giriş/çıkış ve takip',
                'category' => 'products',
                'risk_level' => 'medium',
            ],
        ],

        // Supplier Management Permissions
        'suppliers' => [
            'view_suppliers' => [
                'name' => 'Tedarikçileri Görüntüle',
                'description' => 'Tedarikçi listesini görüntüleme',
                'category' => 'suppliers',
                'risk_level' => 'low',
            ],
            'manage_suppliers' => [
                'name' => 'Tedarikçileri Yönet',
                'description' => 'Tedarikçi ekleme, düzenleme, silme',
                'category' => 'suppliers',
                'risk_level' => 'medium',
            ],
            'manage_supplier_feeds' => [
                'name' => 'Tedarikçi Beslemelerini Yönet',
                'description' => 'XML/API tedarikçi entegrasyonları',
                'category' => 'suppliers',
                'risk_level' => 'medium',
            ],
            'manage_procurement_requests' => [
                'name' => 'Tedarik Taleplerini Yönet',
                'description' => 'Tedarikçi bazlı toplu talep oluşturma ve durum yönetimi',
                'category' => 'suppliers',
                'risk_level' => 'medium',
            ],
            'view_procurement_purchase_prices' => [
                'name' => 'Tedarik Alış Fiyatlarını Görüntüle',
                'description' => 'İç alış liste fiyatı, iskonto ve toplamları görüntüleme',
                'category' => 'suppliers',
                'risk_level' => 'high',
            ],
            'generate_supplier_request_form' => [
                'name' => 'Tedarikçi Talep Formu Oluştur',
                'description' => 'Fiyatsız dış tedarikçi talep formu üretme',
                'category' => 'suppliers',
                'risk_level' => 'medium',
            ],
        ],

        // Reporting Permissions
        'reports' => [
            'view_basic_reports' => [
                'name' => 'Temel Raporları Görüntüle',
                'description' => 'Temel sipariş ve teklif raporları',
                'category' => 'reports',
                'risk_level' => 'low',
            ],
            'view_advanced_reports' => [
                'name' => 'Gelişmiş Raporları Görüntüle',
                'description' => 'Detaylı analiz ve karlılık raporları',
                'category' => 'reports',
                'risk_level' => 'medium',
            ],
            'export_reports' => [
                'name' => 'Raporları Dışa Aktar',
                'description' => 'Raporları Excel/PDF olarak dışa aktarma',
                'category' => 'reports',
                'risk_level' => 'low',
            ],
        ],

        // Accounting Integration Permissions
        'accounting' => [
            'manage_accounting_export' => [
                'name' => 'Muhasebe Çıkışını Yönet',
                'description' => 'Muhasebe programı entegrasyonları',
                'category' => 'accounting',
                'risk_level' => 'high',
            ],
            'view_accounting_logs' => [
                'name' => 'Muhasebe Loglarını Görüntüle',
                'description' => 'Muhasebe entegrasyon logları',
                'category' => 'accounting',
                'risk_level' => 'low',
            ],
        ],

        'notifications' => [
            'view_notification_center' => [
                'name' => 'Bildirim Merkezini Görüntüle',
                'description' => 'Bildirim merkezi ekranlarını görüntüleme',
                'category' => 'notifications',
                'risk_level' => 'medium',
            ],
            'manage_notification_settings' => [
                'name' => 'Bildirim Ayarlarını Yönet',
                'description' => 'Tenant SMTP ve kanal ayarlarını düzenleme',
                'category' => 'notifications',
                'risk_level' => 'high',
            ],
            'manage_notification_templates' => [
                'name' => 'Bildirim Şablonlarını Yönet',
                'description' => 'Bildirim şablonlarını ekleme ve güncelleme',
                'category' => 'notifications',
                'risk_level' => 'high',
            ],
            'view_notification_logs' => [
                'name' => 'Bildirim Loglarını Görüntüle',
                'description' => 'Bildirim geçmişi ve hata kayıtlarını görüntüleme',
                'category' => 'notifications',
                'risk_level' => 'medium',
            ],
            'send_test_notifications' => [
                'name' => 'Test Bildirimi Gönder',
                'description' => 'Test e-postası veya test bildirim akışını çalıştırma',
                'category' => 'notifications',
                'risk_level' => 'medium',
            ],
        ],

        // System Permissions
        'system' => [
            'view_audit_logs' => [
                'name' => 'Denetim Loglarını Görüntüle',
                'description' => 'Sistem denetim kayıtlarını görüntüleme',
                'category' => 'system',
                'risk_level' => 'medium',
            ],
            'manage_system_settings' => [
                'name' => 'Sistem Ayarlarını Yönet',
                'description' => 'Genel sistem ayarları',
                'category' => 'system',
                'risk_level' => 'high',
            ],
            'view_system_status' => [
                'name' => 'Sistem Durumunu Görüntüle',
                'description' => 'Sistem durumu ve performans bilgileri',
                'category' => 'system',
                'risk_level' => 'low',
            ],
        ],
    ],

    'default_roles' => [
        'tenant_owner' => [
            'name' => 'Tenant Sahibi',
            'description' => 'Tenant tam yönetim yetkisi',
            'permissions' => '*', // All permissions
            'is_system' => false,
        ],

        'admin' => [
            'name' => 'Yönetici',
            'description' => 'Genel yönetim yetkileri',
            'permissions' => [
                'financial' => ['*'],
                'management' => ['manage_users', 'manage_roles'],
                'orders' => ['*'],
                'customers' => ['*'],
                'products' => ['*'],
                'suppliers' => ['*'],
                'reports' => ['*'],
                'accounting' => ['*'],
                'notifications' => ['*'],
                'system' => ['view_audit_logs', 'view_system_status'],
            ],
            'is_system' => false,
        ],

        'sales' => [
            'name' => 'Satış',
            'description' => 'Satış personeli yetkileri',
            'permissions' => [
                'financial' => ['view_order_finance_summary', 'view_sales_prices', 'view_quote_totals'],
                'orders' => ['create_quotes', 'edit_quotes', 'view_quotes'],
                'customers' => ['view_customers', 'create_customers', 'edit_customers'],
                'products' => ['view_products'],
                'notifications' => ['view_notification_center'],
                'reports' => ['view_basic_reports', 'export_reports'],
            ],
            'is_system' => false,
        ],

        'finance' => [
            'name' => 'Finans',
            'description' => 'Finans personeli yetkileri',
            'permissions' => [
                'financial' => ['*'],
                'costs' => ['*'],
                'orders' => ['view_quotes', 'view_orders'],
                'customers' => ['view_customers'],
                'reports' => ['*'],
                'accounting' => ['*'],
                'notifications' => ['view_notification_center', 'view_notification_logs', 'send_test_notifications'],
            ],
            'is_system' => false,
        ],

        'graphic' => [
            'name' => 'Grafik',
            'description' => 'Grafik tasarım personeli',
            'permissions' => [
                'orders' => ['view_quotes', 'view_orders'],
                'products' => ['view_products'],
            ],
            'is_system' => false,
        ],

        'production' => [
            'name' => 'Üretim',
            'description' => 'Üretim personeli',
            'permissions' => [
                'orders' => ['view_orders'],
                'products' => ['view_products', 'manage_stock'],
                'suppliers' => ['view_suppliers'],
            ],
            'is_system' => false,
        ],

        'supplier_operator' => [
            'name' => 'Tedarikçi Operatör',
            'description' => 'Tedarikçi operasyon personeli',
            'permissions' => [
                'products' => ['manage_product_data_hub'],
                'suppliers' => ['view_suppliers', 'manage_supplier_feeds'],
            ],
            'is_system' => false,
        ],

        'warehouse' => [
            'name' => 'Depo',
            'description' => 'Depo personeli',
            'permissions' => [
                'orders' => ['view_orders'],
                'products' => ['view_products', 'manage_stock'],
            ],
            'is_system' => false,
        ],

        'delivery' => [
            'name' => 'Teslimat',
            'description' => 'Teslimat personeli',
            'permissions' => [
                'orders' => ['view_orders'],
                'customers' => ['view_customers'],
            ],
            'is_system' => false,
        ],

        'customer_portal_user' => [
            'name' => 'Müşteri Portalı Kullanıcısı',
            'description' => 'Portal müşterileri',
            'permissions' => [
                'orders' => ['view_quotes', 'view_orders'],
                'customers' => ['view_customers'], // Only own company
            ],
            'is_system' => false,
        ],
    ],
];
