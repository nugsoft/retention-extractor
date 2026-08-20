<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Retention Intel extractor
|--------------------------------------------------------------------------
|
| This file is the contract between your product and Retention Intel. Nothing
| is inferred at run time: what gets pushed is exactly what you describe here.
|
| `php artisan retention:install` inspects your schema and fills this in with
| its best guesses. Check every line it wrote before enabling the schedule —
| a wrong mapping produces confident, wrong numbers rather than an error.
|
| Preview what would be sent without sending it:
|
|     php artisan retention:push --dry-run
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    */

    'api' => [
        'url' => env('RETENTION_API_URL', 'https://retention.nugsoft.com'),
        'key' => env('RETENTION_API_KEY'),
        'timeout' => 15,
        'retries' => 3,
    ],

    /*
    | Must match a `code` in Retention Intel's products table, and must match
    | the product your API key is scoped to.
    */

    'product' => env('RETENTION_PRODUCT_CODE'),

    /*
    | Set false to keep the package installed but silent.
    */

    'enabled' => env('RETENTION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Who your clients are
    |--------------------------------------------------------------------------
    |
    | Multi-tenant — one install serving many businesses. Point `model` at the
    | tenant model and the extractor pushes one snapshot per row:
    |
    |     'model' => \App\Models\Business::class,
    |     'external_id' => 'id',
    |     'name' => 'business_name',
    |
    | Single-tenant — one install per business. Leave `model` null and set
    | RETENTION_EXTERNAL_ID and RETENTION_CLIENT_NAME in .env instead. The
    | extractor then pushes exactly one snapshot and every `via` key below is
    | ignored, since there is nothing to scope by.
    |
    */

    'clients' => [

        'model' => null,

        'external_id' => 'id',
        'name' => 'name',
        'contact_phone' => null,
        'contact_email' => null,

        /*
        | Optional: limit which rows are pushed. Receives the query builder.
        |
        |     'scope' => fn ($query) => $query->where('is_active', true),
        */

        'scope' => null,

        /*
        | Used only when `model` is null.
        */

        'single' => [
            'external_id' => env('RETENTION_EXTERNAL_ID'),
            'name' => env('RETENTION_CLIENT_NAME', env('APP_NAME')),
            'contact_phone' => env('RETENTION_CLIENT_PHONE'),
            'contact_email' => env('RETENTION_CLIENT_EMAIL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | What counts as activity
    |--------------------------------------------------------------------------
    |
    | Each entry is one aggregate over one table, restricted to the last
    | `window_days`. The key must be a metric Retention Intel knows — see the
    | list at the bottom of this file.
    |
    |     'items_sold_7d' => [
    |         'table' => 'sale_items',   // table to aggregate
    |         'sum'   => 'quantity',     // or 'count' => '*'
    |         'via'   => 'business_id',  // column linking rows to the client
    |         'date'  => 'created_at',   // column used for the time window
    |     ],
    |
    | `via` may also be a two-step path when the table has no direct link:
    |
    |         'via' => ['sale_id' => ['sales', 'id', 'business_id']],
    |
    */

    'metrics' => [],

    'window_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | When the client was last active
    |--------------------------------------------------------------------------
    |
    | The most recent row in this table is what drives dormancy — the single
    | most important signal in the whole system. Point it at the table that
    | best represents real use of your product.
    |
    |     'last_activity' => ['table' => 'sales', 'via' => 'business_id', 'date' => 'created_at'],
    |
    */

    'last_activity' => null,

    /*
    |--------------------------------------------------------------------------
    | Subscriptions (optional)
    |--------------------------------------------------------------------------
    |
    | Leave null and no subscription data is pushed. `status_map` translates
    | your product's wording into Retention Intel's: active, expired, cancelled.
    |
    */

    'subscription' => null,

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Registered automatically. Set `time` to null to schedule it yourself.
    |
    */

    'schedule' => [
        'time' => env('RETENTION_PUSH_TIME', '02:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Retention Intel accepts
    |--------------------------------------------------------------------------
    |
    | Every product sends:            login_count_7d
    | POScream / POSCafe:             items_sold_7d, transactions_7d, transaction_value_7d
    | Clinic Plus:                    visits_7d, lab_requests_7d, prescriptions_7d, new_patients_7d
    | Mfuko:                          member_registrations_7d, loan_disbursements_7d,
    |                                 transactions_7d, transaction_value_7d
    | School Monitor:                 academic_entries_7d, attendance_records_7d, fee_payments_7d
    |
    | Anything else you send is preserved in raw_payload but not scored.
    |
    */

];
