<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Licensing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;

/**
 * Writes a licence decision into this product's own tables.
 *
 * Nothing here is inferred: the config says which table holds licence state,
 * how to find the rows belonging to a client, and how this product expresses
 * "may they work". That matters because no two products express it the same
 * way — Clinic Plus keeps a status word on the facility, School Monitor has no
 * status at all and derives access from a date that acts as a ceiling.
 *
 * Two strategies, and a product uses exactly one:
 *
 *   `status`  — write a word into a column. Clinic Plus.
 *   `ceiling` — write a date that caps access, or clear it. School Monitor.
 *
 * The ceiling strategy revokes by setting the date to yesterday rather than by
 * deleting a term, and grants by clearing it so the product's own paid term
 * governs again. That is deliberate: it means a mistake here can only ever
 * shorten access, never hand somebody time they have not paid for.
 */
class LicenceApplier
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return int the number of rows changed
     *
     * @throws ConfigurationException
     */
    public function apply(LicenceState $licence): int
    {
        $this->assertConfigured();

        $table = (string) $this->config['table'];
        $ids = $this->rowIdsFor($licence->externalId);

        if ($ids === []) {
            return 0;
        }

        $changed = DB::table($table)
            ->whereIn($this->primaryKey(), $ids)
            ->update($this->valuesFor($licence));

        $this->rememberApplied($licence);

        return $changed;
    }

    /**
     * Whether this product currently lets that client work.
     *
     * Read back through the same mapping used to write, which is the point: a
     * product that reports its subscription DATES is not reporting whether
     * somebody can log in. Clinic Plus keeps the answer on the facility and the
     * dates on a different table entirely, so a suspended client was being
     * reported as active — and every suspension looked like a disagreement
     * between the two systems when they agreed perfectly.
     *
     * Null when licence state cannot be read, so a caller can tell "no" from
     * "no idea" rather than guessing on the client's behalf.
     */
    public function grantsAccess(string $externalId): ?bool
    {
        if (! $this->isConfigured() || blank($this->config['via'] ?? null)) {
            return null;
        }

        $ids = $this->rowIdsFor($externalId);

        if ($ids === []) {
            return null;
        }

        $rows = DB::table((string) $this->config['table'])
            ->whereIn($this->primaryKey(), $ids)
            ->get();

        $status = $this->config['status'] ?? null;

        if (is_array($status)) {
            $column = (string) $status['column'];

            // Every row has to grant it. A client is on or off, never half —
            // the same rule the write side follows when it fans out.
            return $rows->every(fn ($row): bool => ($row->{$column} ?? null) == $status['granted']);
        }

        /** @var array<string, mixed> $ceiling */
        $ceiling = $this->config['ceiling'];
        $column = (string) $ceiling['column'];
        $today = now()->startOfDay();

        return $rows->every(function ($row) use ($column, $today): bool {
            $capped = $row->{$column} ?? null;

            // No cap at all means nothing is holding this client back.
            return $capped === null || Carbon::parse((string) $capped)->startOfDay()->gte($today);
        });
    }

    /**
     * Whether this delivery is older than what has already been applied.
     *
     * The pull always wins, because it asks for the current state rather than
     * carrying one; only a pushed webhook can arrive out of order.
     */
    public function isStale(LicenceState $licence): bool
    {
        $applied = DB::table('retention_licences')
            ->where('external_id', $licence->externalId)
            ->value('licence_version');

        return $applied !== null && $licence->licenceVersion < (int) $applied;
    }

    public function isConfigured(): bool
    {
        return filled($this->config['table'] ?? null)
            && (filled($this->config['status'] ?? null) || filled($this->config['ceiling'] ?? null));
    }

    /**
     * The columns to write, in this product's own vocabulary.
     *
     * @return array<string, mixed>
     */
    private function valuesFor(LicenceState $licence): array
    {
        $status = $this->config['status'] ?? null;

        if (is_array($status)) {
            return [
                (string) $status['column'] => $licence->grantsAccess
                    ? $status['granted']
                    : $status['revoked'],
            ];
        }

        /** @var array<string, mixed> $ceiling */
        $ceiling = $this->config['ceiling'];

        return [
            (string) $ceiling['column'] => $licence->grantsAccess
                // Cleared, so the product's own term governs again. Setting it
                // to our end date instead would let this package extend a term
                // the product was never paid for.
                ? null
                : now()->subDay()->startOfDay()->toDateString(),
        ];
    }

    /**
     * The rows holding licence state for one client.
     *
     * `via` is either a column on the licence table itself, or the two-step
     * path used elsewhere in this config for a table that only knows something
     * beneath the client — School Monitor's subscriptions know their branch,
     * and the branch knows the school.
     *
     * @return array<int, mixed>
     */
    private function rowIdsFor(string $externalId): array
    {
        $table = (string) $this->config['table'];
        $via = $this->config['via'];
        $key = $this->primaryKey();

        if (is_string($via)) {
            return DB::table($table)->where($via, $externalId)->pluck($key)->all();
        }

        /** @var array<string, array<int, string>> $via */
        $localColumn = (string) array_key_first($via);
        [$parentTable, $parentKey, $parentVia] = $via[$localColumn];

        $parentIds = DB::table($parentTable)->where($parentVia, $externalId)->pluck($parentKey);

        if ($parentIds->isEmpty()) {
            return [];
        }

        return DB::table($table)->whereIn($localColumn, $parentIds)->pluck($key)->all();
    }

    private function primaryKey(): string
    {
        return (string) ($this->config['primary_key'] ?? 'id');
    }

    private function rememberApplied(LicenceState $licence): void
    {
        DB::table('retention_licences')->updateOrInsert(
            ['external_id' => $licence->externalId],
            [
                'licence_version' => $licence->licenceVersion,
                'grants_access' => $licence->grantsAccess,
                'applied_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @throws ConfigurationException
     */
    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw ConfigurationException::missing(
                'licence',
                'Describe where this product keeps licence state before licence sync can be applied.',
            );
        }

        if (blank($this->config['via'] ?? null)) {
            throw ConfigurationException::missing(
                'licence.via',
                'Say how a licence row is linked to the client it belongs to.',
            );
        }
    }
}
