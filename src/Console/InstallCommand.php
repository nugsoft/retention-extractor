<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Support\SchemaInspector;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Walks the developer through mapping their schema onto Retention Intel's
 * metrics, using the database to propose answers rather than asking blind.
 *
 * The result is written to config for review. Nothing is inferred later.
 */
class InstallCommand extends Command
{
    protected $signature = 'retention:install {--force : Overwrite an existing config file}';

    protected $description = 'Set up the Retention Intel extractor for this product';

    /**
     * Metrics Retention Intel scores, by product code.
     *
     * @var array<string, array<int, string>>
     */
    private const array ProductMetrics = [
        'poscream' => ['items_sold_7d', 'transactions_7d', 'transaction_value_7d'],
        'poscafe' => ['items_sold_7d', 'transactions_7d', 'transaction_value_7d'],
        'clinic_plus' => ['visits_7d', 'lab_requests_7d', 'prescriptions_7d', 'new_patients_7d'],
        'mfuko' => ['member_registrations_7d', 'loan_disbursements_7d', 'transactions_7d', 'transaction_value_7d'],
        'school_monitor' => ['academic_entries_7d', 'attendance_records_7d', 'fee_payments_7d'],
    ];

    /**
     * Where each metric is usually counted from, most likely first.
     *
     * Read twice: to default each metric's own table, and to put the tables a
     * product actually measures at the front of the "real use of this product"
     * list. One list, so those two answers cannot drift apart.
     *
     * @var array<string, array<int, string>>
     */
    private const array MetricTableHints = [
        'login_count_7d' => ['sessions', 'logins', 'login_logs', 'user_sessions', 'users'],
        'items_sold_7d' => ['sale_items', 'order_items', 'line_items'],
        'transactions_7d' => ['sales', 'transactions', 'orders'],
        'transaction_value_7d' => ['sales', 'transactions', 'orders', 'payments'],
        'visits_7d' => ['client_visits', 'visits', 'appointments', 'consultations'],
        'lab_requests_7d' => ['laboratory_orders', 'lab_requests', 'lab_tests', 'investigations'],
        'prescriptions_7d' => ['visit_prescriptions', 'prescriptions'],
        // A clinic's patients are usually its `clients`; the tenant is the
        // facility they attend.
        'new_patients_7d' => ['patients', 'clients'],
        'member_registrations_7d' => ['members'],
        'loan_disbursements_7d' => ['loans', 'disbursements'],
        'academic_entries_7d' => ['results', 'marks', 'grades'],
        'attendance_records_7d' => ['attendances', 'attendance_records'],
        'fee_payments_7d' => ['fee_payments', 'payments'],
    ];

    public function handle(SchemaInspector $schema): int
    {
        $this->components->info('Retention Intel extractor setup');
        $this->line('  Reading your schema to propose a mapping. Nothing is sent anywhere.');
        $this->newLine();

        $product = select(
            label: 'Which product is this?',
            options: array_keys(self::ProductMetrics),
        );

        $tenantTable = $this->resolveTenancy($schema);

        $lastActivity = $this->resolveLastActivity($schema, $product, $tenantTable);

        $metrics = $this->resolveMetrics($schema, $product, $tenantTable, $lastActivity);

        $subscription = $this->resolveSubscription($schema, $tenantTable);

        $this->writeConfig($product, $tenantTable, $lastActivity, $metrics, $subscription);

        $this->printNextSteps($product);

        return self::SUCCESS;
    }

    /**
     * @return array{table: string, key: string, model: string}|null
     */
    private function resolveTenancy(SchemaInspector $schema): ?array
    {
        $guess = $schema->guessTenantTable();

        $isMultiTenant = confirm(
            label: 'Does this installation serve more than one business?',
            default: $guess !== null,
            hint: $guess !== null
                ? "Found a '{$guess}' table, which suggests it does."
                : 'No obvious tenant table found, which suggests one business per install.',
        );

        if (! $isMultiTenant) {
            return null;
        }

        $tables = $schema->tables();

        if ($tables === []) {
            throw new ConfigurationException(
                'No tables found on the '.DB::connection()->getName().' connection. '
                .'Run your migrations before setting up the extractor.'
            );
        }

        $table = select(
            label: 'Which table holds those businesses?',
            options: $tables,
            default: $guess ?? $tables[0],
            scroll: 15,
        );

        $columns = $schema->columns($table);

        // A select with no options cannot be answered or escaped — it simply
        // sits there, which is what happened when the tenant guess matched a
        // table in somebody else's database. Say what is wrong instead.
        if ($columns === []) {
            throw new ConfigurationException(
                "Table '{$table}' has no readable columns on the '"
                .DB::connection()->getName()."' connection. Check that it belongs to this "
                .'product\'s database and that the connection user can read it.'
            );
        }

        return [
            'table' => $table,
            'key' => select(
                label: 'Which column identifies each business to Retention Intel?',
                options: $columns,
                default: $schema->firstPresent($columns, ['uuid', 'code', 'id']) ?? 'id',
                hint: 'This becomes external_id. It must never change for a given business.',
            ),
            'name' => select(
                label: 'Which column holds the business name?',
                options: $columns,
                default: $schema->firstPresent($columns, ['business_name', 'name', 'title', 'company_name']) ?? 'name',
            ),
            'model' => $this->guessModelClass($table),
        ];
    }

    /**
     * @param  array{table: string, key: string, model: string}|null  $tenant
     * @return array{table: string, via: ?string, date: string}
     */
    private function resolveLastActivity(SchemaInspector $schema, string $product, ?array $tenant): array
    {
        $candidates = $this->rankForProduct($schema->guessActivityTables(), $product, $schema);

        $table = select(
            label: 'Which table best represents real use of this product?',
            options: $candidates !== [] ? $candidates : $schema->tables(),
            default: $candidates[0] ?? $schema->tables()[0],
            hint: 'The newest row here decides how dormant a client looks — the strongest churn signal there is.',
            scroll: 15,
        );

        return [
            'table' => $table,
            'via' => $tenant === null ? null : $schema->guessTenantKey($table, $tenant['table']),
            'date' => $schema->guessDateColumn($table) ?? 'created_at',
        ];
    }

    /**
     * Puts the tables this particular product measures at the front.
     *
     * The candidate list is ordered for retail, so a clinic was offered `sales`
     * as the table best representing real use of it — a table Clinic Plus does
     * have, and which says nothing about whether anybody is treating patients.
     * The product has already been chosen by this point, so the ordering may as
     * well reflect it.
     *
     * @param  array<int, string>  $candidates
     * @return array<int, string>
     */
    private function rankForProduct(array $candidates, string $product, SchemaInspector $schema): array
    {
        $preferred = [];

        foreach (self::ProductMetrics[$product] ?? [] as $metric) {
            foreach (self::MetricTableHints[$metric] ?? [] as $table) {
                if (in_array($table, $candidates, true) && ! in_array($table, $preferred, true)) {
                    $preferred[] = $table;
                }
            }
        }

        return [...$preferred, ...array_values(array_diff($candidates, $preferred))];
    }

    /**
     * @param  array{table: string, key: string, model: string}|null  $tenant
     * @param  array{table: string, via: ?string, date: string}  $lastActivity
     * @return array<string, array<string, mixed>>
     */
    private function resolveMetrics(SchemaInspector $schema, string $product, ?array $tenant, array $lastActivity): array
    {
        $this->newLine();
        $this->components->info('Now the metrics '.Str::headline($product).' reports.');
        $this->line('  Pick the table each one is counted from. Choose <fg=yellow>skip</> to leave it out.');
        $this->newLine();

        $tables = $schema->tables();
        $metrics = [];

        $wanted = ['login_count_7d', ...self::ProductMetrics[$product]];

        foreach ($wanted as $metric) {
            $table = select(
                label: $metric,
                options: ['skip', ...$tables],
                default: $this->defaultTableFor($metric, $tables, $lastActivity['table']),
                scroll: 12,
            );

            if ($table === 'skip') {
                continue;
            }

            $isValue = str_contains($metric, '_value_');
            $amountColumn = $isValue ? $schema->guessAmountColumn($table) : null;

            $metrics[$metric] = array_filter([
                'table' => $table,
                'sum' => $amountColumn,
                'count' => $amountColumn === null ? '*' : null,
                'via' => $tenant === null ? null : $schema->guessTenantKey($table, $tenant['table']),
                'date' => $schema->guessDateColumn($table),
            ], fn (mixed $value): bool => $value !== null);
        }

        return $metrics;
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function defaultTableFor(string $metric, array $tables, string $activityTable): string
    {
        foreach (self::MetricTableHints[$metric] ?? [] as $candidate) {
            if (in_array($candidate, $tables, true)) {
                return $candidate;
            }
        }

        return in_array($activityTable, $tables, true) ? $activityTable : 'skip';
    }

    /**
     * @param  array{table: string, key: string, model: string}|null  $tenant
     * @return array<string, mixed>|null
     */
    private function resolveSubscription(SchemaInspector $schema, ?array $tenant): ?array
    {
        $guess = $schema->guessSubscriptionTable();

        if ($guess === null) {
            return null;
        }

        if (! confirm(label: "Push subscription dates from '{$guess['table']}'?", default: true)) {
            return null;
        }

        return [
            ...$guess,
            'via' => $tenant === null ? null : $schema->guessTenantKey($guess['table'], $tenant['table']),
        ];
    }

    /**
     * The conventional model class for a tenant table.
     *
     * Written into the config either way — a product whose model sits somewhere
     * other than App\Models needs to correct one line, which is the same job as
     * checking every other guess this command makes. Said out loud when it is
     * not there, rather than tested in a ternary returning the same thing on
     * both branches, which is what this did before and could not warn anybody.
     */
    private function guessModelClass(string $table): string
    {
        $class = '\\App\\Models\\'.Str::studly(Str::singular($table));

        if (! class_exists($class)) {
            $this->components->warn(
                "No class found at {$class}. It has been written into the config anyway — "
                .'point clients.model at your tenant model before pushing.'
            );
        }

        return $class.'::class';
    }

    /**
     * @param  array{table: string, key: string, model: string}|null  $tenant
     * @param  array{table: string, via: ?string, date: string}  $lastActivity
     * @param  array<string, array<string, mixed>>  $metrics
     * @param  array<string, mixed>|null  $subscription
     */
    private function writeConfig(
        string $product,
        ?array $tenant,
        array $lastActivity,
        array $metrics,
        ?array $subscription,
    ): void {
        $path = config_path('retention-extractor.php');

        if (file_exists($path) && ! $this->option('force')) {
            if (! confirm(label: 'config/retention-extractor.php already exists. Overwrite it?', default: false)) {
                $this->components->warn('Left the existing config alone.');

                return;
            }
        }

        $stub = file_get_contents(__DIR__.'/../../config/retention-extractor.php');

        $stub = str_replace(
            "    'metrics' => [],",
            "    'metrics' => ".$this->export($metrics, 1).',',
            $stub,
        );

        $stub = str_replace(
            "    'last_activity' => null,",
            "    'last_activity' => ".$this->export($lastActivity, 1).',',
            $stub,
        );

        if ($subscription !== null) {
            $stub = str_replace(
                "    'subscription' => null,",
                "    'subscription' => ".$this->export($subscription, 1).',',
                $stub,
            );
        }

        if ($tenant !== null) {
            $stub = str_replace("        'model' => null,", "        'model' => {$tenant['model']},", $stub);
            $stub = str_replace("        'external_id' => 'id',", "        'external_id' => '{$tenant['key']}',", $stub);
            $stub = str_replace("        'name' => 'name',", "        'name' => '{$tenant['name']}',", $stub);
        }

        file_put_contents($path, $stub);

        $this->newLine();
        $this->components->info('Wrote config/retention-extractor.php');
    }

    /**
     * @param  array<mixed>  $value
     */
    private function export(array $value, int $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $inner = str_repeat('    ', $depth + 1);

        $lines = ['['];

        foreach ($value as $key => $item) {
            $formattedKey = is_int($key) ? '' : "'{$key}' => ";

            $lines[] = is_array($item)
                ? $inner.$formattedKey.$this->export($item, $depth + 1).','
                : $inner.$formattedKey.var_export($item, true).',';
        }

        $lines[] = $indent.']';

        return implode("\n", $lines);
    }

    private function printNextSteps(string $product): void
    {
        $this->newLine();
        $this->components->bulletList([
            'Open <fg=cyan>config/retention-extractor.php</> and check every mapping it guessed.',
            'Add to <fg=cyan>.env</>: RETENTION_API_URL, RETENTION_API_KEY, '
                ."RETENTION_PRODUCT_CODE={$product}",
            'Preview what would be sent: <fg=yellow>php artisan retention:push --dry-run</>',
            'When it looks right, the daily schedule takes over automatically.',
        ]);
        $this->newLine();
        $this->components->warn('The mappings above are guesses from your schema. A wrong one sends wrong numbers, not an error.');
    }
}
