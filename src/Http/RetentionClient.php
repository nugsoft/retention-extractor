<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Exceptions\PushFailedException;

/**
 * Talks to the NugsoftOS ingestion API.
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

    /**
     * What this product's licences currently say.
     *
     * The authoritative answer, asked for rather than waited for. Pass an
     * external id to ask about one client, which is the form used on login.
     *
     * @return array{product?: string, as_of?: string, licences?: array<int, array<string, mixed>>}
     */
    public function licences(?string $externalId = null): array
    {
        $endpoint = '/api/v1/licence'.($externalId === null ? '' : '?external_id='.urlencode($externalId));

        $response = $this->request()->get($this->url($endpoint));

        if ($response->failed()) {
            throw PushFailedException::fromResponse('GET', $endpoint, $response->status(), $response->body());
        }

        /** @var array{product?: string, as_of?: string, licences?: array<int, array<string, mixed>>} $body */
        $body = $response->json() ?? [];

        return $body;
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
     * What NugsoftOS needs from this product.
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
            throw PushFailedException::fromResponse('GET', '/api/v1/metrics', $response->status(), $response->body());
        }

        /** @var array{product: array{code: string, name: string}, scored: bool, required: array<int, string>, accepted: array<int, string>, components: array<int, string>, hints: array<string, array{tables: array<int, string>, where?: array<string, mixed>, distinct?: string}>} $contract */
        $contract = $response->json() ?? [];

        return $contract;
    }

    /**
     * Where this product keeps its subscriptions and its licence.
     *
     * Deliberately small and deliberately dull: it is read on the path that
     * applies a licence, so it carries no scoring data and nothing that would
     * grow.
     *
     * @return array<string, mixed>
     *
     * @throws PushFailedException
     */
    public function mapping(): array
    {
        $response = $this->request()->get($this->url('/api/v1/mapping'));

        if ($response->failed()) {
            throw PushFailedException::fromResponse('GET', '/api/v1/mapping', $response->status(), $response->body());
        }

        /** @var array<string, mixed> $mapping */
        $mapping = $response->json() ?? [];

        return $mapping;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $payload): array
    {
        $response = $this->request()->post($this->url($endpoint), $payload);

        if ($response->failed()) {
            throw PushFailedException::fromResponse('POST', $endpoint, $response->status(), $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * The key, checked here rather than discovered at the far end.
     *
     * NugsoftOS refuses anything that is not 64 lowercase hex characters
     * before it looks the key up, and answers a bare 401 — which this package
     * then reports as "the API key was not recognised", sending somebody to
     * check a key that is already correct. A trailing newline in `.env` is
     * enough to cause it, and nothing in the round trip says so.
     *
     * Surrounding whitespace is forgiven rather than reported: it comes from
     * the file, not from the person, and there is nothing to decide about it.
     * Anything else is named here, while the key is still in front of us.
     */
    private function apiKey(): string
    {
        $key = config('retention-extractor.api.key');

        if (blank($key)) {
            throw ConfigurationException::missing(
                'api.key',
                'Set RETENTION_API_KEY to the key issued by the CTO for this product.',
            );
        }

        $key = trim((string) $key);

        if (preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
            throw ConfigurationException::malformedApiKey($key);
        }

        return $key;
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->withToken($this->apiKey())
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
