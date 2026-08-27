<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Http\RetentionClient;
use Nugsoft\RetentionExtractor\Tests\Fixtures\Business;

/**
 * Pushes against a real Retention Intel instance.
 *
 * Skipped unless RETENTION_TEST_URL and RETENTION_TEST_KEY are set, so the
 * suite stays green on CI without one running.
 */
beforeEach(function (): void {
    $url = env('RETENTION_TEST_URL');
    $key = env('RETENTION_TEST_KEY');

    if (blank($url) || blank($key)) {
        $this->markTestSkipped('Set RETENTION_TEST_URL and RETENTION_TEST_KEY to run integration tests.');
    }

    config()->set('retention-extractor.api.url', $url);
    config()->set('retention-extractor.api.key', $key);
    config()->set('retention-extractor.product', env('RETENTION_TEST_PRODUCT', 'poscream'));

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
            'table' => 'sale_items', 'sum' => 'quantity',
            'via' => ['sale_id' => ['sales', 'id', 'business_id']], 'date' => 'created_at',
        ],
    ]);

    config()->set('retention-extractor.subscription', [
        'table' => 'subscriptions', 'via' => 'business_id',
        'start' => 'starts_at', 'end' => 'ends_at', 'status' => 'status',
        'status_map' => ['paid' => 'active'],
    ]);
});

it('pushes a real snapshot end to end', function (): void {
    $business = Business::create([
        'business_name' => 'Integration Test Shop',
        'phone' => '+256700999888',
        'email' => 'integration@test.com',
        'is_active' => true,
    ]);

    $saleId = DB::table('sales')->insertGetId([
        'business_id' => $business->id, 'total' => 450000,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
    DB::table('sale_items')->insert([
        'sale_id' => $saleId, 'quantity' => 17,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
    DB::table('sessions_log')->insert([
        'business_id' => $business->id,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
    DB::table('subscriptions')->insert([
        'business_id' => $business->id,
        'starts_at' => now()->subYear()->toDateString(),
        'ends_at' => now()->addMonths(4)->toDateString(),
        'status' => 'paid',
    ]);

    $client = iterator_to_array(app(ClientResolver::class)->all())[0];
    $builder = app(SnapshotBuilder::class);
    $api = app(RetentionClient::class);

    $activity = $api->pushActivity($builder->activityPayload($client));
    $subscription = $api->pushSubscription($builder->subscriptionPayload($client));

    expect($activity)->toHaveKeys(['message', 'client_id', 'snapshot_id'])
        ->and($activity['message'])->toBe('Activity snapshot recorded.')
        ->and($subscription['message'])->toBe('Subscription record updated.');

    // Idempotency: the same push again must not create a second snapshot.
    $repeat = $api->pushActivity($builder->activityPayload($client));

    expect($repeat['snapshot_id'])->toBe($activity['snapshot_id'])
        ->and($repeat['client_id'])->toBe($activity['client_id']);
})->group('integration');

/**
 * The same journey a product team actually takes: run the command, against a
 * real instance, and let it resolve, aggregate, build and post on its own.
 *
 * The test above proves the pieces work; this proves the thing they type does.
 */
it('pushes through the command itself', function (): void {
    $business = Business::create([
        'business_name' => 'Command Integration Shop',
        'phone' => '+256700111222',
        'email' => 'command@test.com',
        'is_active' => true,
    ]);

    $saleId = DB::table('sales')->insertGetId([
        'business_id' => $business->id, 'total' => 275000,
        'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);
    DB::table('sale_items')->insert([
        'sale_id' => $saleId, 'quantity' => 9,
        'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);
    DB::table('sessions_log')->insert([
        'business_id' => $business->id,
        'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);

    // Dry run first: it must describe the push without performing it.
    expect(Artisan::call('retention:push', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())
        ->toContain('Command Integration Shop')
        ->toContain('"transactions_7d": 1')
        ->toContain('Nothing was sent');

    // Then for real.
    expect(Artisan::call('retention:push', ['--client' => (string) $business->id]))->toBe(0);
    expect(Artisan::output())->toContain('Pushed 1 client(s)');

    // Re-running is safe — both endpoints are idempotent.
    expect(Artisan::call('retention:push', ['--client' => (string) $business->id]))->toBe(0);
})->group('integration');
