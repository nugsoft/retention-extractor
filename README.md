# Retention Extractor

Pushes client activity and subscription data from a Laravel product into
[NugsoftOS](https://retention.nugsoft.com), so churn risk is spotted
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
sends, and how NugsoftOS switches a client off here — and it ends by
saying whether licence sync came out on or off, so a half-finished setup says so
rather than looking finished.

If NugsoftOS already knows where your product keeps its subscriptions and
its licence, the wizard offers to take both from there and asks you neither.
See [Where the mapping lives](#where-the-mapping-lives).

Then add to `.env`:

```dotenv
RETENTION_API_URL=https://retention.nugsoft.com
RETENTION_API_KEY=          # issued by the CTO, one per product
RETENTION_PRODUCT_CODE=poscream
RETENTION_LICENCE_SECRET=   # only if NugsoftOS switches clients off here
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

Once you have corrected that file, running the installer again will not quietly
undo it. It lists every setting it would change, as `old → new`, and asks before
replacing anything. `--force` skips the question but still prints the list. If
nothing would change, it writes nothing.

## Where the mapping lives

Two answers, and it is a decision rather than a fallback.

**`local`** — the default, and what everything below describes. The mapping
sits in your `config/retention-extractor.php`, changes go through your review,
and nothing outside your repository can move it. Right wherever your team owns
this integration.

**`remote`** — the subscription and licence mappings come from NugsoftOS
instead:

```dotenv
RETENTION_MAPPING_SOURCE=remote
```

Leave those two blocks empty locally and NugsoftOS answers for them. A
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

The date is sent as a **UTC** day, because that is how NugsoftOS counts them
and it refuses a date after its own today. Your rows are read in `app.timezone`,
which is what Eloquent writes them in; if your database stores UTC while your
app runs on local time, say so:

```dotenv
RETENTION_TIMEZONE=UTC
```

Before this, a product on Kampala time pushing at 02:00 sent "tomorrow" for any
client active after midnight, and that client's whole snapshot was refused.
Nothing later than today is ever sent, so a row dated in the future — a booked
appointment, say — reads as today rather than costing the client the night.

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

#### Licensing each branch on its own

Where every branch has its own licence row and end date, as in PosCream, name
the column holding the branch's key:

```php
'subscription' => [
    'table' => 'licences',
    'via' => 'business_id',
    'branch' => 'branch_id',
    'start' => 'start_date',
    'end' => 'end_date',
],
```

Each branch is then pushed with its own term and a `branch_external_id`, and
NugsoftOS keeps one licence per branch. The licence it sends back names the
branch too (`LicenceState::$branchExternalId`), so the `licence` mapping must
be a `driver` that can switch one branch: the table strategies would switch
every branch of the client at once, and refuse a branch licence instead.
A branch switched off with time left on its term is reported as `suspended`;
one past its term is `expired`, whoever switched it off.

The row reported is **whichever ends last**, which makes two keys worth knowing
about wherever a product keeps more than one.

`via` is a column on the subscription table, or the same two-step path the
metrics use where the table only knows something beneath the client. School
Monitor needs only the column: each branch is a client there, and a
subscription row names its branch:

```php
'via' => 'school_branch_id',
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

`where` filters the subscription table only. Where the branch table also
soft-deletes, give the path the same fourth element the metrics take, and a
closed branch's term is left out too. The licence path accepts it as well:

```php
'via' => ['branch_id' => ['branches', 'id', 'client_id', ['deleted_at' => null]]],
```

#### A row per cycle

Some products add a row for every cycle rather than editing one row per term:
School Monitor renews by inserting rows, keeps every cycle it ever sold, and
marks a terminated cycle with `revoked_at`. "Whichever ends last" is then wrong
— a renewal bought in advance ends later than the cycle running today. Four
optional keys describe such a product:

```php
'via'       => 'school_branch_id',    // each branch is a client
'pick'      => 'current',             // started and ends last, else the next to begin
'grace'     => 'grace_ends_at',       // free access after the end
'deadline'  => 'license_expires_at',  // payment deadline, caps end and grace
'cancelled' => 'revoked_at',          // set once the term was terminated
'where'     => ['deleted_at' => null, 'revoked_at' => null],
```

Under `pick => current`, a cycle reported that has not begun is `future`: the
client holds a term but may not work until it starts, which School Monitor
calls scheduled. It is `suspended` instead where the product suspended it
itself (a `blocks` column on the licence mapping). `future` needs a NugsoftOS
that accepts it.

With `grace` or `deadline` named, the push says when access really ends and
why, alongside the paid term it always sent:

| Field | |
|---|---|
| `end_date` | the paid term, unchanged |
| `grace_ends_at` | the grace column, or null |
| `payment_deadline` | the deadline column, or null |
| `effective_end_date` | the earliest of the cancellation and the deadline, otherwise the later of end and grace |
| `ends_because` | `term`, `grace`, `payment_deadline` or `cancelled` |

A payment deadline falls within the paid term, between its start and its end,
and only ever shortens access. One that has passed while the paid term still
runs is reported as `suspended`, not `expired`: the time was bought and is being
withheld for want of payment, which School Monitor calls overdue. Once the paid
term is over the client is `expired`, deadline or not: grace is not owed to a
client who missed the deadline.

On a cycle yet to begin, a date before its start is no deadline at all — it is
NugsoftOS's revoke, which caps waiting cycles as yesterday — so it is left out:
`payment_deadline` is null and access ends with the term. Once the cycle has
begun the product enforces whatever caps it, and it counts again.

With `grace` or `deadline` named, the dates decide the status (`future`,
`active`, `grace`, `suspended` or `expired`) and your status column no longer does, with
one exception: where it says `cancelled`, directly or through `status_map`,
that is reported. No date can tell a cancellation.

`cancelled` names a column that, once set, means the term was terminated, and
such a row is reported as `cancelled`, but only where nothing newer stands:

- A cycle nobody cancelled that is still running, or yet to begin, is reported
  first. An old cycle terminated long ago does not hide the one paid for today.
- Otherwise whichever happened last is the client's state: the latest
  cancellation, if it came on or after the live cycle ran out (grace
  included), or else that cycle, as `expired`.

A cancelled cycle's access ends on the day it was cancelled, where that comes
first: `effective_end_date` is that day and `ends_because` is `cancelled`.

Naming the column also lifts it out of `where`. Keep it filtered there anyway
wherever the mapping reaches an install on an older extractor, as NugsoftOS's
served mapping does: those go on dropping terminated rows, rather than
reporting one as the live term.

### Licences (optional)

Everything above pushes data *out*. This is the one thing that comes back:
NugsoftOS is the master for whether a client may work, and it tells this
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
derived from dates, and `license_expires_at`, its payment deadline, caps them.
Each branch is a client there, and its cycles are rows naming it:

```php
'licence' => [
    'table'   => 'branch_subscriptions',
    'via'     => 'school_branch_id',
    'ceiling' => ['column' => 'license_expires_at'],
],
```

The ceiling is set to yesterday to revoke and **cleared** to grant, so your own
paid term governs again. That is deliberate: nothing here can give access
beyond the paid term and its grace.

**A grant clears the deadline whoever set it, and that is intended.** Finance
makes School Monitor's subscriptions and licences in NugsoftOS, which is the
master for whether a branch may work. The hourly pull re-applies every granted
licence, not only those that changed, so a deadline set in School Monitor's
manager app on a branch NugsoftOS grants lasts until the next pull. To keep a
branch out, switch it off in NugsoftOS. A suspension made in School Monitor
(`suspended_at`) is read through `blocks` and never written, so NugsoftOS sees
it as the product's own.

**Every write is announced.** The rows change through the query builder, not
your models, so nothing your models do on save runs for them — including
forgetting a cache of what those rows say. Listen for
`Nugsoft\RetentionExtractor\Events\LicenceApplied`, which carries the
`LicenceState` written and how many rows took it. School Monitor caches each
branch's access for an hour, so it forgets that branch's keys on this event:

```php
Event::listen(LicenceApplied::class, function (LicenceApplied $event): void {
    // The client is the branch there, so its external id is the branch id.
    BranchSubscription::forgetCachesForBranch((int) $event->licence->externalId);
});
```

A licence covers the whole client, so where the rows are per branch every
branch of that client is written. A client is either on or off, never half.

**A class of your own**: for licence state that no single column can express.
PosCream keeps access on every branch *and* on the business, an expired branch
has to stay off whatever it is told, and an open till stays signed in until its
token is deleted. That knowledge belongs in PosCream, not in this package:

```php
'licence' => [
    'driver' => \App\Retention\PosCreamLicence::class,
],
```

The class implements `Nugsoft\RetentionExtractor\Licensing\LicenceDriver`:
`apply(LicenceState $licence): int` returns how many rows hold that client's
licence (0 means no such client here), and
`grantsAccess(string $externalId, ?string $branchExternalId = null): ?bool`
reads the current state back. It is resolved from the container. The package
still verifies signatures, drops stale deliveries, records what was applied and
reports back. A driver stays yours under `RETENTION_MAPPING_SOURCE=remote`;
NugsoftOS describes tables and can never name a class here. The installer
offers it as the last answer to "Which table holds that?"; with a remote
mapping it does not ask, so add the line by hand.

Where that table soft-deletes, say so:

```php
'where' => ['deleted_at' => null],
```

Without it this package caps rows your product never reads, then reads them back
and reports a restriction nobody is enforcing — which shows up in Retention
Intel as a disagreement that no amount of re-sending will clear.

Four more keys are read back and never written, for a product that keeps more
than one fact about access:

```php
'blocks'        => ['suspended_at'],               // set on any row: they may not work
'term_ends'     => ['end_date', 'grace_ends_at'],  // rows past these are not asked
'term_starts'   => ['start_date'],                 // rows not yet begun are not asked
'client_blocks' => [                               // set on the client's own row: they may not work
    'table' => 'school_branches', 'key' => 'id', 'columns' => ['suspended_at'],
],
```

`client_blocks` is for a switch the product keeps on the client itself rather
than on any of its terms. School Monitor's manager deactivates a branch by
stamping `school_branches.suspended_at`, and the school app then logs that
branch's users out whatever its subscription says. `blocks` cannot see it,
because it reads the licence table. With `client_blocks` named, such a client
reads back as no access, so a running term is pushed as `suspended`. The row is
found by the client's external id in `key`.

`blocks` reports a suspension the product made for itself, which reading only
the ceiling missed. `term_ends` stops a cap left on a cycle that ended last year
from holding the client at "no access" for good; where every row's term is over
the read-back answers "no idea", and the dates say expired. `term_starts` does
the same for a renewal bought in advance: a deadline or a suspension on a cycle
that has not begun does not hold back the one running today. Where nothing has
begun yet the read-back answers "no idea", and the push says `future`, unless
the next cycle to begin carries a `blocks` suspension. A ceiling on it is not
read: NugsoftOS's revoke caps waiting cycles too, and School Monitor calls such
a branch scheduled, not overdue.

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

A login table that names only the user can narrow the users it passes through
with a fourth element on the hop. Clinic Plus's support staff sign in to help a
facility, and are not that facility using the product:

```php
'login_count_7d' => [
    'table' => 'user_auth_logs',
    'distinct' => 'user_id',
    'via' => ['user_id' => ['users', 'id', 'facility_id', ['is_support' => false]]],
    'date' => 'login_at',
    'where' => ['deleted_at' => null],
],
```

Where branches are configured, a metric whose table has no branch column is
left out of each branch's figures and reported for the client only. A sign-in
belongs to a person, not a branch.

`date_format` matters more than it looks. Without it a unix column is compared
against a datetime string, which MySQL casts to 0 — every row matches and the
metric reports the whole table while looking entirely reasonable.

**If your product records nothing that means "somebody used this", leave the
metric out.** NugsoftOS asks only for what it scores you on.

### Metrics NugsoftOS scores

A product is asked for exactly what it is scored on — its `targets` block in
NugsoftOS's `config/health_score.php`, and nothing else. Adding a new
product to the system is adding that one block.

| Product | Metrics |
| --- | --- |
| POScream, POSCafe | `login_count_7d`, `items_sold_7d`, `transactions_7d`, `transaction_value_7d` |
| Clinic Plus | `login_count_7d`, `visits_7d`, `lab_requests_7d`, `prescriptions_7d`, `new_patients_7d` |
| Mfuko | `login_count_7d`, `member_registrations_7d`, `loan_disbursements_7d`, `transactions_7d`, `transaction_value_7d` |
| School Monitor | `login_count_7d`, `academic_entries_7d`, `attendance_records_7d`, `fee_payments_7d` |

A product that cannot report a component declares no target for it, is never
asked for it, and is scored across the components it can report rather than
marked down for the gap. Clinic Plus worked that way until it started counting
`user_auth_logs`.

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
