<?php

use Dotenv\Dotenv;

// Load .env file
require_once __DIR__ . '/../../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

return [
    'backend' => [
        'frontName' => getenv('BACKEND_FRONT_NAME')
    ],
    'queue' => [
        'consumers_wait_for_messages' => 1
    ],
    'crypt' => [
        'key' => getenv('CRYPT_KEY')
    ],
    'db' => [
        'table_prefix' => '',
        'connection' => [
            'default' => [
                'host' => getenv('DB_HOST'),
                'dbname' => getenv('DB_NAME'),
                'username' => getenv('DB_USER'),
                'password' => getenv('DB_PASSWORD'),
                'model' => 'mysql4',
                'engine' => 'innodb',
                'initStatements' => 'SET NAMES utf8;',
                'active' => '1',
                'driver_options' => [
                    1014 => false
                ]
            ]
        ]
    ],
    'resource' => [
        'default_setup' => [
            'connection' => 'default'
        ]
    ],
    'x-frame-options' => 'SAMEORIGIN',
    'MAGE_MODE' => getenv('MAGE_MODE'),
    'session' => [
        'save' => 'files'
    ],
    'cache' => [
        'frontend' => [
            'default' => [
                'id_prefix' => '38d_'
            ],
            'page_cache' => [
                'id_prefix' => '38d_'
            ]
        ]
    ],
    'lock' => [
        'provider' => 'db',
        'config' => [
            'prefix' => ''
        ]
    ],
    'cache_types' => [
        'config' => 1,
        'layout' => 1,
        'block_html' => 1,
        'collections' => 1,
        'reflection' => 1,
        'db_ddl' => 1,
        'compiled_config' => 1,
        'eav' => 1,
        'customer_notification' => 1,
        'config_integration' => 1,
        'config_integration_api' => 1,
        'google_product' => 1,
        'full_page' => 0,
        'config_webservice' => 1,
        'translate' => 1,
        'vertex' => 1
    ],
    'downloadable_domains' => [getenv('DOWNLOADABLE_DOMAIN')],
    'install' => [
        'date' => 'Tue, 31 Aug 2021 17:41:08 +0000'
    ]
];
