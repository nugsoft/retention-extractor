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
     * What Retention Intel needs from this product.
     *
     * Asked rather than assumed. This package used to carry its own list of the
     * metrics each of five products reports, so a product added to Retention
     * Intel could not be set up here at all until the package was released
     * again — and the copy could drift from what is actually scored with
     * nothing to notice.
     *
     * Nothing is passed: the API key says which product is asking.
     *
     * @return array{
     *     product: array{code: string, name: string},
     *     scored: bool,
     *     required: array<int, string>,
     *     accepted: array<int, string>,
     *     components: array<int, string>,
     *     hints: array<string, array{tables: array<int, string>, where?: array<string, mixed>, distinct?: string}>,
     * }
     */
    public function metrics(): array
    {
        $response = $this->request()->get($this->url('/api/v1/metrics'));

        if ($response->failed()) {
            throw PushFailedException::fromResponse('/api/v1/metrics', $response->status(), $response->body());
        }

        /** @var array{product: array{code: string, name: string}, scored: bool, required: array<int, string>, accepted: array<int, string>, components: array<int, string>, hints: array<string, array{tables: array<int, string>, where?: array<string, mixed>, distinct?: string}>} $contract */
        $contract = $response->json() ?? [];

        return $contract;
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
