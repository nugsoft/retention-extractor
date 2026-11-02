<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Extraction;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;

/**
 * Runs the aggregate described by each metric mapping.
 *
 * Every query here is read-only and bounded to the configured window. Nothing
 * in this package ever writes to the product's database.
 */
class MetricCollector
{
    /**
     * @param  array<string, array<string, mixed>>  $metrics
     */
    public function __construct(
        private readonly array $metrics,
        private readonly int $windowDays,
    ) {}

    /**
     * Whether each table records a branch, asked once per table per run.
     *
     * @var array<string, bool>
     */
    private array $recordsBranch = [];

    /**
     * @return array<string, int|float>
     */
    public function collect(ClientRecord $client, ?BranchRecord $branch = null): array
    {
        $values = [];

        foreach ($this->metrics as $name => $mapping) {
            // A branch's share can only be read from a table that says which
            // branch a row belongs to. Elsewhere the metric is left out of the
            // branch rather than refused: MySQL rejects the unknown column and
            // the whole snapshot goes with it, while SQLite quietly reads the
            // name as a string and reports nothing. The client's own figure
            // still carries it.
            if ($branch !== null && ! $this->recordsBranch((string) ($mapping['table'] ?? ''))) {
                continue;
            }

            $values[$name] = $this->aggregate($name, $mapping, $client, $branch);
        }

        return $values;
    }

    /**
     * The most recent activity date for this client, or null when nothing has
     * ever been recorded.
     *
     * @param  array<string, mixed>  $mapping
     */
    public function lastActivityDate(array $mapping, ClientRecord $client, ?BranchRecord $branch = null): ?string
    {
        $this->assertTable($mapping['table'] ?? null, 'last_activity.table');

        $dateColumn = $mapping['date'] ?? 'created_at';

        $query = $this->scoped(DB::table($mapping['table']), $mapping, $client, 'last_activity', $branch);

        $this->applyFilters($query, $mapping);

        $value = $query->max($dateColumn);

        if (blank($value)) {
            return null;
        }

        // A unix column read as a string would give '1756...' — a date somewhere
        // in the year 1756, which is not obviously wrong enough to be noticed.
        // A timestamp is an instant, so its UTC day is the only one to name.
        if (($mapping['date_format'] ?? null) === 'timestamp') {
            return self::noLaterThanToday(Carbon::createFromTimestamp((int) $value, 'UTC')->toDateString());
        }

        $stored = (string) $value;

        // A bare date names a day and carries no time to convert. Read as
        // midnight in the product's timezone it would come out a day early.
        if (strlen($stored) === 10) {
            return self::noLaterThanToday($stored);
        }

        return self::noLaterThanToday(
            Carbon::parse($stored, self::databaseTimezone())->utc()->toDateString(),
        );
    }

    /**
     * The day NugsoftOS will accept, which is UTC's.
     *
     * NugsoftOS refuses a last_activity_date after its own today, and its day
     * is UTC's. A product on Kampala time pushes at 02:00 local — 23:00 UTC
     * the day before — so the local date of anything sold after midnight is
     * "tomorrow" there, and the whole snapshot was refused with it. Converting
     * covers that; capping covers the row dated in the future, a booked
     * appointment in the table that measures activity, which would otherwise
     * cost the client a night's figures for a fact about next week.
     */
    private static function noLaterThanToday(string $date): string
    {
        $today = Carbon::now('UTC')->toDateString();

        return $date > $today ? $today : $date;
    }

    /**
     * The timezone the product's database writes its datetimes in.
     *
     * The application's own unless configured otherwise. Eloquent writes
     * timestamps in `app.timezone`, which is right for nearly every product;
     * one whose rows are written in UTC while its app runs on local time says
     * so, rather than having every date shifted a second time.
     */
    private static function databaseTimezone(): string
    {
        $timezone = config('retention-extractor.timezone') ?: config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function aggregate(string $name, array $mapping, ClientRecord $client, ?BranchRecord $branch = null): int|float
    {
        $this->assertTable($mapping['table'] ?? null, "metrics.{$name}.table");

        $query = $this->scoped(DB::table($mapping['table']), $mapping, $client, "metrics.{$name}", $branch);

        $this->restrictToWindow($query, $mapping);
        $this->applyFilters($query, $mapping);

        if (isset($mapping['sum'])) {
            return round((float) $query->sum($mapping['sum']), 2);
        }

        // `distinct` counts the things behind the rows rather than the rows —
        // how many people were active, not how many records they left. That is
        // usually what a product means by a login: three sessions from one
        // nurse is one nurse.
        if (isset($mapping['distinct'])) {
            return (int) $query->distinct()->count($mapping['distinct']);
        }

        return (int) $query->count();
    }

    /**
     * Narrows the aggregate to the reporting window.
     *
     * `date_format: 'timestamp'` is for a column holding a unix integer rather
     * than a datetime — Laravel's own `sessions.last_activity` is one, and it is
     * exactly the table a product reaches for when asked to count logins.
     * Binding a datetime against an integer column does not fail: MySQL casts
     * the string to 0 and every row matches, so the metric silently reports the
     * whole table. That is the class of bug this package exists not to have.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function restrictToWindow(Builder $query, array $mapping): void
    {
        $dateColumn = $mapping['date'] ?? null;

        if ($dateColumn === null) {
            return;
        }

        $since = now()->subDays($this->windowDays)->startOfDay();

        $query->where(
            $dateColumn,
            '>=',
            ($mapping['date_format'] ?? null) === 'timestamp' ? $since->getTimestamp() : $since,
        );
    }

    /**
     * Extra equality conditions on the rows counted.
     *
     * An audit trail is one table holding every kind of event, so counting
     * logins out of it means naming the one that is a login:
     * `'where' => ['action' => 'login']`.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function applyFilters(Builder $query, array $mapping): void
    {
        foreach ((array) ($mapping['where'] ?? []) as $column => $value) {
            is_array($value)
                ? $query->whereIn($column, $value)
                : $query->where($column, $value);
        }
    }

    /**
     * Restricts an aggregate to one client.
     *
     * A single-tenant install has nothing to scope by — every row in the
     * product's database belongs to the one client — so the query is left
     * whole. A `via` given as an array describes a hop through an intermediate
     * table for rows that carry no direct tenant column.
     *
     * A multi-tenant install with no `via` is refused rather than left whole.
     * Returning the unscoped query there reported the whole table to every
     * client — two shops with one and three sales between them were each told
     * they had four — and it did so silently, which is the one thing this
     * package sets out not to do. `retention:install` writes exactly that
     * mapping whenever it cannot guess the tenant column: the null it produces
     * is filtered out and the key simply goes missing.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function scoped(
        Builder $query,
        array $mapping,
        ClientRecord $client,
        string $configKey,
        ?BranchRecord $branch = null,
    ): Builder {
        if (! $client->isScoped()) {
            return $query;
        }

        $branchKey = $this->branchKey();

        // One branch's share. Naming the branch is narrower than naming the
        // client, so nothing else needs adding: a row belonging to this branch
        // belongs to this client by construction.
        $via = $mapping['via'] ?? null;

        if ($branch !== null && $branchKey !== null) {
            return $this->throughHops($query->where($branchKey, $branch->key), $via, null);
        }

        // A table that only records which BRANCH a row belongs to, which is the
        // ordinary case where a product is branch-first: 103 of School
        // Monitor's tables carry `school_branch_id` and 15 carry `school_id`.
        // The client's total is every one of its branches, and asking for a
        // client column that is not there would refuse a mapping that is right.
        if (blank($via) && $branchKey !== null && $client->hasBranches()) {
            return $query->whereIn($branchKey, $client->branchKeys());
        }

        if (blank($via)) {
            throw ConfigurationException::missing(
                "{$configKey}.via",
                'This install serves more than one client, so the mapping must name the column linking '
                ."a row back to its client. Without it, '{$mapping['table']}' is counted whole for everybody.",
            );
        }

        if (is_string($via)) {
            return $query->where($via, $client->key);
        }

        return $this->throughHops($query, $via, $client);
    }

    /**
     * Follows each hop to the table that knows the client.
     *
     * `['sale_id' => ['sales', 'id', 'business_id']]` — a sale item reaches its
     * business through the sale. An optional fourth element narrows the rows
     * of that middle table, which is where a product keeps the facts about who
     * did something: `['user_id' => ['users', 'id', 'facility_id',
     * ['is_support' => false]]]` counts a facility's own staff and not the
     * support desk signing in to help them.
     *
     * Without a client — reading one branch, which is already narrower — only
     * the filtered hops are followed, since an unfiltered one would add
     * nothing but a subquery.
     *
     * @param  mixed  $via
     */
    private function throughHops(Builder $query, $via, ?ClientRecord $client): Builder
    {
        if (! is_array($via)) {
            return $query;
        }

        foreach ($via as $localKey => $hop) {
            [$table, $foreignKey, $tenantColumn] = $hop;
            $filters = (array) ($hop[3] ?? []);

            if ($client === null && $filters === []) {
                continue;
            }

            $query->whereIn($localKey, function (Builder $sub) use ($table, $foreignKey, $tenantColumn, $filters, $client): void {
                $sub->select($foreignKey)->from($table);

                if ($client !== null) {
                    $sub->where($tenantColumn, $client->key);
                }

                $this->applyFilters($sub, ['where' => $filters]);
            });
        }

        return $query;
    }

    private function recordsBranch(string $table): bool
    {
        $branchKey = $this->branchKey();

        if ($branchKey === null || $table === '') {
            return true;
        }

        return $this->recordsBranch[$table] ??= Schema::hasColumn($table, $branchKey);
    }

    /**
     * The column on an activity table naming which branch a row belongs to, or
     * null where the product does not record branches.
     */
    private function branchKey(): ?string
    {
        $key = config('retention-extractor.clients.branches.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function assertTable(?string $table, string $configKey): void
    {
        if (blank($table)) {
            throw ConfigurationException::missing($configKey, 'Name the table to read from.');
        }

        if (! Schema::hasTable($table)) {
            throw ConfigurationException::unknownTable($table, $configKey);
        }
    }
}
