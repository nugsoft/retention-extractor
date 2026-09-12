<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;
use Nugsoft\RetentionExtractor\Support\ProductMapping;

/**
 * Asking NugsoftOS where things live.
 *
 * For a product whose team owns this integration the mapping belongs in its
 * own repository, and `local` is right. This is the other case: a product that
 * cannot take a change at all, where the package goes in once and everything
 * after has to be answerable from the other side.
 */
function remoteMapping(array $body): void
{
    config()->set('retention-extractor.mapping_source', 'remote');
    config()->set('retention-extractor.api.url', 'https://retention.test');
    config()->set('retention-extractor.api.key', 'a-product-key');

    Http::fake(['https://retention.test/api/v1/mapping' => Http::response($body)]);
}

function schoolMonitorMapping(): array
{
    return [
        'product' => ['code' => 'school_monitor', 'name' => 'School Monitor'],
        'subscription' => [
            'table' => 'branch_licences',
            'via' => ['business_branch_id' => ['business_branches', 'id', 'business_id']],
            'start' => 'start_date',
            'end' => 'end_date',
            'where' => ['deleted_at' => null],
        ],
        'licence' => [
            'table' => 'branch_licences',
            'via' => ['business_branch_id' => ['business_branches', 'id', 'business_id']],
            'primary_key' => 'id',
            'ceiling' => ['column' => 'license_expires_at'],
            'where' => ['deleted_at' => null],
        ],
    ];
}

function mapping(): ProductMapping
{
    return app(ProductMapping::class);
}

beforeEach(function (): void {
    Cache::forget(ProductMapping::CacheKey);
});

describe('a product told to ask', function (): void {
    it('uses the mapping it is given rather than its own file', function (): void {
        config()->set('retention-extractor.licence', ['table' => 'something_stale', 'secret' => 'ours']);

        remoteMapping(schoolMonitorMapping());

        expect(mapping()->licence()['table'])->toBe('branch_licences')
            ->and(mapping()->subscription()['end'])->toBe('end_date');
    });

    it('keeps its own secret and route, whatever it is sent', function (): void {
        config()->set('retention-extractor.licence', [
            'secret' => 'ours-and-ours-alone',
            'route' => 'api/retention/licence',
        ]);

        remoteMapping([
            ...schoolMonitorMapping(),
            // Nothing stops a compromised or mistaken answer carrying these.
            'licence' => [...schoolMonitorMapping()['licence'], 'secret' => 'theirs', 'route' => 'api/hijack'],
        ]);

        expect(mapping()->licence()['secret'])->toBe('ours-and-ours-alone')
            ->and(mapping()->licence()['route'])->toBe('api/retention/licence');
    });

    it('applies a licence through a mapping it was never configured with', function (): void {
        // The whole point: this install has no licence mapping of its own.
        config()->set('retention-extractor.licence', ['secret' => 'a-signing-key', 'table' => null]);

        remoteMapping(schoolMonitorMapping());

        $business = makeBusiness();
        $branchId = makeBranch($business->id, 'Main');

        DB::table('branch_licences')->insert([
            'business_branch_id' => $branchId,
            'end_date' => now()->addYear()->toDateString(),
            'license_expires_at' => null,
        ]);

        app(LicenceApplier::class)->apply(LicenceState::fromPayload([
            'external_id' => (string) $business->id,
            'product' => 'school_monitor',
            'grants_access' => false,
            'status' => 'suspended',
            'end_date' => now()->addYear()->toDateString(),
            'effective_end_date' => now()->addYear()->toDateString(),
            'licence_version' => 1,
        ]));

        expect(DB::table('branch_licences')->where('id', 1)->value('license_expires_at'))
            ->toBe(now()->subDay()->startOfDay()->toDateString());
    });

    it('asks once and remembers the answer', function (): void {
        remoteMapping(schoolMonitorMapping());

        mapping()->licence();
        mapping()->licence();
        mapping()->subscription();

        Http::assertSentCount(1);
    });

    it('asks again once told to forget', function (): void {
        remoteMapping(schoolMonitorMapping());

        mapping()->licence();
        mapping()->forget();
        mapping()->licence();

        Http::assertSentCount(2);
    });
});

describe('when NugsoftOS cannot be reached', function (): void {
    it('keeps using the last answer it had', function (): void {
        remoteMapping(schoolMonitorMapping());
        mapping()->licence();

        Http::fake(['https://retention.test/api/v1/mapping' => Http::response('down', 503)]);

        expect(mapping()->licence()['table'])->toBe('branch_licences');
    });

    it('reports nothing mapped rather than guessing, when it never had an answer', function (): void {
        config()->set('retention-extractor.licence', ['secret' => 'a-signing-key']);
        config()->set('retention-extractor.mapping_source', 'remote');
        config()->set('retention-extractor.api.url', 'https://retention.test');
        config()->set('retention-extractor.api.key', 'a-product-key');

        Http::fake(['https://retention.test/api/v1/mapping' => Http::response('down', 503)]);

        // Not configured, so the webhook answers 503 and is retried. The one
        // thing it must never do is write a licence against a table it is no
        // longer sure about.
        expect(app(LicenceApplier::class)->isConfigured())->toBeFalse()
            ->and(mapping()->subscription())->toBeNull();
    });
});

describe('a product that owns its own mapping', function (): void {
    it('never asks, and reads its own file', function (): void {
        Http::fake();

        withStatusLicence();
        config()->set('retention-extractor.subscription', ['table' => 'subscriptions', 'via' => 'business_id']);

        expect(mapping()->licence()['table'])->toBe('facilities')
            ->and(mapping()->subscription()['table'])->toBe('subscriptions');

        Http::assertNothingSent();
    });
});
