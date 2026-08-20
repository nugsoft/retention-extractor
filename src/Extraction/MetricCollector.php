<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Extraction;

use Illuminate\Database\Query\Builder;
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

        $value = $this->scoped(DB::table($mapping['table']), $mapping, $client)
            ->max($dateColumn);

        if (blank($value)) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function aggregate(string $name, array $mapping, ClientRecord $client): int|float
    {
        $this->assertTable($mapping['table'] ?? null, "metrics.{$name}.table");

        $query = $this->scoped(DB::table($mapping['table']), $mapping, $client);

        $dateColumn = $mapping['date'] ?? null;

        if ($dateColumn !== null) {
            $query->where($dateColumn, '>=', now()->subDays($this->windowDays)->startOfDay());
        }

        if (isset($mapping['sum'])) {
            return round((float) $query->sum($mapping['sum']), 2);
        }

        return (int) $query->count();
    }

    /**
     * Restricts an aggregate to one client.
     *
     * A single-tenant install has nothing to scope by — every row in the
     * product's database belongs to the one client — so the query is left
     * whole. A `via` given as an array describes a hop through an intermediate
     * table for rows that carry no direct tenant column.
     *
     * @param  array<string, mixed>  $mapping
     */
    private function scoped(Builder $query, array $mapping, ClientRecord $client): Builder
    {
        if (! $client->isScoped()) {
            return $query;
        }

        $via = $mapping['via'] ?? null;

        if (blank($via)) {
            return $query;
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
