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

        // Where the tenant table is known, the column derived from its name is
        // the only answer accepted. The generic list below is not consulted,
        // and that is the whole point of this being written down:
        //
        // `fees_payment_details` in School Monitor has no `school_id`, so the
        // generic list was reached and matched `account_id` — a chart-of-
        // accounts reference. Nothing about that is the school. Offered as a
        // `via` and accepted, every figure from that table would have been
        // grouped by the wrong thing entirely, and reported as fact.
        //
        // Returning null here is not a failure. The caller asks, and offers the
        // two-step path, which is how most of these tables really do reach
        // their tenant — through a branch, a sale, a visit.
        if ($tenantTable !== null) {
            $derived = Str::singular($tenantTable).'_id';

            return in_array($derived, $columns, true) ? $derived : null;
        }

        // Only when there is no tenant table to derive from. A guess is all
        // there is, and the caller confirms it either way.
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
     * Where a client's branches live, if the product records them.
     *
     * Derived from the tenant table before anything else — a product with
     * `schools` almost always calls them `school_branches` — because a bare
     * `branches` table could belong to anything, and School Monitor has eight
     * tables with "branch" in the name of which exactly one is the branches.
     */
    public function guessBranchTable(?string $tenantTable): ?string
    {
        $candidates = [];

        if ($tenantTable !== null) {
            $candidates[] = Str::singular($tenantTable).'_branches';
            $candidates[] = Str::singular($tenantTable).'_sites';
        }

        $candidates[] = 'branches';

        foreach ($candidates as $candidate) {
            if ($this->hasTable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The column an activity row uses to name the branch it belongs to.
     *
     * Derived rather than searched for, and returned whether or not any given
     * table has it: it describes the product's convention, and the caller
     * confirms it.
     */
    public function guessBranchKey(string $branchTable): string
    {
        return Str::singular($branchTable).'_id';
    }

    /**
     * Tables that could hold a per-client weekly figure at all, most plausible
     * first, for the times there is nothing better to go on.
     *
     * Not a guess at WHICH table — it cannot be — but enough to stop the
     * alphabet deciding. School Monitor matched none of the activity names, so
     * the prompt for the table best representing real use of the product
     * arrived defaulted to `academic_reporting_cycle_sets`, the first table in
     * the database by name and a configuration table at that.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    public function orderByPlausibility(array $tables, ?string $tenantTable, ?string $branchKey = null): array
    {
        $reachable = [];
        $rest = [];

        foreach ($tables as $table) {
            $dated = $this->guessDateColumn($table) !== null;

            $reaches = $tenantTable === null
                || $this->guessTenantKey($table, $tenantTable) !== null
                || ($branchKey !== null && in_array($branchKey, $this->columns($table), true));

            $dated && $reaches ? $reachable[] = $table : $rest[] = $table;
        }

        return [...$reachable, ...$rest];
    }

    /**
     * Orders candidate tables by how much they look like they hold this metric.
     *
     * Naming knowledge cannot be had for free — six School Monitor tables carry
     * `school_branch_id` and `created_at`, and only one of them is the academic
     * entries. But a great deal can be ruled OUT by reading the schema, and
     * ruling out is most of the work: of the two tables whose names match "fee
     * payments", only one can reach the school at all, and of the three that
     * look like logins, only one has both a tenant and a date.
     *
     * So a table scores for being reachable from the tenant and for being
     * dated, since a metric cannot be counted per client per week without both,
     * and then for how much of its name the metric's name accounts for. The
     * name is the weakest signal deliberately: it is the one most likely to be
     * a coincidence.
     *
     * @param  array<int, string>  $candidates
     * @return array<int, string>
     */
    public function rankForMetric(string $metric, array $candidates, ?string $tenantTable): array
    {
        $scored = [];

        foreach ($candidates as $table) {
            $scored[$table] = $this->plausibility($metric, $table, $tenantTable);
        }

        // Anything that cannot be counted per client per week is not a
        // candidate at all, however much its name suggests otherwise.
        $scored = array_filter($scored, fn (int $score): bool => $score > 0);

        arsort($scored);

        return array_keys($scored);
    }

    /**
     * How much this table looks like it holds this metric. Zero disqualifies.
     */
    private function plausibility(string $metric, string $table, ?string $tenantTable): int
    {
        if (! $this->hasTable($table)) {
            return 0;
        }

        // A single-tenant product has nothing to reach, so this only counts
        // where there is a tenant to be reachable from.
        if ($tenantTable !== null && $this->guessTenantKey($table, $tenantTable) === null) {
            return 0;
        }

        if ($this->guessDateColumn($table) === null) {
            return 0;
        }

        $name = $this->nameOverlap($metric, $table);

        // Reachable and dated is necessary and nowhere near sufficient — in
        // School Monitor dozens of tables are both. Without the name saying so
        // too, there is no candidate here worth proposing.
        return $name === 0 ? 0 : 10 + $name;
    }

    /**
     * How many of the metric's words appear in the table's name.
     *
     * Singularised on both sides, so `fee_payments_7d` recognises
     * `fees_payments` — a difference of one letter that no list of exact names
     * would ever have caught.
     */
    private function nameOverlap(string $metric, string $table): int
    {
        $metricWords = $this->words($metric);
        $tableWords = $this->words($table);

        // Every word of the metric has to be accounted for. A partial match is
        // how `academic_entries_7d` picked `journal_entries` and
        // `attendance_records_7d` picked `learner_medical_records` — one shared
        // word each, and a confident wrong answer somebody would have accepted
        // because it was already selected.
        if (array_diff($metricWords, $tableWords) !== []) {
            return 0;
        }

        // Then the closest fit wins: `fee_payments_7d` matches both
        // `fees_payments` and `fees_payment_details`, and the one that adds
        // nothing of its own is the one meant.
        return 20 - min(19, count(array_diff($tableWords, $metricWords)));
    }

    /**
     * A name's significant words, singularised so `fee_payments_7d` and
     * `fees_payments` are recognisably the same thing — a difference of one
     * letter that no list of exact names would ever have caught.
     *
     * @return array<int, string>
     */
    private function words(string $name): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $word): string => Str::singular($word),
                explode('_', (string) preg_replace('/_\d+d$/', '', $name)),
            ),
            fn (string $word): bool => $word !== '' && ! is_numeric($word) && ! in_array($word, ['count', 'record'], true),
        ));
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
