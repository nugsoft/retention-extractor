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
`config/retention-extractor.php`. It covers both directions — what your product
sends, and how Retention Intel switches a client off here — and it ends by
saying whether licence sync came out on or off, so a half-finished setup says so
rather than looking finished.

If Retention Intel already knows where your product keeps its subscriptions and
its licence, the wizard offers to take both from there and asks you neither.
See [Where the mapping lives](#where-the-mapping-lives).

Then add to `.env`:

```dotenv
RETENTION_API_URL=https://retention.nugsoft.com
RETENTION_API_KEY=          # issued by the CTO, one per product
RETENTION_PRODUCT_CODE=poscream
RETENTION_LICENCE_SECRET=   # only if Retention Intel switches clients off here
```

Preview what would be sent, without sending it:

```bash
php artisan retention:push --dry-run
```

Then check the whole thing:

```bash
php artisan retention:status
```

That reports what is actually wired — whether your key is recognised and as
which product, where the mappings come from, how a client is switched off here,
and when a licence was last applied. Every line is a fact or a stated absence.

When it reads right, you're done — the daily push is scheduled automatically at
02:00 and the licence pull hourly. Make sure your scheduler cron is running, or
neither happens.

## It cannot guess, and does not try

There is no reliable way for a package to know that your `sale_items.quantity`
means "items sold". It could guess from table names, but a wrong guess would
push confident, wrong numbers — clients would appear on or vanish from the
retention watchlist for reasons nobody could see.

So the config file is the contract. `retention:install` fills it in with its
best reading of your schema to save you typing, and then it is on you to check
what it wrote. Anything it cannot find, it leaves blank and the push refuses to
run until you fill it in.

## Where the mapping lives

Two answers, and it is a decision rather than a fallback.

**`local`** — the default, and what everything below describes. The mapping
sits in your `config/retention-extractor.php`, changes go through your review,
and nothing outside your repository can move it. Right wherever your team owns
this integration.

**`remote`** — the subscription and licence mappings come from Retention Intel
instead:

```dotenv
RETENTION_MAPPING_SOURCE=remote
```

Leave those two blocks empty locally and Retention Intel answers for them. A
column that moves later is then a change there, with no release of this package
and no deployment of yours.

That exists because for some products the first option is not available at any
price — a team with its own roadmap, an install nobody can deploy to. The
package goes in once and everything after has to be answerable from the other
side.

Three things keep it honest:

- **It is opted into and never inferred.** A product silently taking
  instructions about which table to write, from the network, is not a default
  anybody should get by accident.
- **Secrets never travel.** `RETENTION_LICENCE_SECRET` and your licence route
  stay in your environment and are merged over the answer, so it can say where
  things are without being able to say who may change them.
- **A failure is never a guess.** The last good answer is kept and used; with
  none at all nothing reports as mapped and the licence endpoint answers `503`,
  which is retried — rather than writing against a table it is no longer sure
  about.

`retention:sync-licence` refreshes the cached mapping, so a correction made
centrally is live on the next pull.

Everything else — who your clients are, their branches, the metric tables and
last activity — is always read from your own config file, whichever source you
choose.

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

The row reported is **whichever ends last**, which makes two keys worth knowing
about wherever a product keeps more than one.

`via` is a column on the subscription table, or the same two-step path the
metrics use where the table only knows something beneath the client. School
Monitor bills per branch, and nothing on a subscription row names a school:

```php
'via' => ['school_branch_id' => ['school_branches', 'id', 'school_id']],
```

`where` drops rows your product would not read itself. **This matters more than
it looks.** These queries do not go through your models, so a soft-deleted row
is still a candidate — and since the row reported is the one ending last, a
deleted future term is reported as the current one:

```php
'where' => ['deleted_at' => null],
```

Four Clinic Plus facilities were pushing a term from a deleted row before this
was found. Nothing errored; the dates were simply two days wrong.

### Licences (optional)

Everything above pushes data *out*. This is the one thing that comes back:
Retention Intel is the master for whether a client may work, and it tells this
product when somebody is switched on or off.

Fill it in and two things happen — a signed webhook the moment a licence
changes, and an hourly `retention:sync-licence` that asks for the current state
anyway, so a message nobody received does not leave a suspended client working.

Say where licence state lives, how to reach the client it belongs to, and how
**this** product expresses "may they work". That last part is the whole reason
this is configuration and not code: no two products say it the same way.

**A word in a column** — Clinic Plus keeps it on the facility:

```php
'licence' => [
    'table'  => 'facilities',
    'via'    => 'id',
    'status' => ['column' => 'status', 'granted' => 'Active', 'revoked' => 'Suspend'],
],
```

**A date that caps access** — School Monitor has no status at all; access is
derived from dates, and `license_expires_at` is an administrative ceiling on
them. The rows are per branch, reached through the branch table:

```php
'licence' => [
    'table'   => 'branch_subscriptions',
    'via'     => ['school_branch_id' => ['school_branches', 'id', 'school_id']],
    'ceiling' => ['column' => 'license_expires_at'],
],
```

The ceiling is set to yesterday to revoke and **cleared** to grant, so your own
paid term governs again. That is deliberate: a mistake here can only ever
shorten access, never hand somebody time they have not paid for.

A licence covers the whole client, so where the rows are per branch every
branch of that client is written. A client is either on or off, never half.

Where that table soft-deletes, say so:

```php
'where' => ['deleted_at' => null],
```

Without it this package caps rows your product never reads, then reads them back
and reports a restriction nobody is enforcing — which shows up in Retention
Intel as a disagreement that no amount of re-sending will clear.

Set `RETENTION_LICENCE_SECRET` to the value issued with your API key. Without
it nothing is received — an endpoint that switches clients off must not take
anybody's word for who is calling.

Leave `table` null and no route is mounted at all, unless
`RETENTION_MAPPING_SOURCE=remote`, in which case naming that source is the
declaration of intent: the route is mounted and answers `503` until a mapping
arrives, because a `503` is retried and a `404` is final.

The package ships a migration for `retention_licences`, which records the last
version applied here. That is what lets a webhook arriving after a newer one be
dropped rather than applied.

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
| `retention:status` | Report what is actually wired up |
| `retention:push` | Push every client |
| `retention:push --dry-run` | Print the payloads, send nothing |
| `retention:push --client=ID` | Push one client, for testing |
| `retention:sync-licence` | Fetch current licences and apply them |
| `retention:sync-licence --dry-run` | Report what would change, write nothing |
| `retention:sync-licence --client=ID` | Sync one client |

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
