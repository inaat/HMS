<?php

/*
 * Order-booker mobile sync.
 *
 * The same code runs on two machines:
 *  - local (main): owns all data, pushes products / customers / stock / bookers up, pulls orders and payments down.
 *  - cloud (shared hosting): only a post office. Serves the mobile app and holds bookers' orders and payments
 *    until the local PC collects them. It never makes invoices or changes stock.
 */
return [
    // local | cloud | off
    'role' => env('MOBILE_SYNC_ROLE', 'off'),

    // Shared secret between the local PC and the cloud (header X-Sync-Key). Long random string, same on both.
    'sync_key' => env('MOBILE_SYNC_KEY'),

    // Local only: base URL of the cloud copy, e.g. https://pos.example.com
    'cloud_url' => rtrim((string) env('MOBILE_SYNC_CLOUD_URL', ''), '/'),

    // Local only: which business / location the bookers sell from.
    'business_id' => (int) env('MOBILE_SYNC_BUSINESS_ID', 1),
    'location_id' => (int) env('MOBILE_SYNC_LOCATION_ID', 1),

    // Local only: also copy every local database change to the cloud database (`mobile-sync:mirror`), as SQL over
    // HTTPS to /api/sync/mirror/sql. The cloud web screens are then read-only, because the copy overwrites them.
    'mirror' => (bool) env('MOBILE_SYNC_MIRROR', false),
    // Local only: WhatsApp the customer the order slip when a booker order arrives, and a receipt when a booker
    // collection is approved (through the connected device in Settings > WhatsApp).
    'whatsapp' => (bool) env('MOBILE_SYNC_WHATSAPP', true),
    // Local only: php.exe that the browser-started sync runs (empty = next to the loaded php.ini, as in Laragon).
    'php_bin' => env('MOBILE_SYNC_PHP_BIN'),
    // Folder with mysqldump when it is not on PATH (empty = next to the running MySQL server).
    'mysql_bin' => env('MOBILE_SYNC_MYSQL_BIN'),

    // Mobile login token lifetime in days (0 = never expires; blocking the booker locally still revokes it).
    'token_days' => (int) env('MOBILE_SYNC_TOKEN_DAYS', 90),
];
