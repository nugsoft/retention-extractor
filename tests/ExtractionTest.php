<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Exceptions\ConfigurationException;
use Nugsoft\RetentionExtractor\Exceptions\PushFailedException;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Nugsoft\RetentionExtractor\Tests\Fixtures\Business;

function multiTenantConfig(): void
{
    config()->set('retention-extractor.clients', [
        'model' => Business::class,
        'external_id' => 'id',
        'name' => 'business_name',
        'contact_phone' => 'phone',
        'contact_email' => 'email',
        'scope' => null,
        'single' => [],
    ]);

    config()->set('retention-extractor.last_activity', [
        'table' => 'sales', 'via' => 'business_id', 'date' => 'created_at',
    ]);

    config()->set('retention-extractor.metrics', [
        'login_count_7d' => ['table' => 'sessions_log', 'count' => '*', 'via' => 'business_id', 'date' => 'created_at'],
        'transactions_7d' => ['table' => 'sales', 'count' => '*', 'via' => 'business_id', 'date' => 'created_at'],
        'transaction_value_7d' => ['table' => 'sales', 'sum' => 'total', 'via' => 'business_id', 'date' => 'created_at'],
        'items_sold_7d' => [
            'table' => 'sale_items',
            'sum' => 'quantity',
            'via' => ['sale_id' => ['sales', 'id', 'business_id']],
            'date' => 'created_at',
        ],
    ]);
}

function makeBusiness(array $attributes = []): Business
{
    return Business::create([
        'business_name' => 'Kampala Retail Ltd',
        'phone' => '+256700000001',
        'email' => 'owner@kampalaretail.com',
        'is_active' => true,
        ...$attributes,
    ]);
}

function makeSale(int $businessId, float $total, int $daysAgo = 0, int $items = 0): int
{
    $saleId = DB::table('sales')->insertGetId([
        'business_id' => $businessId,
        'total' => $total,
        'created_at' => now()->subDays($daysAgo),
        'updated_at' => now()->subDays($daysAgo),
    ]);

    if ($items > 0) {
        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'quantity' => $items,
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    return $saleId;
}

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
