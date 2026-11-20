<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nugsoft\RetentionExtractor\Tests\Fixtures\ClosableBusiness;

/**
 * A client the product has closed for good.
 *
 * School Monitor soft-deletes a branch it closes, and nothing restores one.
 * Left out of the client list by its own model, such a branch just stopped
 * being reported: NugsoftOS went on granting it a licence and scoring it as
 * going dormant. With `clients.ended` named, its term is reported once more,
 * as cancelled — to a NugsoftOS that already licenses it, and no other.
 */
beforeEach(function (): void {
    multiTenantConfig();

    config()->set('retention-extractor.clients.model', ClosableBusiness::class);
    config()->set('retention-extractor.clients.ended', 'deleted_at');
    config()->set('retention-extractor.subscription', [
        'table' => 'subscriptions', 'via' => 'business_id',
        'start' => 'starts_at', 'end' => 'ends_at', 'status' => 'status',
    ]);

    Http::fake(['*' => Http::response(['message' => 'ok'])]);
});

/**
 * A closed client with a term still running, which NugsoftOS has licensed here.
 */
function closedClient(string $endsAt, bool $licensed = true): ClosableBusiness
{
    $business = ClosableBusiness::create(['business_name' => 'Closed Branch']);

    DB::table('subscriptions')->insert([
        'business_id' => $business->id,
        'starts_at' => now()->subYear()->toDateString(),
        'ends_at' => $endsAt,
        'status' => 'active',
    ]);

    if ($licensed) {
        DB::table('retention_licences')->insert([
            'external_id' => (string) $business->id,
            'branch_external_id' => '',
            'licence_version' => 3,
            'grants_access' => true,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $business->delete();

    return $business;
}

it('reports a closed client NugsoftOS licenses as cancelled, from the day it closed', function (): void {
    $this->travelTo('2026-11-20 10:00:00');

    closedClient(endsAt: '2027-06-30');

    $this->artisan('retention:push')->assertExitCode(0);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/subscription')
        && $request['status'] === 'cancelled'
        && $request['end_date'] === '2027-06-30'
        && $request['effective_end_date'] === '2026-11-20'
        && $request['ends_because'] === 'cancelled');
});

it('sends a closed client no activity', function (): void {
    closedClient(endsAt: now()->addMonths(6)->toDateString());

    $this->artisan('retention:push')->assertExitCode(0);

    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/activity'));
});

it('keeps the day and reason of a term that ran out before the client closed', function (): void {
    $this->travelTo('2026-11-20 10:00:00');

    closedClient(endsAt: '2026-09-30');

    $this->artisan('retention:push')->assertExitCode(0);

    Http::assertSent(fn ($request): bool => $request['status'] === 'cancelled'
        && $request['effective_end_date'] === '2026-09-30'
        && $request['ends_because'] === 'term');
});

it('says nothing of a closed client NugsoftOS has never licensed', function (): void {
    // Reporting it would create a licence there, already cancelled, and count
    // a client lost that NugsoftOS never had.
    closedClient(endsAt: now()->addMonths(6)->toDateString(), licensed: false);

    $this->artisan('retention:push')->assertExitCode(0);

    Http::assertNothingSent();
});

it('says nothing of a closed client unless the mapping names how to tell', function (): void {
    config()->set('retention-extractor.clients.ended', null);

    closedClient(endsAt: now()->addMonths(6)->toDateString());

    $this->artisan('retention:push')->assertExitCode(0);

    Http::assertNothingSent();
});

it('still reports a live client as it always has', function (): void {
    $live = ClosableBusiness::create(['business_name' => 'Open Branch']);

    DB::table('subscriptions')->insert([
        'business_id' => $live->id,
        'starts_at' => now()->subYear()->toDateString(),
        'ends_at' => now()->addMonths(6)->toDateString(),
        'status' => 'active',
    ]);

    $this->artisan('retention:push')->assertExitCode(0);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/subscription')
        && $request['name'] === 'Open Branch'
        && $request['status'] === 'active');
});
