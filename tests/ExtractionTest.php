<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Exceptions\PushFailedException;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Http\RetentionClient;

describe('resolving clients', function (): void {
    it('yields one record per tenant row', function (): void {
        multiTenantConfig();

        makeBusiness();
        makeBusiness(['business_name' => 'Jinja Traders Co']);

        $clients = iterator_to_array(app(ClientResolver::class)->all());

        expect($clients)->toHaveCount(2)
            ->and($clients[0]->name)->toBe('Kampala Retail Ltd')
            ->and($clients[0]->externalId)->toBe('1')
            ->and($clients[0]->isScoped())->toBeTrue();
    });

    it('honours a configured scope', function (): void {
        multiTenantConfig();
        config()->set('retention-extractor.clients.scope', fn ($query) => $query->where('is_active', true));

        makeBusiness();
        makeBusiness(['business_name' => 'Closed Shop', 'is_active' => false]);

        expect(iterator_to_array(app(ClientResolver::class)->all()))->toHaveCount(1);
    });

    it('yields exactly one record for a single-tenant install', function (): void {
        config()->set('retention-extractor.clients', [
            'model' => null,
            'single' => [
                'external_id' => 'ACME-001',
                'name' => 'Acme Hardware',
                'contact_phone' => null,
                'contact_email' => null,
            ],
        ]);

        $clients = iterator_to_array(app(ClientResolver::class)->all());

        expect($clients)->toHaveCount(1)
            ->and($clients[0]->externalId)->toBe('ACME-001')
            ->and($clients[0]->isScoped())->toBeFalse();
    });

    it('refuses to run single-tenant without an identifier', function (): void {
        config()->set('retention-extractor.clients', ['model' => null, 'single' => ['external_id' => null]]);

        expect(fn () => iterator_to_array(app(ClientResolver::class)->all()))
            ->toThrow(ConfigurationException::class, 'RETENTION_EXTERNAL_ID');
    });
});

describe('collecting metrics', function (): void {
    beforeEach(function (): void {
        multiTenantConfig();
        $this->business = makeBusiness();
    });

    it('counts and sums within the window', function (): void {
        makeSale($this->business->id, 100_000, daysAgo: 1, items: 5);
        makeSale($this->business->id, 250_000, daysAgo: 3, items: 8);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];
        $payload = app(SnapshotBuilder::class)->activityPayload($client);

        expect($payload['transactions_7d'])->toBe(2)
            ->and($payload['transaction_value_7d'])->toBe(350000.0)
            ->and($payload['items_sold_7d'])->toBe(13.0);
    });

    it('ignores rows outside the window', function (): void {
        makeSale($this->business->id, 100_000, daysAgo: 1);
        makeSale($this->business->id, 999_000, daysAgo: 30);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->activityPayload($client)['transactions_7d'])->toBe(1);
    });

    it('never counts another tenant\'s rows', function (): void {
        $other = makeBusiness(['business_name' => 'Jinja Traders Co']);

        makeSale($this->business->id, 100_000, daysAgo: 1);
        makeSale($other->id, 500_000, daysAgo: 1);
        makeSale($other->id, 500_000, daysAgo: 1);

        $clients = iterator_to_array(app(ClientResolver::class)->all());
        $builder = app(SnapshotBuilder::class);

        expect($builder->activityPayload($clients[0])['transactions_7d'])->toBe(1)
            ->and($builder->activityPayload($clients[1])['transactions_7d'])->toBe(2);
    });

    it('follows a two-step path to reach the tenant', function (): void {
        $other = makeBusiness(['business_name' => 'Jinja Traders Co']);

        makeSale($this->business->id, 100_000, daysAgo: 1, items: 3);
        makeSale($other->id, 100_000, daysAgo: 1, items: 40);

        $clients = iterator_to_array(app(ClientResolver::class)->all());

        // sale_items has no business_id — it reaches the tenant through sales.
        expect(app(SnapshotBuilder::class)->activityPayload($clients[0])['items_sold_7d'])->toBe(3.0);
    });

    it('reads every row for a single-tenant install', function (): void {
        makeSale($this->business->id, 100_000, daysAgo: 1);
        makeSale(999, 100_000, daysAgo: 1);

        config()->set('retention-extractor.clients', [
            'model' => null,
            'single' => ['external_id' => 'ACME-001', 'name' => 'Acme'],
        ]);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->activityPayload($client)['transactions_7d'])->toBe(2);
    });

    it('fails loudly when a mapped table does not exist', function (): void {
        config()->set('retention-extractor.metrics.transactions_7d.table', 'nope');

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(fn () => app(SnapshotBuilder::class)->activityPayload($client))
            ->toThrow(ConfigurationException::class, "Table 'nope'");
    });
});

describe('the activity payload', function (): void {
    beforeEach(function (): void {
        multiTenantConfig();
        $this->business = makeBusiness();
    });

    it('carries the client identity and product', function (): void {
        $client = iterator_to_array(app(ClientResolver::class)->all())[0];
        $payload = app(SnapshotBuilder::class)->activityPayload($client);

        expect($payload['external_id'])->toBe('1')
            ->and($payload['name'])->toBe('Kampala Retail Ltd')
            ->and($payload['contact_email'])->toBe('owner@kampalaretail.com')
            ->and($payload['product'])->toBe('poscream');
    });

    it('reports the newest activity date', function (): void {
        makeSale($this->business->id, 100_000, daysAgo: 4);
        makeSale($this->business->id, 100_000, daysAgo: 1);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->activityPayload($client)['last_activity_date'])
            ->toBe(now()->subDay()->toDateString());
    });

    it('reports a client with no activity as long dormant rather than skipping it', function (): void {
        $client = iterator_to_array(app(ClientResolver::class)->all())[0];
        $payload = app(SnapshotBuilder::class)->activityPayload($client);

        expect($payload['last_activity_date'])->toBe(now()->subYear()->toDateString())
            ->and($payload['transactions_7d'])->toBe(0);
    });

    it('preserves the raw figures alongside the scored ones', function (): void {
        makeSale($this->business->id, 100_000, daysAgo: 1);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];
        $payload = app(SnapshotBuilder::class)->activityPayload($client);

        expect($payload['raw_payload']['window_days'])->toBe(7)
            ->and($payload['raw_payload']['metrics'])->toHaveKey('transactions_7d');
    });
});

describe('the subscription payload', function (): void {
    beforeEach(function (): void {
        multiTenantConfig();
        $this->business = makeBusiness();

        config()->set('retention-extractor.subscription', [
            'table' => 'subscriptions', 'via' => 'business_id',
            'start' => 'starts_at', 'end' => 'ends_at', 'status' => 'status',
            'status_map' => ['paid' => 'active', 'lapsed' => 'expired'],
        ]);
    });

    it('translates the product\'s own wording', function (): void {
        DB::table('subscriptions')->insert([
            'business_id' => $this->business->id,
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'status' => 'paid',
        ]);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayload($client)['status'])->toBe('active');
    });

    it('returns nothing when the product has no subscription for that client', function (): void {
        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayload($client))->toBeNull();
    });

    it('returns nothing at all when subscriptions are not configured', function (): void {
        config()->set('retention-extractor.subscription', null);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayload($client))->toBeNull();
    });
});

describe('talking to the API', function (): void {
    it('sends a bearer token to the activity endpoint', function (): void {
        Http::fake(['*' => Http::response(['message' => 'Activity snapshot recorded.'])]);

        app(RetentionClient::class)->pushActivity(['external_id' => 'X']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://retention.test/api/v1/activity'
            && $request->hasHeader('Authorization', 'Bearer '.str_repeat('a', 64)));
    });

    it('explains an inactive or mis-scoped key', function (): void {
        Http::fake(['*' => Http::response(['message' => 'API key is inactive.'], 403)]);

        expect(fn () => app(RetentionClient::class)->pushActivity([]))
            ->toThrow(PushFailedException::class, 'scoped to a different product');
    });

    it('surfaces a validation failure verbatim', function (): void {
        Http::fake(['*' => Http::response(['message' => 'The given data was invalid.'], 422)]);

        expect(fn () => app(RetentionClient::class)->pushActivity([]))
            ->toThrow(PushFailedException::class, 'rejected the payload');
    });

    it('refuses to send without an API key', function (): void {
        config()->set('retention-extractor.api.key', null);

        expect(fn () => app(RetentionClient::class)->pushActivity([]))
            ->toThrow(ConfigurationException::class, 'RETENTION_API_KEY');
    });
});

/**
 * A multi-tenant mapping that cannot say which column links a row to its client
 * used to be treated as "no scoping needed" and reported the whole table to
 * everybody. `retention:install` writes exactly that whenever it cannot guess
 * the tenant column, so this was reachable by following the documented setup.
 */
describe('a multi-tenant mapping that cannot reach its tenant', function (): void {
    beforeEach(function (): void {
        multiTenantConfig();
    });

    it('refuses a metric with no via rather than counting the whole table', function (): void {
        makeBusiness(['business_name' => 'Shop A']);

        config()->set('retention-extractor.metrics.transactions_7d', [
            'table' => 'sales', 'count' => '*', 'date' => 'created_at',
        ]);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(fn () => app(SnapshotBuilder::class)->activityPayload($client))
            ->toThrow(ConfigurationException::class, 'metrics.transactions_7d.via');
    });

    it('refuses last_activity with no via', function (): void {
        makeBusiness();

        config()->set('retention-extractor.last_activity', ['table' => 'sales', 'date' => 'created_at']);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(fn () => app(SnapshotBuilder::class)->activityPayload($client))
            ->toThrow(ConfigurationException::class, 'last_activity.via');
    });

    it('refuses a subscription with no via rather than reporting somebody else\'s dates', function (): void {
        $a = makeBusiness(['business_name' => 'Shop A']);
        $b = makeBusiness(['business_name' => 'Shop B']);

        // Only Shop B has a subscription. Unscoped, Shop A was handed it.
        DB::table('subscriptions')->insert([
            'business_id' => $b->id,
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'status' => 'paid',
        ]);

        config()->set('retention-extractor.subscription', [
            'table' => 'subscriptions', 'start' => 'starts_at', 'end' => 'ends_at', 'status' => 'status',
        ]);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect($client->name)->toBe('Shop A')
            ->and(fn () => app(SnapshotBuilder::class)->subscriptionPayload($client))
            ->toThrow(ConfigurationException::class, 'subscription.via');
    });

    it('still leaves a single-tenant install unscoped, which is correct there', function (): void {
        $business = makeBusiness();
        makeSale($business->id, 100, daysAgo: 1);

        config()->set('retention-extractor.clients', [
            'model' => null,
            'single' => ['external_id' => 'ACME-001', 'name' => 'Acme'],
        ]);

        config()->set('retention-extractor.metrics.transactions_7d', [
            'table' => 'sales', 'count' => '*', 'date' => 'created_at',
        ]);
        config()->set('retention-extractor.last_activity', ['table' => 'sales', 'date' => 'created_at']);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->activityPayload($client)['transactions_7d'])->toBe(1);
    });
});

describe('a subscription with no status the product understands', function (): void {
    beforeEach(function (): void {
        multiTenantConfig();
        $this->business = makeBusiness();

        // Clinic Plus's shape: enrolment and expiry dates, no status column.
        config()->set('retention-extractor.subscription', [
            'table' => 'subscriptions', 'via' => 'business_id',
            'start' => 'starts_at', 'end' => 'ends_at', 'status' => 'status',
        ]);
    });

    function subscriptionEnding(int $businessId, string $endDate, ?string $status = null): void
    {
        DB::table('subscriptions')->insert([
            'business_id' => $businessId,
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => $endDate,
            'status' => $status ?? '',
        ]);
    }

    it('reads a lapsed subscription off the end date rather than calling it active', function (): void {
        subscriptionEnding($this->business->id, now()->subMonth()->toDateString());

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayload($client)['status'])->toBe('expired');
    });

    it('still calls a subscription that has not run out active', function (): void {
        subscriptionEnding($this->business->id, now()->addMonths(3)->toDateString());

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayload($client)['status'])->toBe('active');
    });

    it('never infers cancelled, which only a person can say', function (): void {
        subscriptionEnding($this->business->id, now()->subYear()->toDateString());

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayload($client)['status'])->not->toBe('cancelled');
    });

    it('still prefers what the product says when it says something', function (): void {
        subscriptionEnding($this->business->id, now()->subMonth()->toDateString(), 'cancelled');

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayload($client)['status'])->toBe('cancelled');
    });
});
