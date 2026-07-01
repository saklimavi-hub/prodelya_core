<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'pdh_private' => [
            'driver' => env('PDH_PRIVATE_DISK_DRIVER', 'local'),
            'root' => env('PDH_PRIVATE_DISK_DRIVER', 'local') === 'local'
                ? storage_path('app/pdh/private')
                : storage_path('app/pdh/private'),
            'throw' => false,
            'report' => false,
        ],

        'pdh_public' => [
            'driver' => env('PDH_PUBLIC_DISK_DRIVER', 'local'),
            'root' => env('PDH_PUBLIC_DISK_DRIVER', 'local') === 'local'
                ? storage_path('app/public/pdh')
                : storage_path('app/public/pdh'),
            'url' => rtrim(env('PDH_PUBLIC_DISK_URL', env('APP_URL', 'http://localhost')), '/') . '/storage/pdh',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'pdh_temp' => [
            'driver' => env('PDH_TEMP_DISK_DRIVER', 'local'),
            'root' => env('PDH_TEMP_DISK_DRIVER', 'local') === 'local'
                ? storage_path('app/pdh/temp')
                : storage_path('app/pdh/temp'),
            'throw' => false,
            'report' => false,
        ],

        'product_images' => [
            'driver' => env('PDH_PRODUCT_IMAGES_DISK_DRIVER', 'local'),
            'root' => env('PDH_PRODUCT_IMAGES_DISK_DRIVER', 'local') === 'local'
                ? storage_path('app/public/product-images')
                : storage_path('app/public/product-images'),
            'url' => rtrim(env('PDH_PRODUCT_IMAGES_DISK_URL', env('APP_URL', 'http://localhost')), '/') . '/storage/product-images',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'exports' => [
            'driver' => env('PDH_EXPORTS_DISK_DRIVER', 'local'),
            'root' => env('PDH_EXPORTS_DISK_DRIVER', 'local') === 'local'
                ? storage_path('app/exports')
                : storage_path('app/exports'),
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
