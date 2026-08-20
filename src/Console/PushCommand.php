<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Extraction\ClientRecord;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Throwable;

/**
 * Collects each client's figures and pushes them to Retention Intel.
 *
 * One client failing never stops the rest — a single bad tenant row should not
 * cost the whole product a day of data.
 */
class PushCommand extends Command
{
    protected $signature = 'retention:push
                            {--dry-run : Print the payloads instead of sending them}
                            {--client= : Push a single external_id, for testing}';

    protected $description = 'Push client activity and subscription data to Retention Intel';

    public function handle(
        ClientResolver $clients,
        SnapshotBuilder $snapshots,
        RetentionClient $api,
    ): int {
        if (! config('retention-extractor.enabled', true)) {
            $this->components->warn('Retention extractor is disabled. Set RETENTION_ENABLED=true to turn it on.');

            return self::SUCCESS;
        }

        try {
            $this->assertConfigured();
        } catch (ConfigurationException $exception) {
            $this->components->error($exception->getMessage());
            $this->line('  Run <fg=yellow>php artisan retention:install</> to set this up.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('client');

        $pushed = 0;
        $failed = 0;

        foreach ($clients->all() as $client) {
            if ($only !== null && $client->externalId !== $only) {
                continue;
            }

            try {
                $this->pushOne($client, $snapshots, $api, $dryRun);
                $pushed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->reportClientFailure($client, $exception);
            }
        }

        return $this->summarise($pushed, $failed, $dryRun);
    }

    private function pushOne(
        ClientRecord $client,
        SnapshotBuilder $snapshots,
        RetentionClient $api,
        bool $dryRun,
    ): void {
        $activity = $snapshots->activityPayload($client);
        $subscription = $snapshots->subscriptionPayload($client);

        if ($dryRun) {
            $this->line('');
            $this->components->twoColumnDetail(
                "<fg=cyan>{$client->name}</>",
                $client->externalId,
            );
            $this->line(json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($subscription !== null) {
                $this->line(json_encode($subscription, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return;
        }

        $api->pushActivity($activity);

        if ($subscription !== null) {
            $api->pushSubscription($subscription);
        }
    }

    private function reportClientFailure(ClientRecord $client, Throwable $exception): void
    {
        $this->components->error("{$client->name} ({$client->externalId}): {$exception->getMessage()}");

        Log::channel(config('retention-extractor.log_channel', null) ?? config('logging.default'))
            ->error('Retention push failed for a client.', [
                'external_id' => $client->externalId,
                'exception' => $exception->getMessage(),
            ]);
    }

    private function summarise(int $pushed, int $failed, bool $dryRun): int
    {
        if ($dryRun) {
            $this->components->info("Dry run complete — {$pushed} client(s) would be pushed. Nothing was sent.");

            return self::SUCCESS;
        }

        if ($failed > 0) {
            $this->components->warn("Pushed {$pushed}, failed {$failed}.");

            return self::FAILURE;
        }

        $this->components->info("Pushed {$pushed} client(s) to Retention Intel.");

        return self::SUCCESS;
    }

    private function assertConfigured(): void
    {
        if (blank(config('retention-extractor.product'))) {
            throw ConfigurationException::missing(
                'product',
                'Set RETENTION_PRODUCT_CODE to this product\'s code in Retention Intel.',
            );
        }

        if (blank(config('retention-extractor.metrics'))) {
            throw ConfigurationException::missing(
                'metrics',
                'No metrics are mapped, so there is nothing to report.',
            );
        }
    }
}
