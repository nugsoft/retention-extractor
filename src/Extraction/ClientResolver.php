<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Extraction;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;

/**
 * Produces the clients to report on, whichever way the product is deployed.
 *
 * Multi-tenant installs list a tenant model and get one record per row;
 * single-tenant installs leave it null and get exactly one record built from
 * configuration. Callers never need to know which.
 */
class ClientResolver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function isMultiTenant(): bool
    {
        return filled($this->config['model'] ?? null);
    }

    /**
     * Streamed in chunks so a product with thousands of tenants never loads
     * them all at once.
     *
     * @return Generator<int, ClientRecord>
     */
    public function all(int $chunkSize = 200): Generator
    {
        if (! $this->isMultiTenant()) {
            yield $this->single();

            return;
        }

        foreach ($this->query()->lazyById($chunkSize) as $model) {
            yield $this->fromModel($model);
        }
    }

    public function count(): int
    {
        return $this->isMultiTenant() ? $this->query()->count() : 1;
    }

    /**
     * Clients the product has ended for good, with the day it did.
     *
     * `ended` names a column on the client's own row that, once set, means
     * the client is gone: School Monitor soft-deletes a closed branch, and
     * nothing restores one. Such a client is left out of `all()` — a model
     * that soft-deletes leaves it out by itself — so it simply stopped being
     * reported, and NugsoftOS went on granting a licence nobody could use and
     * scoring a client that no longer existed as going dormant. Listed here
     * instead, so its term can be reported once more, as ended.
     *
     * Soft deletes are lifted for this query alone; the configured `scope`
     * still applies.
     *
     * @return Generator<int, array{client: ClientRecord, endedOn: string}>
     */
    public function ended(int $chunkSize = 200): Generator
    {
        $column = $this->config['ended'] ?? null;

        if (! $this->isMultiTenant() || blank($column)) {
            return;
        }

        $query = $this->query()->withoutGlobalScope(SoftDeletingScope::class);

        $query->whereNotNull($query->getModel()->qualifyColumn((string) $column));

        foreach ($query->lazyById($chunkSize) as $model) {
            yield [
                'client' => $this->fromModel($model),
                'endedOn' => substr((string) $model->getRawOriginal((string) $column), 0, 10),
            ];
        }
    }

    /**
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        /** @var class-string<Model> $class */
        $class = $this->config['model'];

        if (! class_exists($class)) {
            throw ConfigurationException::missing(
                'clients.model',
                "Class {$class} does not exist.",
            );
        }

        $query = $class::query();

        $scope = $this->config['scope'] ?? null;

        if (is_callable($scope)) {
            $scope($query);
        }

        return $query;
    }

    private function fromModel(Model $model): ClientRecord
    {
        $externalId = $this->attribute($model, $this->config['external_id'] ?? 'id');

        if (blank($externalId)) {
            $column = $this->config['external_id'] ?? 'id';
            $class = $model::class;

            throw ConfigurationException::missing(
                'clients.external_id',
                "Column '{$column}' is empty on {$class} #{$model->getKey()}. "
                .'Every client needs a stable identifier.',
            );
        }

        return new ClientRecord(
            externalId: (string) $externalId,
            name: (string) ($this->attribute($model, $this->config['name'] ?? 'name') ?? $externalId),
            contactPhone: $this->optionalAttribute($model, $this->config['contact_phone'] ?? null),
            contactEmail: $this->optionalAttribute($model, $this->config['contact_email'] ?? null),
            key: $model->getKey(),
            branches: $this->branchesOf($model),
        );
    }

    /**
     * Where this client's work happens.
     *
     * Empty unless the product records branches and the mapping says how to
     * find them — which is the ordinary case for a product with one location,
     * and for a product that draws no such distinction.
     *
     * Read as a plain query rather than through a relationship, because the
     * mapping names a table and two columns; requiring the product to have
     * declared an Eloquent relationship would be requiring it to have
     * anticipated this.
     *
     * @return array<int, BranchRecord>
     */
    private function branchesOf(Model $model): array
    {
        $branches = $this->config['branches'] ?? null;

        if (! is_array($branches) || blank($branches['table'] ?? null)) {
            return [];
        }

        $externalId = $branches['external_id'] ?? 'id';
        $name = $branches['name'] ?? 'name';

        $rows = DB::table($branches['table'])
            ->where($branches['via'], $model->getKey())
            ->orderBy($name)
            ->get();

        return $rows
            ->map(fn (object $row): BranchRecord => new BranchRecord(
                externalId: (string) $row->{$externalId},
                name: (string) ($row->{$name} ?? $row->{$externalId}),
                key: $row->{$branches['local_key'] ?? 'id'},
                address: $this->column($row, $branches['address'] ?? null),
                contactPhone: $this->column($row, $branches['contact_phone'] ?? null),
                contactEmail: $this->column($row, $branches['contact_email'] ?? null),
            ))
            ->all();
    }

    /**
     * One optional column off a branch row.
     *
     * Unmapped and missing are the same answer here — a product that does not
     * record an address, and one whose mapping does not mention it, both have
     * nothing to send.
     */
    private function column(object $row, ?string $column): ?string
    {
        if ($column === null || ! property_exists($row, $column)) {
            return null;
        }

        return blank($row->{$column}) ? null : (string) $row->{$column};
    }

    private function single(): ClientRecord
    {
        $single = $this->config['single'] ?? [];

        if (blank($single['external_id'] ?? null)) {
            throw ConfigurationException::missing(
                'clients.single.external_id',
                'Set RETENTION_EXTERNAL_ID in .env, or point clients.model at your tenant model.',
            );
        }

        return new ClientRecord(
            externalId: (string) $single['external_id'],
            name: (string) ($single['name'] ?? $single['external_id']),
            contactPhone: $single['contact_phone'] ?? null,
            contactEmail: $single['contact_email'] ?? null,
            key: null,
        );
    }

    private function attribute(Model $model, string $column): mixed
    {
        return $model->getAttribute($column);
    }

    private function optionalAttribute(Model $model, ?string $column): ?string
    {
        if ($column === null) {
            return null;
        }

        $value = $model->getAttribute($column);

        return blank($value) ? null : (string) $value;
    }
}
