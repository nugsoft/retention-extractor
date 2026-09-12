<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Support\ProductMapping;
use Throwable;

/**
 * Whether this product is actually wired up, in one command.
 *
 * Everything here was previously six separate checks in a deployment
 * document — run a push and read the error, curl an endpoint, open a panel,
 * suspend a test client. Each of them tells you one thing, and the order
 * matters, so somebody following them under pressure finds out about the third
 * problem after fixing the first two.
 *
 * Every line is either a fact or a stated absence. Nothing here says "probably"
 * and nothing is inferred from something adjacent: whether a key works is
 * whether NugsoftOS answered, not whether one is set.
 */
class StatusCommand extends Command
{
    protected $signature = 'retention:status';

    protected $description = 'Report what is wired up between this product and NugsoftOS';

    public function handle(RetentionClient $api, ProductMapping $mapping): int
    {
        $this->newLine();
        $this->components->info('NugsoftOS');

        $rows = [
            ['Enabled', $this->yesNo((bool) config('retention-extractor.enabled', true))],
            ['NugsoftOS', (string) (config('retention-extractor.api.url') ?: 'not set')],
            ['Product code', (string) (config('retention-extractor.api.key')
                ? config('retention-extractor.product') ?: 'not set'
                : 'not set')],
        ];

        [$reachable, $identifiedAs] = $this->askWhoWeAre($api);

        $rows[] = ['Key recognised', $identifiedAs];

        $this->table(['', ''], $rows);

        $this->reportMapping($mapping);
        $this->reportLicence($mapping);

        $this->newLine();

        // A key that is not recognised makes every line below it meaningless,
        // so it is the one thing that decides the exit code. Everything else is
        // reported and left to a person: an install part-way through setup is
        // not a failure, it is an install part-way through setup.
        if (! $reachable) {
            $this->components->error('NugsoftOS could not be reached or did not recognise this key. Nothing else here can be trusted.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function askWhoWeAre(RetentionClient $api): array
    {
        if (blank(config('retention-extractor.api.key'))) {
            return [false, 'no key set'];
        }

        try {
            $contract = $api->metrics();
        } catch (Throwable $exception) {
            return [false, 'no — '.$exception->getMessage()];
        }

        $name = $contract['product']['name'] ?? '?';
        $code = $contract['product']['code'] ?? '?';

        return [true, "yes — {$name} ({$code})"];
    }

    private function reportMapping(ProductMapping $mapping): void
    {
        $this->newLine();
        $this->components->info('Where the mappings come from');

        $subscription = $mapping->subscription();

        $this->table(['', ''], [
            ['Source', $mapping->isRemote() ? 'NugsoftOS' : 'this product\'s config file'],
            ['Subscription', is_array($subscription) && filled($subscription['table'] ?? null)
                ? "reporting the term from '{$subscription['table']}'"
                : 'not mapped — clients arrive with no term'],
        ]);
    }

    private function reportLicence(ProductMapping $mapping): void
    {
        $this->newLine();
        $this->components->info('Switching a client off');

        $licence = $mapping->licence();
        $applier = app(LicenceApplier::class);

        if (! $applier->isConfigured()) {
            $this->table(['', ''], [
                ['Licence sync', 'OFF — nothing can switch a client off here'],
                ['Webhook', $mapping->isRemote()
                    ? 'mounted, but answering 503 until a mapping arrives'
                    : 'not mounted'],
            ]);

            return;
        }

        $strategy = filled($licence['status'] ?? null)
            ? "a word in {$licence['table']}.{$licence['status']['column']}"
            : "a date in {$licence['table']}.{$licence['ceiling']['column']}";

        $this->table(['', ''], [
            ['Licence sync', 'on'],
            ['Access written as', $strategy],
            ['Ignoring rows where', $this->describeWhere($licence)],
            ['Listening on', (string) (config('retention-extractor.licence.route') ?: 'not set')],
            ['Signing secret', $this->yesNo(filled(config('retention-extractor.licence.secret')))],
            ['Pull runs', (string) (config('retention-extractor.licence.pull_at') ?: 'never — scheduled by hand')],
            ['Last applied', $this->lastApplied()],
        ]);

        if (blank(config('retention-extractor.licence.secret'))) {
            $this->newLine();
            $this->components->warn('No signing secret, so every delivery will be refused. It must match `sync_secret` on the product in NugsoftOS.');
        }
    }

    /**
     * @param  array<string, mixed>  $licence
     */
    private function describeWhere(array $licence): string
    {
        $where = $licence['where'] ?? null;

        if (! is_array($where) || $where === []) {
            return 'nothing — every row is written and read';
        }

        return collect($where)
            ->map(fn (mixed $value, string $column): string => $value === null
                ? "{$column} is set"
                : "{$column} is not ".var_export($value, true))
            ->implode(', ');
    }

    /**
     * When this product last accepted a licence, and for how many clients.
     *
     * Read from the package's own bookkeeping rather than from the product's
     * tables, because it is the only record of a delivery having been applied
     * as opposed to a client happening to be suspended.
     */
    private function lastApplied(): string
    {
        try {
            $row = DB::table('retention_licences')
                ->selectRaw('count(*) as clients, max(updated_at) as last')
                ->first();
        } catch (Throwable) {
            return 'never — the bookkeeping table is missing, so run migrations';
        }

        if ($row === null || (int) $row->clients === 0) {
            return 'never';
        }

        return "{$row->last} ({$row->clients} client(s))";
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
