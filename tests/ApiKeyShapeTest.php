<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Exceptions\PushFailedException;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Nugsoft\RetentionExtractor\Support\ApiKeyShape;

/**
 * Saying what actually went wrong with the key.
 *
 * NugsoftOS refuses a key of the wrong shape before it looks it up, and
 * answers a bare 401 — identical, from here, to a key that was revoked. The
 * package reported that as "the API key was not recognised", so the fix people
 * reached for was to re-paste a key that had been right all along. A trailing
 * newline in `.env` was enough to cause it.
 */
describe('the shape of a key', function (): void {
    it('accepts what NugsoftOS issues', function (): void {
        expect(ApiKeyShape::isWellFormed(str_repeat('a', 64)))->toBeTrue()
            ->and(ApiKeyShape::describe(str_repeat('a', 64)))->toBe('well formed');
    });

    it('says how a key of the wrong length is wrong', function (): void {
        expect(ApiKeyShape::describe(str_repeat('a', 63)))->toBe('63 characters — 1 too few')
            ->and(ApiKeyShape::describe(str_repeat('a', 70)))->toBe('70 characters — 6 too many');
    });

    it('tells uppercase apart from not-hex, because they are different mistakes', function (): void {
        expect(ApiKeyShape::describe(str_repeat('A', 64)))
            ->toContain('uppercase')
            ->and(ApiKeyShape::describe(str_repeat('z', 64)))
            ->toContain('hex digit');
    });

    it('never puts the key in the description', function (): void {
        $key = str_repeat('deadbeef', 9);

        expect(ApiKeyShape::describe($key))->not->toContain('deadbeef');
    });
});

describe('before anything is sent', function (): void {
    it('refuses a key of the wrong shape, naming the problem', function (): void {
        Http::fake();
        config()->set('retention-extractor.api.key', str_repeat('a', 64)."\n_extra");

        expect(fn () => app(RetentionClient::class)->metrics())
            ->toThrow(ConfigurationException::class, 'is not the shape NugsoftOS issues');

        // Nothing left this machine: the key could not have worked, and a
        // request would have come back as "not recognised".
        Http::assertNothingSent();
    });

    it('forgives whitespace around a key, which comes from the file not the person', function (): void {
        Http::fake(['*/api/v1/metrics' => Http::response([
            'product' => ['code' => 'clinic_plus', 'name' => 'Clinic Plus'],
            'scored' => true,
            'required' => [],
            'accepted' => [],
            'components' => [],
            'hints' => [],
        ])]);

        config()->set('retention-extractor.api.key', ' '.str_repeat('a', 64)."\n");

        expect(app(RetentionClient::class)->metrics()['product']['code'])->toBe('clinic_plus');

        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Bearer '.str_repeat('a', 64),
        ));
    });

    it('still says when no key is set at all', function (): void {
        config()->set('retention-extractor.api.key', null);

        expect(fn () => app(RetentionClient::class)->metrics())
            ->toThrow(ConfigurationException::class, 'is not configured');
    });
});

describe('naming the request that failed', function (): void {
    it('calls a GET a GET', function (): void {
        // The whole bug: /api/v1/metrics answers GET only, and a failure
        // reading it was reported as a POST — so the first guess was a method
        // mismatch that did not exist.
        Http::fake(['*/api/v1/metrics' => Http::response('who are you', 401)]);

        expect(fn () => app(RetentionClient::class)->metrics())
            ->toThrow(PushFailedException::class, 'GET /api/v1/metrics failed');
    });

    it('still calls a push a POST', function (): void {
        Http::fake(['*/api/v1/activity' => Http::response('nope', 401)]);

        expect(fn () => app(RetentionClient::class)->pushActivity(['product' => 'clinic_plus']))
            ->toThrow(PushFailedException::class, 'POST /api/v1/activity failed');
    });

    it('points at the config cache when a key is refused', function (): void {
        Http::fake(['*/api/v1/metrics' => Http::response('who are you', 401)]);

        expect(fn () => app(RetentionClient::class)->metrics())
            ->toThrow(PushFailedException::class, 'config:clear');
    });
});
