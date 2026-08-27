# Retention Extractor

Pushes client activity and subscription data from a Laravel product into
[Retention Intel](https://retention.nugsoft.com), so churn risk is spotted
before the client leaves.

## What it does

Once a day it reads your database, works out how much each of your clients
actually used the product in the last seven days, and posts that to Retention
Intel. Nothing is ever written to your database — every query is read-only.

## Install

```bash
composer require nugsoft/retention-extractor
php artisan retention:install
```

`retention:install` reads your schema, proposes a mapping, and writes
`config/retention-extractor.php`.

Then add to `.env`:

```dotenv
RETENTION_API_URL=https://retention.nugsoft.com
RETENTION_API_KEY=          # issued by the CTO, one per product
RETENTION_PRODUCT_CODE=poscream
```

Preview what would be sent, without sending it:

```bash
php artisan retention:push --dry-run
```

When that looks right, you're done — the daily push is scheduled automatically
at 02:00. Make sure your scheduler cron is running.

## It cannot guess, and does not try

There is no reliable way for a package to know that your `sale_items.quantity`
means "items sold". It could guess from table names, but a wrong guess would
push confident, wrong numbers — clients would appear on or vanish from the
retention watchlist for reasons nobody could see.

So the config file is the contract. `retention:install` fills it in with its
best reading of your schema to save you typing, and then it is on you to check
what it wrote. Anything it cannot find, it leaves blank and the push refuses to
run until you fill it in.

## Configuration

### Who your clients are

**Multi-tenant** — one install serving many businesses:

```php
'clients' => [
    'model' => \App\Models\Business::class,
    'external_id' => 'id',            // must never change for a given business
    'name' => 'business_name',
    'contact_phone' => 'phone',
    'contact_email' => 'email',
    'scope' => fn ($query) => $query->where('is_active', true),   // optional
],
```

**Single-tenant** — one install per business. Leave `model` as `null` and set:

```dotenv
RETENTION_EXTERNAL_ID=acme-hardware
RETENTION_CLIENT_NAME="Acme Hardware Ltd"
```

Every `via` key is then ignored, since there is nothing to scope by.

### What counts as activity

Each metric is one aggregate over one table, restricted to the last seven days:

```php
'metrics' => [
    'login_count_7d'  => ['table' => 'sessions', 'count' => '*', 'via' => 'business_id', 'date' => 'created_at'],
    'transactions_7d' => ['table' => 'sales', 'count' => '*', 'via' => 'business_id', 'date' => 'created_at'],
    'transaction_value_7d' => ['table' => 'sales', 'sum' => 'total', 'via' => 'business_id', 'date' => 'created_at'],
],
```

When a table has no direct link to the tenant, describe the hop:

```php
'items_sold_7d' => [
    'table' => 'sale_items',
    'sum' => 'quantity',
    'via' => ['sale_id' => ['sales', 'id', 'business_id']],
    'date' => 'created_at',
],
```

### When they were last active

```php
'last_activity' => ['table' => 'sales', 'via' => 'business_id', 'date' => 'created_at'],
```

The newest row here decides how dormant a client looks, which is the strongest
churn signal in the system. Point it at the table that best represents genuine
use of your product.

A client with no rows at all is reported as long dormant rather than skipped —
never reporting them would hide exactly the clients most at risk.

### Subscriptions (optional)

```php
'subscription' => [
    'table' => 'subscriptions',
    'via' => 'business_id',
    'start' => 'starts_at',
    'end' => 'ends_at',
    'status' => 'status',
    'status_map' => ['paid' => 'active', 'lapsed' => 'expired', 'void' => 'cancelled'],
],
```

Leave it `null` and no subscription data is pushed.

### Counting logins, wherever your product keeps them

Almost no two products record a login in the same place, so three keys exist
for it. Laravel's own `sessions` table needs all three:

```php
'login_count_7d' => [
    'table' => 'sessions',
    'distinct' => 'user_id',                                  // people, not rows
    'via' => ['user_id' => ['users', 'id', 'business_id']],   // no tenant column of its own
    'date' => 'last_activity',
    'date_format' => 'timestamp',                             // a unix integer, not a datetime
],
```

An audit trail holding every kind of event needs `where` to say which one is a
login:

```php
'login_count_7d' => [
    'table' => 'audit_trail',
    'distinct' => 'user_id',
    'via' => 'business_id',
    'date' => 'created_at',
    'where' => ['action' => ['login', 'signed_in']],
],
```

`date_format` matters more than it looks. Without it a unix column is compared
against a datetime string, which MySQL casts to 0 — every row matches and the
metric reports the whole table while looking entirely reasonable.

**If your product records nothing that means "somebody used this", leave the
metric out.** Retention Intel asks only for what it scores you on.

### Metrics Retention Intel scores

A product is asked for exactly what it is scored on — its `targets` block in
Retention Intel's `config/health_score.php`, and nothing else. Adding a new
product to the system is adding that one block.

| Product | Metrics |
| --- | --- |
| POScream, POSCafe | `login_count_7d`, `items_sold_7d`, `transactions_7d`, `transaction_value_7d` |
| Clinic Plus | `visits_7d`, `lab_requests_7d`, `prescriptions_7d`, `new_patients_7d` |
| Mfuko | `login_count_7d`, `member_registrations_7d`, `loan_disbursements_7d`, `transactions_7d`, `transaction_value_7d` |
| School Monitor | `login_count_7d`, `academic_entries_7d`, `attendance_records_7d`, `fee_payments_7d` |

Clinic Plus is the worked example of a product that cannot report a component:
it records logins nowhere, so it declares no login target, is never asked for
one, and is scored across what a clinic can actually be asked about instead of
being capped at four fifths of the score for ever.

Anything else you send is kept in `raw_payload` but not scored.

## Commands

| Command | |
| --- | --- |
| `retention:install` | Guided setup; writes the config |
| `retention:push` | Push every client |
| `retention:push --dry-run` | Print the payloads, send nothing |
| `retention:push --client=ID` | Push one client, for testing |

## Notes

Both endpoints are idempotent, so re-sending a day's snapshot replaces it rather
than duplicating — a retry after a timeout is safe.

One client failing does not stop the others; the failure is logged and the run
continues.

Set `RETENTION_ENABLED=false` to keep the package installed but silent.

## Testing

```bash
composer install
vendor/bin/pest
```

To run the integration test against a live instance:

```bash
RETENTION_TEST_URL=http://localhost:8000 \
RETENTION_TEST_KEY=your-key \
vendor/bin/pest --group=integration
```

## License

MIT.
