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
        |----------------------------------------------------------------------
        | Branches beneath each client
        |----------------------------------------------------------------------
        |
        | Leave null unless your product records branches. Most do: a school has
        | campuses, a facility has sites, and the day-to-day records hang off
        | the branch rather than the business — 103 of School Monitor's tables
        | carry `school_branch_id` and 15 carry `school_id`.
        |
        |     'branches' => [
        |         'table' => 'school_branches',   // where the branches live
        |         'via' => 'school_id',           // branch row -> its client
        |         'external_id' => 'id',          // stable, like the client's
        |         'name' => 'name',
        |         'key' => 'school_branch_id',    // activity row -> its branch
        |
        |         // Optional, and worth filling in: this is usually the only
        |         // place a client's address and contacts exist at all.
        |         'address' => 'address',
        |         'contact_phone' => 'main_contact',
        |         'contact_email' => 'email',
        |     ],
        |
        | `key` is what does the work. Where a metric names no `via` of its own,
        | the client's figure becomes every one of its branches — which is how a
        | table that only knows the branch is counted for the business. And each
        | branch's own share is the same query narrowed to that one branch.
        |
        | A branch is never scored. Retention Intel keeps one health score and
        | one watchlist entry per business, whichever level your product
        | happens to bill; the breakdown is what somebody reads when that score
        | falls and they need to know which branch stopped.
        |
        */

        'branches' => null,

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
    | Three more keys, for the metrics that are not a plain count of a table.
    | `login_count_7d` needs all three more often than not, because almost no
    | two products record a login in the same place:
    |
    |   'distinct' => 'user_id'
    |       Counts the people behind the rows rather than the rows. Three
    |       sessions from one nurse is one nurse.
    |
    |   'where' => ['action' => 'login']
    |       Narrows to the rows that are the event you mean. An audit trail is
    |       one table holding everything that ever happened, so counting logins
    |       out of it means saying which action is one. Give it a list to match
    |       any of several: ['action' => ['login', 'signed_in']].
    |
    |   'date_format' => 'timestamp'
    |       For a column holding a unix integer instead of a datetime. Laravel's
    |       own `sessions.last_activity` is one. WITHOUT THIS the window is
    |       compared against a datetime string, which MySQL casts to 0 — every
    |       row matches and the metric quietly reports the whole table.
    |
    | Reading logins out of Laravel's sessions table, which carries no tenant
    | column of its own, therefore looks like this:
    |
    |     'login_count_7d' => [
    |         'table'       => 'sessions',
    |         'distinct'    => 'user_id',
    |         'via'         => ['user_id' => ['users', 'id', 'business_id']],
    |         'date'        => 'last_activity',
    |         'date_format' => 'timestamp',
    |     ],
    |
    | If your product records nothing that means "somebody used this", leave the
    | metric out. Retention Intel asks only for what it scores you on, and a
    | product that cannot report a component is scored across the ones it can
    | rather than marked down for the gap.
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
