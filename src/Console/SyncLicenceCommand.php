<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Console;

use Illuminate\Console\Command;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;
use Nugsoft\RetentionExtractor\Support\ProductMapping;
use Throwable;

/**
 * Asks Retention Intel what this product's licences currently say, and applies
 * the answer.
 *
 * The half that makes the webhook safe to depend on. A webhook can be missed —
 * a worker dies, this product is restarting, a firewall rule changes for an
 * afternoon — and a missed suspension means a client keeps working for as long
 * as nobody notices. Asking on a schedule heals from that without anybody
 * finding out from the client first.
 *
 * Deliberately not version-guarded: the answer to "what is it now" is never
 * stale, so unlike a pushed delivery this always applies.
 */
class SyncLicenceCommand extends Command
{
    protected $signature = 'retention:sync-licence
                            {--client= : Sync a single external_id}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Fetch current licences from Retention Intel and apply them here';

    public function handle(RetentionClient $api, ProductMapping $mapping): int
    {
        if (! config('retention-extractor.enabled', true)) {
            $this->components->warn('Retention extractor is disabled. Set RETENTION_ENABLED=true to turn it on.');

            return self::SUCCESS;
        }

        // This command already asks for the current state rather than carrying
        // one, so it is the right place to ask where that state lives too. It
        // is also the lever: a mapping corrected centrally is live on the next
        // pull instead of whenever the cache happens to lapse.
        if ($mapping->isRemote()) {
            $mapping->forget();
        }

        $applier = app(LicenceApplier::class);

        if (! $applier->isConfigured()) {
            $mapping->isRemote()
                ? $this->components->error('Retention Intel has no licence mapping for this product, or could not be reached.')
                : $this->components->error('Licence sync is not configured. Fill in the `licence` block in config/retention-extractor.php.');

            return self::FAILURE;
        }

        try {
            $response = $api->licences($this->option('client'));
        } catch (Throwable $exception) {
            $this->components->error("Could not reach Retention Intel: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $applied = 0;
        $missing = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($response['licences'] ?? [] as $payload) {
            $licence = LicenceState::fromPayload($payload);

            if ($dryRun) {
                $this->line(sprintf(
                    '  %s — %s (v%d)',
                    $licence->externalId,
                    $licence->grantsAccess ? 'may work' : 'switched off',
                    $licence->licenceVersion,
                ));

                continue;
            }

            // One client failing must not cost the rest their sync — the same
            // rule the daily push has followed since the day it was written.
            try {
                $changed = $applier->apply($licence);
                $changed > 0 ? $applied++ : $missing++;
            } catch (Throwable $exception) {
                $this->components->warn("{$licence->externalId}: {$exception->getMessage()}");
            }
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $this->components->info("Licences applied: {$applied}. Not found here: {$missing}.");

        return self::SUCCESS;
    }
}
