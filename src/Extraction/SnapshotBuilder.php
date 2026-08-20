<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Extraction;

use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;

/**
 * Turns a client plus its aggregates into the exact payloads the ingestion
 * endpoints expect.
 */
class SnapshotBuilder
{
    public function __construct(
        private readonly MetricCollector $metrics,
        private readonly string $product,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function activityPayload(ClientRecord $client): array
    {
        $metrics = $this->metrics->collect($client);

        $lastActivity = $this->resolveLastActivityDate($client);

        return [
            ...$client->toPayload(),
            'product' => $this->product,
            'last_activity_date' => $lastActivity,
            ...$metrics,
            // Everything collected is echoed back so Retention Intel keeps the
            // raw figures even for metrics it does not score.
            'raw_payload' => [
                'collected_at' => now()->toIso8601String(),
                'window_days' => (int) config('retention-extractor.window_days', 7),
                'metrics' => $metrics,
            ],
        ];
    }

    /**
     * Null when the product does not track subscriptions locally.
     *
     * @return array<string, mixed>|null
     */
    public function subscriptionPayload(ClientRecord $client): ?array
    {
        $mapping = config('retention-extractor.subscription');

        if (! is_array($mapping) || blank($mapping['table'] ?? null)) {
            return null;
        }

        $query = DB::table($mapping['table']);

        if ($client->isScoped() && filled($mapping['via'] ?? null)) {
            $query->where($mapping['via'], $client->key);
        }

        $row = $query->orderByDesc($mapping['end'] ?? 'ends_at')->first();

        if ($row === null) {
            return null;
        }

        $start = $row->{$mapping['start'] ?? 'starts_at'} ?? null;
        $end = $row->{$mapping['end'] ?? 'ends_at'} ?? null;

        if (blank($start) || blank($end)) {
            return null;
        }

        return [
            ...$client->toPayload(),
            'product' => $this->product,
            'start_date' => substr((string) $start, 0, 10),
            'end_date' => substr((string) $end, 0, 10),
            'status' => $this->mapStatus($row->{$mapping['status'] ?? 'status'} ?? null, $mapping),
        ];
    }

    /**
     * A client with no recorded activity is reported as dormant since the day
     * they were created rather than skipped — never reporting them at all would
     * hide exactly the clients most at risk.
     */
    private function resolveLastActivityDate(ClientRecord $client): string
    {
        $mapping = config('retention-extractor.last_activity');

        if (! is_array($mapping)) {
            throw ConfigurationException::missing(
                'last_activity',
                'Name the table that best represents real use of your product; it drives dormancy.',
            );
        }

        return $this->metrics->lastActivityDate($mapping, $client)
            ?? now()->subYear()->toDateString();
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function mapStatus(mixed $status, array $mapping): string
    {
        $status = is_string($status) ? strtolower($status) : '';

        $map = $mapping['status_map'] ?? [];

        if (isset($map[$status])) {
            return $map[$status];
        }

        return in_array($status, ['active', 'expired', 'cancelled'], true)
            ? $status
            : 'active';
    }
}
