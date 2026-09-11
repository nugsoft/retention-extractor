<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Support;

use Illuminate\Support\Facades\Cache;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Throwable;

/**
 * Where this product keeps its subscriptions and its licence.
 *
 * Two places this can come from, and which one is a deliberate choice made per
 * install rather than a fallback chain nobody can predict.
 *
 * `local` reads the config file published here, and is right for a product
 * whose team owns this integration: the mapping sits in their repository,
 * changes go through their review, and nothing outside can move it.
 *
 * `remote` asks Retention Intel. That exists because for some products the
 * first option is not available at any price — a team with its own roadmap, an
 * install nobody there can deploy to. The package goes in once and everything
 * after has to be answerable from the other side, or it is not answerable.
 *
 * Three rules make `remote` safe enough to offer:
 *
 *   - It is opted into. Nothing switches to it on its own, because a product
 *     silently taking instructions about which table to write from the network
 *     is not a default anybody should get by accident.
 *   - Secrets never travel. The signing secret and the route stay in this
 *     product's own environment and are merged in here, so the answer can say
 *     where things are without being able to say who may change them.
 *   - A failure is never a guess. The last good answer is kept and used; with
 *     no answer at all this reports nothing mapped, and the webhook then says
 *     503 and is retried rather than applying a licence against a table it is
 *     no longer sure about.
 */
class ProductMapping
{
    /**
     * Long enough that the webhook is not waiting on the network, short enough
     * that a correction made centrally is live the same morning. The pull
     * refreshes it outright, so a change that cannot wait has a lever.
     */
    public const int CacheSeconds = 1800;

    public const string CacheKey = 'retention-extractor.mapping';

    public function __construct(private readonly RetentionClient $api) {}

    /**
     * @return array<string, mixed>
     */
    public function licence(): array
    {
        $local = (array) config('retention-extractor.licence', []);

        if (! $this->isRemote()) {
            return $local;
        }

        $remote = $this->fetched()['licence'] ?? null;

        if (! is_array($remote)) {
            return $local;
        }

        // The secret and the route are this product's own, never Retention
        // Intel's to send. Everything describing where things live is taken
        // from the answer; everything describing who may change them is not.
        return [
            ...$remote,
            'secret' => $local['secret'] ?? null,
            'route' => $local['route'] ?? null,
            'pull_at' => $local['pull_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function subscription(): ?array
    {
        if (! $this->isRemote()) {
            $local = config('retention-extractor.subscription');

            return is_array($local) ? $local : null;
        }

        $remote = $this->fetched()['subscription'] ?? null;

        return is_array($remote) ? $remote : null;
    }

    /**
     * Throw away what is remembered, so the next read asks again.
     */
    public function forget(): void
    {
        Cache::forget(self::CacheKey);
    }

    public function isRemote(): bool
    {
        return config('retention-extractor.mapping_source') === 'remote';
    }

    /**
     * The answer, remembered.
     *
     * A failure keeps whatever was last known rather than reverting to the
     * local file, because on a remote install that file is empty and reverting
     * to it would read as "nothing is mapped here" — which would suspend
     * nobody and, worse, report every client as unrestricted.
     *
     * @return array<string, mixed>
     */
    private function fetched(): array
    {
        $remembered = Cache::get(self::CacheKey);

        if (is_array($remembered)) {
            return $remembered;
        }

        try {
            $mapping = $this->api->mapping();
        } catch (Throwable) {
            // Nothing known and nothing reachable. Answering with an empty
            // mapping is the honest outcome: it reports nothing as mapped, and
            // the webhook refuses with a 503 that will be retried.
            return [];
        }

        Cache::put(self::CacheKey, $mapping, self::CacheSeconds);

        return $mapping;
    }
}
