<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Support;

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
    private const array TenantCandidates = [
        'businesses', 'tenants', 'companies', 'organisations', 'organizations',
        'clients', 'customers', 'shops', 'stores', 'branches', 'accounts',
        'schools', 'clinics', 'saccos',
    ];

    /**
     * Column names that usually point back at the tenant.
     *
     * @var array<int, string>
     */
    private const array TenantKeyCandidates = [
        'business_id', 'tenant_id', 'company_id', 'organisation_id', 'organization_id',
        'client_id', 'customer_id', 'shop_id', 'store_id', 'branch_id', 'account_id',
        'school_id', 'clinic_id', 'sacco_id',
    ];

    /**
     * Tables worth reading activity from, most telling first.
     *
     * @var array<int, string>
     */
    private const array ActivityCandidates = [
        'sales', 'orders', 'transactions', 'invoices', 'receipts', 'payments',
        'visits', 'appointments', 'consultations', 'prescriptions',
        'attendances', 'attendance_records', 'results', 'enrolments',
        'loans', 'deposits', 'contributions', 'savings',
    ];

    /**
     * @return array<int, string>
     */
    public function tables(): array
    {
        return array_map(
            fn (array $table): string => $table['name'],
            Schema::getTables(),
        );
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
