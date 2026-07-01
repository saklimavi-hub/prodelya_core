<?php

return [
    'enabled' => filter_var(env('PRODELYA_BACKUP_ENABLED', true), FILTER_VALIDATE_BOOL),
    'display_name' => 'Canlı Yedekleme Hazırlığı',
    'expected_frequency_hours' => (int) env('PRODELYA_BACKUP_EXPECTED_FREQUENCY_HOURS', 24),
    'warning_after_hours' => (int) env('PRODELYA_BACKUP_WARNING_AFTER_HOURS', 30),
    'critical_after_hours' => (int) env('PRODELYA_BACKUP_CRITICAL_AFTER_HOURS', 48),
    'monitored_paths' => [
        [
            'key' => 'general_backups_primary',
            'label' => 'Genel yedek arşivi',
            'path' => storage_path('app/backups'),
            'scope' => 'general_backup',
        ],
        [
            'key' => 'general_backups_secondary',
            'label' => 'Alternatif yedek arşivi',
            'path' => storage_path('app/backup'),
            'scope' => 'general_backup',
        ],
        [
            'key' => 'product_data_hub_category_backups',
            'label' => 'Product Data Hub kategori arşivi',
            'path' => storage_path('app/product-data-hub/category-backups'),
            'scope' => 'product_data_hub_category_backup',
        ],
    ],
    'required_storage_checks' => [
        'public_storage_link',
        'public_disk_readable',
        'public_disk_writable',
        'storage_logs_writable',
        'bootstrap_cache_writable',
        'filesystem_disk',
        'attachment_visibility',
        'pdf_attachment_storage',
        'work_folder_storage',
        'pdh_disks',
    ],
    'restore_checklist_enabled' => filter_var(env('PRODELYA_BACKUP_RESTORE_CHECKLIST_ENABLED', true), FILTER_VALIDATE_BOOL),
];
