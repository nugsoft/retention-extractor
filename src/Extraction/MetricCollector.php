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
     * @return array<string, int|float>
     */
    public function collect(ClientRecord $client): array
    {
        $values = [];

        foreach ($this->metrics as $name => $mapping) {
            $values[$name] = $this->aggregate($name, $mapping, $client);
        }

        return $values;
    }

    /**
     * The most recent activity date for this client, or null when nothing has
     * ever been recorded.
     *
     * @param  array<string, mixed>  $mapping
     */
    public function lastActivityDate(array $mapping, ClientRecord $client): ?string
    {
        $this->assertTable($mapping['table'] ?? null, 'last_activity.table');

        $dateColumn = $mapping['date'] ?? 'created_at';

        $query = $this->scoped(DB::table($mapping['table']), $mapping, $client, 'last_activity');

        $this->applyFilters($query, $mapping);

        $value = $query->max($dateColumn);

        if (blank($value)) {
            return null;
        }

        // A unix column read as a string would give '1756...' — a date somewhere
        // in the year 1756, which is not obviously wrong enough to be noticed.
        if (($mapping['date_format'] ?? null) === 'timestamp') {
            return Carbon::createFromTimestamp((int) $value)->toDateString();
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function aggregate(string $name, array $mapping, ClientRecord $client): int|float
    {
        $this->assertTable($mapping['table'] ?? null, "metrics.{$name}.table");

        $query = $this->scoped(DB::table($mapping['table']), $mapping, $client, "metrics.{$name}");

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
    private function scoped(Builder $query, array $mapping, ClientRecord $client, string $configKey): Builder
    {
        if (! $client->isScoped()) {
            return $query;
        }

        $via = $mapping['via'] ?? null;

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

        // ['sale_id' => ['sales', 'id', 'business_id']]
        foreach ($via as $localKey => [$table, $foreignKey, $tenantColumn]) {
            $query->whereIn($localKey, fn (Builder $sub) => $sub
                ->select($foreignKey)
                ->from($table)
                ->where($tenantColumn, $client->key));
        }

        return $query;
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
