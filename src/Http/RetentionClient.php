<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Exceptions\PushFailedException;

/**
 * Talks to the Retention Intel ingestion API.
 *
 * Both endpoints are idempotent, so a retry after a timeout is safe — re-sending
 * the same day's snapshot replaces it rather than duplicating.
 */
class RetentionClient
{
    public function __construct(private readonly Factory $http) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function pushActivity(array $payload): array
    {
        return $this->post('/api/v1/activity', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function pushSubscription(array $payload): array
    {
        return $this->post('/api/v1/subscription', $payload);
    }

    public function isReachable(): bool
    {
        try {
            return $this->request()->get($this->url('/api/v1/health'))->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $payload): array
    {
        $response = $this->request()->post($this->url($endpoint), $payload);

        if ($response->failed()) {
            throw PushFailedException::fromResponse($endpoint, $response->status(), $response->body());
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        $key = config('retention-extractor.api.key');

        if (blank($key)) {
            throw ConfigurationException::missing(
                'api.key',
                'Set RETENTION_API_KEY to the key issued by the CTO for this product.',
            );
        }

        return $this->http
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('retention-extractor.api.timeout', 15))
            // Backs off rather than hammering: a product system retrying hard
            // against a shared-hosting API helps nobody.
            ->retry(
                (int) config('retention-extractor.api.retries', 3),
                throw: false,
                sleepMilliseconds: fn (int $attempt): int => $attempt * 500,
            );
    }

    private function url(string $endpoint): string
    {
        $base = rtrim((string) config('retention-extractor.api.url'), '/');

        if ($base === '') {
            throw ConfigurationException::missing('api.url', 'Set RETENTION_API_URL.');
        }

        return $base.$endpoint;
    }
}
