<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Tests\Fixtures\Business;

/**
 * `retention:push` is the whole user-facing surface of this package — it is
 * what the scheduler runs and what a product team types to check their mapping.
 * Everything underneath it was covered and the command itself was not, so the
 * promises the README makes about it were promises nothing checked.
 */
beforeEach(function (): void {
    multiTenantConfig();
});

function seedTenantWithASale(string $name = 'Kampala Retail Ltd'): Business
{
    $business = makeBusiness(['business_name' => $name]);

    makeSale($business->id, 120_000, daysAgo: 1, items: 4);

    DB::table('sessions_log')->insert([
        'business_id' => $business->id,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    return $business;
}

describe('retention:push', function (): void {
    it('sends one activity payload per client', function (): void {
        Http::fake(['*' => Http::response(['message' => 'Activity snapshot recorded.'])]);

        seedTenantWithASale();
        seedTenantWithASale('Jinja Traders Co');

        $this->artisan('retention:push')
            ->expectsOutputToContain('Pushed 2 client(s)')
            ->assertExitCode(0);

        Http::assertSentCount(2);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/activity')
            && $request['product'] === 'poscream'
            && $request['transactions_7d'] === 1
            && $request['items_sold_7d'] === 4.0);
    });

    it('sends the subscription too when one is configured and present', function (): void {
        Http::fake(['*' => Http::response(['message' => 'ok'])]);

        $business = seedTenantWithASale();

        config()->set('retention-extractor.subscription', [
            'table' => 'subscriptions', 'via' => 'business_id',
            'start' => 'starts_at', 'end' => 'ends_at', 'status' => 'status',
            'status_map' => ['paid' => 'active'],
        ]);

        DB::table('subscriptions')->insert([
            'business_id' => $business->id,
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->addMonths(2)->toDateString(),
            'status' => 'paid',
        ]);

        $this->artisan('retention:push')->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/subscription')
            && $request['status'] === 'active');
    });

    /**
     * Asserted against the captured output rather than with
     * expectsOutputToContain(). That helper matches each substring against a
     * single write, and Mockery hands a write to the FIRST expectation that
     * matches it — so 'Kampala Retail Ltd' claims the line holding the JSON and
     * every later substring in the same write looks absent. Reading the output
     * back is both accurate and easier to see.
     */
    it('sends nothing on a dry run, and says what it would have sent', function (): void {
        Http::fake();

        seedTenantWithASale();

        expect(Artisan::call('retention:push', ['--dry-run' => true]))->toBe(0);

        $output = Artisan::output();

        expect($output)
            ->toContain('Kampala Retail Ltd')
            ->toContain('"transactions_7d": 1')
            ->toContain('"items_sold_7d": 4')
            ->toContain('"product": "poscream"')
            ->toContain('Nothing was sent');

        Http::assertNothingSent();
    });

    it('pushes only the client asked for', function (): void {
        Http::fake(['*' => Http::response(['message' => 'ok'])]);

        seedTenantWithASale();
        $second = seedTenantWithASale('Jinja Traders Co');

        $this->artisan('retention:push', ['--client' => (string) $second->id])
            ->expectsOutputToContain('Pushed 1 client(s)')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['name'] === 'Jinja Traders Co');
    });

    /**
     * The README promises this outright: "One client failing does not stop the
     * others." Nothing checked it.
     */
    it('carries on after one client fails, and reports the failure', function (): void {
        seedTenantWithASale('Good Shop');
        seedTenantWithASale('Bad Shop');

        Http::fake(fn ($request) => $request['name'] === 'Bad Shop'
            ? Http::response(['message' => 'The given data was invalid.'], 422)
            : Http::response(['message' => 'ok']));

        $this->artisan('retention:push')
            ->expectsOutputToContain('Bad Shop')
            ->expectsOutputToContain('Pushed 1, failed 1')
            ->assertExitCode(1);

        // The good one still went, which is the whole point.
        Http::assertSent(fn ($request): bool => $request['name'] === 'Good Shop');
    });

    it('stays silent when the extractor is turned off', function (): void {
        Http::fake();

        seedTenantWithASale();

        config()->set('retention-extractor.enabled', false);

        $this->artisan('retention:push')
            ->expectsOutputToContain('RETENTION_ENABLED')
            ->assertExitCode(0);

        Http::assertNothingSent();
    });

    it('refuses to run with no product code, and points at the installer', function (): void {
        Http::fake();

        config()->set('retention-extractor.product', null);

        $this->artisan('retention:push')
            ->expectsOutputToContain('RETENTION_PRODUCT_CODE')
            ->expectsOutputToContain('retention:install')
            ->assertExitCode(1);

        Http::assertNothingSent();
    });

    it('refuses to run with nothing mapped', function (): void {
        Http::fake();

        config()->set('retention-extractor.metrics', []);

        $this->artisan('retention:push')
            ->expectsOutputToContain('nothing to report')
            ->assertExitCode(1);

        Http::assertNothingSent();
    });

    /**
     * A misconfigured mapping must never reach the API. It is caught per client
     * rather than up front, because the table is only checked when it is read.
     */
    it('does not push a client whose mapping points at a table that is not there', function (): void {
        Http::fake();

        seedTenantWithASale();

        config()->set('retention-extractor.metrics.transactions_7d.table', 'nope');

        $this->artisan('retention:push')
            ->expectsOutputToContain("Table 'nope'")
            ->assertExitCode(1);

        Http::assertNothingSent();
    });
});
