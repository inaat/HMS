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

    // Mobile login token lifetime in days (0 = never expires; blocking the booker locally still revokes it).
    'token_days' => (int) env('MOBILE_SYNC_TOKEN_DAYS', 90),
];
