<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Guesses a mapping by reading the product's schema.
 *
 * This exists to save typing during setup, not to be trusted. Every guess it
 * makes is written into the config file for a human to confirm — the package
 * never infers anything at run time.
 */
class SchemaInspector
{
    /**
     * Table names that usually hold the tenant in a multi-tenant product.
     *
     * @var array<int, string>
     */
    /*
     * Ordered so the unambiguous words are tried first. `clients` and
     * `customers` are last among the nouns because a product may well use them
     * for the people it serves rather than for the businesses it serves — in
     * Clinic Plus, `clients` is the patient table, and guessing it as the
     * tenant would have scoped every figure to the wrong thing.
     */
    private const array TenantCandidates = [
        'businesses', 'tenants', 'facilities', 'companies', 'organisations',
        'organizations', 'schools', 'clinics', 'saccos', 'shops', 'stores',
        'branches', 'accounts', 'clients', 'customers',
    ];

    /**
     * Column names that usually point back at the tenant.
     *
     * @var array<int, string>
     */
    private const array TenantKeyCandidates = [
        'business_id', 'tenant_id', 'facility_id', 'company_id', 'organisation_id',
        'organization_id', 'school_id', 'clinic_id', 'sacco_id', 'shop_id',
        'store_id', 'branch_id', 'account_id', 'client_id', 'customer_id',
    ];

    /**
     * Tables worth reading activity from, most telling first.
     *
     * @var array<int, string>
     */
    private const array ActivityCandidates = [
        'sales', 'orders', 'transactions', 'invoices', 'receipts', 'payments',
        'client_visits', 'visits', 'appointments', 'outpatient_consultations',
        'consultations', 'laboratory_orders', 'visit_prescriptions', 'prescriptions',
        'attendances', 'attendance_records', 'results', 'enrolments',
        'loans', 'deposits', 'contributions', 'savings',
    ];

    /**
     * The tables in the product's own database, and no others.
     *
     * On Laravel 12 `Schema::getTables()` returns every table on the server the
     * connection's user can see, not just the current database. On a developer
     * machine hosting a dozen projects that is thousands of tables from
     * unrelated schemas, and the damage is not merely noise: the tenant guess
     * matched a `businesses` table belonging to a different application, and
     * asking that table for its columns then returned nothing — an install
     * prompt with no options, which cannot be answered or escaped.
     *
     * Filtered on the row rather than passed as an argument because
     * `getTables()` took no schema before Laravel 12, and this package supports
     * back to 10. Older versions return no `schema` key, so their rows are kept
     * as they are.
     *
     * The name compared against comes from the schema builder, never from
     * `getDatabaseName()`: the two agree on MySQL and do not on SQLite, where
     * the connection is `:memory:` while every table reports a schema of
     * `main`. Comparing those would have filtered out the entire schema.
     *
     * @return array<int, string>
     */
    public function tables(): array
    {
        $current = $this->currentSchemaName();

        return array_values(array_map(
            fn (array $table): string => $table['name'],
            array_filter(
                Schema::getTables(),
                fn (array $table): bool => $current === null
                    || ! array_key_exists('schema', $table)
                    || $table['schema'] === $current,
            ),
        ));
    }

    /**
     * What this driver calls the schema the connection is pointed at, or null
     * on a version that has no such notion — in which case `getTables()` is
     * already scoped and nothing needs filtering.
     */
    private function currentSchemaName(): ?string
    {
        $builder = Schema::connection(DB::connection()->getName());

        if (! method_exists($builder, 'getCurrentSchemaName')) {
            return null;
        }

        return $builder->getCurrentSchemaName();
    }

    /**
     * Whether this table is one this product actually has.
     */
    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables(), true);
    }

    /**
     * @return array<int, string>
     */
    public function columns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    /**
     * The most likely tenant table, or null for a single-tenant product.
     */
    public function guessTenantTable(): ?string
    {
        $tables = $this->tables();

        foreach (self::TenantCandidates as $candidate) {
            if (in_array($candidate, $tables, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The column on `$table` that links a row back to its tenant.
     */
    public function guessTenantKey(string $table, ?string $tenantTable): ?string
    {
        $columns = $this->columns($table);

        if ($tenantTable !== null) {
            $derived = Str::singular($tenantTable).'_id';

            if (in_array($derived, $columns, true)) {
                return $derived;
            }
        }

        foreach (self::TenantKeyCandidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Tables that plausibly represent product use, ordered by how telling they
     * usually are.
     *
     * @return array<int, string>
     */
    public function guessActivityTables(): array
    {
        $tables = $this->tables();

        $ranked = array_values(array_filter(
            self::ActivityCandidates,
            fn (string $candidate): bool => in_array($candidate, $tables, true),
        ));

        return $ranked;
    }

    /**
     * A timestamp column suitable for windowing.
     */
    public function guessDateColumn(string $table): ?string
    {
        $columns = $this->columns($table);

        foreach (['created_at', 'transaction_date', 'sale_date', 'date', 'recorded_at', 'occurred_at'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A numeric column worth summing, for value-style metrics.
     */
    public function guessAmountColumn(string $table): ?string
    {
        $columns = $this->columns($table);

        foreach (['total', 'amount', 'total_amount', 'grand_total', 'value', 'net_total', 'price'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{table: string, start: string, end: string, status: string}|null
     */
    public function guessSubscriptionTable(): ?array
    {
        $tables = $this->tables();

        foreach (['subscriptions', 'plans', 'licences', 'licenses', 'billing_subscriptions'] as $candidate) {
            if (! in_array($candidate, $tables, true)) {
                continue;
            }

            $columns = $this->columns($candidate);

            $start = $this->firstPresent($columns, ['starts_at', 'start_date', 'began_at', 'created_at']);
            $end = $this->firstPresent($columns, ['ends_at', 'end_date', 'expires_at', 'expiry_date', 'valid_until']);

            if ($start === null || $end === null) {
                continue;
            }

            return [
                'table' => $candidate,
                'start' => $start,
                'end' => $end,
                'status' => $this->firstPresent($columns, ['status', 'state']) ?? 'status',
            ];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $candidates
     */
    public function firstPresent(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
