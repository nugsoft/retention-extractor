<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Nugsoft\RetentionExtractor\Support\SchemaInspector;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

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

    /**
     * Where Retention Intel says this product keeps each metric, and which of
     * that table's rows count.
     *
     * @var array<string, array{tables: array<int, string>, where?: array<string, mixed>, distinct?: string}>
     */
    private array $hints = [];

    /** The tenant table, for judging whether a candidate can reach a client. */
    private ?string $tenantTable = null;

    private SchemaInspector $schema;

    public function handle(SchemaInspector $schema, RetentionClient $api): int
    {
        $this->schema = $schema;

        $this->components->info('Retention Intel extractor setup');
        $this->line('  Reading your schema to propose a mapping. Nothing is sent anywhere.');
        $this->newLine();

        [$product, $wanted] = $this->resolveContract($api);

        $tenantTable = $this->resolveTenancy($schema);

        $this->tenantTable = $tenantTable['table'] ?? null;

        $lastActivity = $this->resolveLastActivity($schema, $wanted, $tenantTable);

        $metrics = $this->resolveMetrics($schema, $wanted, $tenantTable, $lastActivity);

        $subscription = $this->resolveSubscription($schema, $tenantTable);

        $this->writeConfig($product, $tenantTable, $lastActivity, $metrics, $subscription);

        $this->printNextSteps($product);

        return self::SUCCESS;
    }

    /**
     * Which product this is, and what Retention Intel needs from it.
     *
     * Asked of Retention Intel rather than read off a list in here. The list
     * only knew the five products that existed when it was written: a sixth
     * could not be chosen at all, and what it claimed each product reports
     * could drift from what is actually scored with nothing to catch it.
     *
     * The API key is what says which product is asking, so it is collected
     * first — it has to be set before anything can be pushed anyway, and asking
     * now saves a trip back to the .env between setting up and finding out
     * whether it works.
     *
     * Falls back to the built-in list when Retention Intel cannot be reached,
     * because being offline should not stop somebody mapping their schema. What
     * it must not do is fall back silently: a product outside that list would
     * then be set up against the wrong metrics entirely.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function resolveContract(RetentionClient $api): array
    {
        $url = text(
            label: 'Where is Retention Intel?',
            default: (string) config('retention-extractor.api.url'),
            required: true,
        );

        $key = text(
            label: 'The API key issued for this product',
            placeholder: '64 hex characters, from the CTO',
            default: (string) config('retention-extractor.api.key'),
            hint: 'Leave blank to map your schema offline against the built-in list.',
        );

        config()->set('retention-extractor.api.url', $url);
        config()->set('retention-extractor.api.key', $key);

        if (blank($key)) {
            return $this->contractFromBuiltInList('No key given.');
        }

        try {
            $contract = $api->metrics();
        } catch (Throwable $exception) {
            return $this->contractFromBuiltInList($exception->getMessage());
        }

        $product = $contract['product']['code'];

        $this->hints = $contract['hints'] ?? [];

        $this->components->info("Retention Intel knows this key as {$contract['product']['name']}.");

        if ($contract['scored'] === false) {
            $this->components->warn(
                "Nobody has decided how {$product} is scored yet, so it is asked for nothing. "
                .'Its pushes are kept, and start counting the day somebody writes its targets.'
            );
        }

        return [$product, $contract['required']];
    }

    /**
     * The metrics this package shipped knowing about, when Retention Intel
     * cannot be asked.
     *
     * Says why it is guessing, and says which products it knows — somebody
     * setting up a product that is not among them needs to know that answering
     * this prompt cannot give them the right answer.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function contractFromBuiltInList(string $because): array
    {
        $this->newLine();
        $this->components->warn("Could not ask Retention Intel what it needs: {$because}");
        $this->line('  Falling back to the list built into this package, which knows only the');
        $this->line('  products that existed when it was released. If yours is newer than that,');
        $this->line('  stop, fix the connection, and run this again.');
        $this->newLine();

        $product = select(
            label: 'Which product is this?',
            options: array_keys(self::ProductMetrics),
        );

        return [$product, ['login_count_7d', ...self::ProductMetrics[$product]]];
    }

    /**
     * @return array{table: string, key: string, name: string, model: string}|null
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
     * @param  array<int, string>  $wanted
     * @param  array{table: string, key: string, name: string, model: string}|null  $tenant
     * @return array{table: string, via: string|array<string, array{0: string, 1: string, 2: string}>|null, date: string}
     */
    private function resolveLastActivity(SchemaInspector $schema, array $wanted, ?array $tenant): array
    {
        $candidates = $this->rankForMetrics($schema->guessActivityTables(), $wanted);

        $table = select(
            label: 'Which table best represents real use of this product?',
            options: $candidates !== [] ? $candidates : $schema->tables(),
            default: $candidates[0] ?? $schema->tables()[0],
            hint: 'The newest row here decides how dormant a client looks — the strongest churn signal there is.',
            scroll: 15,
        );

        return [
            'table' => $table,
            'via' => $this->resolveVia($schema, $table, $tenant, 'last_activity', allowSkip: false),
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
     * @param  array<int, string>  $wanted
     * @return array<int, string>
     */
    private function rankForMetrics(array $candidates, array $wanted): array
    {
        $preferred = [];

        foreach ($wanted as $metric) {
            // A metric Retention Intel asks for that this package has never
            // heard of has no hint, and simply contributes no preference.
            foreach (self::MetricTableHints[$metric] ?? [] as $table) {
                if (in_array($table, $candidates, true) && ! in_array($table, $preferred, true)) {
                    $preferred[] = $table;
                }
            }
        }

        return [...$preferred, ...array_values(array_diff($candidates, $preferred))];
    }

    /**
     * @param  array<int, string>  $wanted
     * @param  array{table: string, key: string, name: string, model: string}|null  $tenant
     * @param  array<string, mixed>  $lastActivity
     * @return array<string, array<string, mixed>>
     */
    private function resolveMetrics(SchemaInspector $schema, array $wanted, ?array $tenant, array $lastActivity): array
    {
        $this->newLine();
        $this->components->info('Now the metrics Retention Intel scores this product on.');
        $this->line('  Pick the table each one is counted from. Choose <fg=yellow>skip</> to leave it out.');
        $this->newLine();

        $tables = $schema->tables();
        $metrics = [];

        foreach ($wanted as $metric) {
            $table = select(
                label: $metric,
                options: ['skip', ...$tables],
                default: $this->defaultTableFor($metric, $tables),
                hint: $metric === 'login_count_7d'
                    ? 'Skip this if the product keeps no record of people signing in — plenty do not.'
                    : '',
                scroll: 12,
            );

            if ($table === 'skip') {
                continue;
            }

            $via = $this->resolveVia($schema, $table, $tenant, $metric);

            // 'skip' comes back when there is no way from this table to the
            // client and the answer is to leave the metric out.
            if ($via === 'skip') {
                continue;
            }

            $isValue = str_contains($metric, '_value_');
            $amountColumn = $isValue ? $schema->guessAmountColumn($table) : null;

            $narrowing = $this->narrowingFor($metric, $table);

            $metrics[$metric] = array_filter([
                'table' => $table,
                'sum' => $amountColumn,
                'count' => $amountColumn === null && ! isset($narrowing['distinct']) ? '*' : null,
                'distinct' => $narrowing['distinct'] ?? null,
                'via' => $via,
                'date' => $schema->guessDateColumn($table),
                'where' => $narrowing['where'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $metrics;
    }

    /**
     * Which rows of this table count, where Retention Intel has said.
     *
     * Naming a table is not always enough. School Monitor writes the same
     * `auth` action for logging in, logging out, changing a password and
     * impersonating somebody, so counting that table — or even counting that
     * action — reports roughly twice the logins there were, every session
     * counted again on the way out, and looks entirely reasonable doing it.
     *
     * Only applied where the hint's own table is the one being used. A filter
     * written for `system_audit_trails` means nothing against whatever else
     * somebody chose instead, and applied blindly would quietly match no rows.
     *
     * @return array{where?: array<string, mixed>, distinct?: string}
     */
    private function narrowingFor(string $metric, string $table): array
    {
        $hint = $this->hints[$metric] ?? [];

        if (! in_array($table, $hint['tables'] ?? [], true)) {
            return [];
        }

        return array_filter([
            'where' => $hint['where'] ?? null,
            'distinct' => $hint['distinct'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * How rows in this table reach the client they belong to.
     *
     * Asked rather than guessed-or-abandoned. Where the guess failed this used
     * to write the mapping without a `via` at all, and the push then refused it
     * — correctly, since counting the table whole for every client is worse
     * than not counting it. But it meant `retention:install` produced a config
     * it knew would be rejected, and said nothing about it: Clinic Plus was
     * handed `sessions` for its login count, a table with no facility on it,
     * and the first push died on exactly that.
     *
     * Three answers, because there are three real situations: the column is
     * there and was not recognised, the table reaches the client through
     * another one, or the product genuinely cannot measure this and the metric
     * should be left out.
     *
     * @param  array{table: string, key: string, name: string, model: string}|null  $tenant
     * @return string|array<string, array{0: string, 1: string, 2: string}>|null
     */
    private function resolveVia(SchemaInspector $schema, string $table, ?array $tenant, string $metric, bool $allowSkip = true): string|array|null
    {
        // Single-tenant: every row in the database belongs to the one client.
        if ($tenant === null) {
            return null;
        }

        $guess = $schema->guessTenantKey($table, $tenant['table']);

        if ($guess !== null) {
            return $guess;
        }

        $this->newLine();
        $this->components->warn("Nothing on '{$table}' looks like a link to '{$tenant['table']}'.");

        $columns = $schema->columns($table);

        $answer = select(
            label: "How does a row in '{$table}' reach the {$tenant['table']} it belongs to?",
            options: [
                ...($allowSkip ? ['skip' => "Leave {$metric} out — this product cannot measure it"] : []),
                'hop' => 'Through another table',
                ...array_combine($columns, $columns),
            ],
            hint: "Pick a column only if it holds a {$tenant['table']}.{$tenant['key']} value.",
            scroll: 12,
        );

        return $answer === 'hop'
            ? $this->resolveHop($schema, $table, $tenant, $columns)
            : $answer;
    }

    /**
     * A two-step path, for a table that reaches the client through another.
     *
     * Laravel's own `sessions` is the everyday case: it knows which user a row
     * belongs to and nothing about which business that user works for.
     *
     * @param  array{table: string, key: string, name: string, model: string}  $tenant
     * @param  array<int, string>  $columns
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function resolveHop(SchemaInspector $schema, string $table, array $tenant, array $columns): array
    {
        $localKey = select(
            label: "Which column on '{$table}' points at that other table?",
            options: $columns,
            scroll: 12,
        );

        $through = select(
            label: 'And which table is that?',
            options: $schema->tables(),
            default: Str::plural(Str::beforeLast($localKey, '_id')),
            scroll: 12,
        );

        $throughColumns = $schema->columns($through);

        return [
            $localKey => [
                $through,
                select(
                    label: "Which column on '{$through}' does {$table}.{$localKey} match?",
                    options: $throughColumns,
                    default: $schema->firstPresent($throughColumns, ['id']) ?? 'id',
                    scroll: 12,
                ),
                select(
                    label: "And which column on '{$through}' holds the {$tenant['table']} it belongs to?",
                    options: $throughColumns,
                    default: $schema->guessTenantKey($through, $tenant['table']) ?? 'id',
                    scroll: 12,
                ),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function defaultTableFor(string $metric, array $tables): string
    {
        // Retention Intel first. Table names are knowledge about a product, and
        // it is where that knowledge is kept and corrected — no release of this
        // package is needed to teach it a new one.
        foreach ($this->hints[$metric]['tables'] ?? [] as $candidate) {
            if (in_array($candidate, $tables, true)) {
                return $candidate;
            }
        }

        // Then the generic names this package shipped with, which cover the
        // ordinary shapes nobody has had to write down.
        foreach (self::MetricTableHints[$metric] ?? [] as $candidate) {
            if (in_array($candidate, $tables, true)) {
                return $candidate;
            }
        }

        // Then the schema itself. This cannot tell `exam_marks` from
        // `exam_sets` — they are identical in everything but their names — but
        // it rules out every table that could not hold a per-client weekly
        // count whatever it is called, which is usually most of them, and it
        // reads `fees_payments` as the fee payments without anybody saying so.
        $ranked = $this->schema->rankForMetric($metric, $tables, $this->tenantTable);

        if ($ranked !== []) {
            return $ranked[0];
        }

        // Nothing knows where this metric lives, so nothing is proposed.
        //
        // This used to fall back to whatever was picked as the table best
        // representing real use of the product, which is a plausible guess for
        // a transaction count and nonsense for anything else: School Monitor
        // has no attendance table at all and was offered `exam_marks` for its
        // attendance records, already selected. A default is accepted far more
        // often than it is read.
        //
        // Skipping is recoverable and loud — Retention Intel refuses a push
        // missing a metric it scores, and names it. A wrong table is silent.
        return 'skip';
    }

    /**
     * @param  array{table: string, key: string, name: string, model: string}|null  $tenant
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

        $via = $this->resolveVia($schema, $guess['table'], $tenant, 'the subscription');

        if ($via === 'skip') {
            return null;
        }

        return [...$guess, 'via' => $via];
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
     * @param  array{table: string, key: string, name: string, model: string}|null  $tenant
     * @param  array<string, mixed>  $lastActivity
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
