<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nugsoft\RetentionExtractor\Extraction\ClientResolver;
use Nugsoft\RetentionExtractor\Extraction\SnapshotBuilder;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;

/**
 * A product that adds a row per cycle rather than editing one row per term.
 *
 * School Monitor's shape: renewals bought in advance are rows that have not
 * started, a revoked cycle was terminated and can outlast a newer one, and three
 * dates decide when access ends — the paid end, free grace after it, and a
 * payment deadline that caps both.
 */
function withCycleSubscription(array $overrides = []): void
{
    config()->set('retention-extractor.subscription', [
        'table' => 'branch_licences',
        'via' => ['business_branch_id' => ['business_branches', 'id', 'business_id']],
        'primary_key' => 'id',
        'pick' => 'current',
        'start' => 'start_date',
        'end' => 'end_date',
        'grace' => 'grace_ends_at',
        'deadline' => 'license_expires_at',
        'cancelled' => 'revoked_at',
        // As NugsoftOS serves it: filtered too, so an extractor too old to
        // know `cancelled` drops those rows. This one lifts the filter.
        'where' => ['deleted_at' => null, 'revoked_at' => null],
        ...$overrides,
    ]);
}

function withCycleLicence(array $overrides = []): void
{
    config()->set('retention-extractor.licence', [
        'table' => 'branch_licences',
        'via' => ['business_branch_id' => ['business_branches', 'id', 'business_id']],
        'primary_key' => 'id',
        'status' => null,
        'ceiling' => ['column' => 'license_expires_at'],
        'blocks' => ['suspended_at'],
        'term_ends' => ['end_date', 'grace_ends_at'],
        'term_starts' => ['start_date'],
        'where' => ['deleted_at' => null, 'revoked_at' => null],
        'secret' => 'a-signing-key',
        'route' => 'api/retention/licence',
        ...$overrides,
    ]);
}

function cycleApplier(): LicenceApplier
{
    return new LicenceApplier(config('retention-extractor.licence', []));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function cycle(int $branchId, string $start, string $end, array $attributes = []): int
{
    return DB::table('branch_licences')->insertGetId([
        'business_branch_id' => $branchId,
        'start_date' => $start,
        'end_date' => $end,
        ...$attributes,
    ]);
}

function day(int $offset): string
{
    return now()->addDays($offset)->toDateString();
}

/**
 * @return array<string, mixed>|null
 */
function termReported(): ?array
{
    $client = iterator_to_array(app(ClientResolver::class)->all())[0];

    return app(SnapshotBuilder::class)->subscriptionPayload($client);
}

beforeEach(function (): void {
    multiTenantConfig();

    $this->business = makeBusiness();
    $this->branch = makeBranch($this->business->id, 'Main');
});

describe('which cycle is the term', function (): void {
    it('reports the cycle running today over a renewal bought in advance', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(-100), day(30));
        cycle($this->branch, day(31), day(396));

        expect(termReported())
            ->start_date->toBe(day(-100))
            ->end_date->toBe(day(30));
    });

    it('reports the next cycle to begin when none has started', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(400), day(765));
        cycle($this->branch, day(10), day(375));

        expect(termReported())
            ->start_date->toBe(day(10))
            ->status->toBe('future');
    });

    it('calls a cycle yet to begin future even with a deadline NugsoftOS wrote on it', function (): void {
        // A revoke caps every cycle, waiting ones included. School Monitor
        // still calls the branch scheduled, not overdue.
        withCycleSubscription();
        withCycleLicence();

        cycle($this->branch, day(10), day(375), ['license_expires_at' => day(-1)]);

        // Nor is the cap sent as a payment deadline: a deadline falls within
        // the term, and this one is dated before it starts.
        expect(termReported())
            ->status->toBe('future')
            ->payment_deadline->toBeNull()
            ->effective_end_date->toBe(day(375))
            ->ends_because->toBe('term');
    });

    it('still reports a payment deadline that falls within a cycle yet to begin', function (): void {
        withCycleSubscription();
        withCycleLicence();

        cycle($this->branch, day(10), day(375), ['license_expires_at' => day(40)]);

        expect(termReported())
            ->status->toBe('future')
            ->payment_deadline->toBe(day(40))
            ->effective_end_date->toBe(day(40))
            ->ends_because->toBe('payment_deadline');
    });

    it('counts a cap dated before the cycle once the cycle has begun', function (): void {
        // The cycle started today under a revoke written yesterday. School
        // Monitor enforces it from the first minute, so the push says so.
        withCycleSubscription();
        withCycleLicence();

        cycle($this->branch, day(0), day(365), ['license_expires_at' => day(-1)]);

        expect(termReported())
            ->status->toBe('suspended')
            ->payment_deadline->toBe(day(-1))
            ->ends_because->toBe('payment_deadline');
    });

    it('calls a cycle yet to begin suspended where the product suspended it', function (): void {
        withCycleSubscription();
        withCycleLicence();

        cycle($this->branch, day(10), day(375), ['suspended_at' => now()]);

        expect(termReported()['status'])->toBe('suspended');
    });

    it('never calls a term future for a product with one row per term', function (): void {
        withCycleSubscription(['pick' => null, 'grace' => null, 'deadline' => null]);

        cycle($this->branch, day(10), day(375));

        expect(termReported()['status'])->toBe('active');
    });

    it('gives a tie between cycles yet to begin to the newest row', function (): void {
        withCycleSubscription();

        // Re-issued for the same day: the first is the one it replaced.
        cycle($this->branch, day(10), day(375));
        cycle($this->branch, day(10), day(380));

        expect(termReported()['end_date'])->toBe(day(380));
    });

    it('passes over a revoked cycle, however long it runs', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(-200), day(500), ['revoked_at' => now()]);
        cycle($this->branch, day(-10), day(355));

        expect(termReported()['end_date'])->toBe(day(355));
    });

    it('reports a cycle terminated while it ran as cancelled', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(-465), day(-100));
        cycle($this->branch, day(-100), day(265), ['revoked_at' => now()->subDays(5)]);

        expect(termReported())
            ->status->toBe('cancelled')
            ->end_date->toBe(day(265))
            ->effective_end_date->toBe(day(-5))
            ->ends_because->toBe('cancelled');
    });

    it('keeps a payment deadline that ended access before the cancellation', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(-100), day(265), [
            'license_expires_at' => day(-20),
            'revoked_at' => now()->subDays(5),
        ]);

        expect(termReported())
            ->status->toBe('cancelled')
            ->effective_end_date->toBe(day(-20))
            ->ends_because->toBe('payment_deadline');
    });

    it('reports a cancellation without grace or a deadline mapped', function (): void {
        withCycleSubscription(['grace' => null, 'deadline' => null]);

        cycle($this->branch, day(-100), day(265), ['revoked_at' => now()]);

        expect(termReported()['status'])->toBe('cancelled');
    });

    it('reports a cycle that ran out after an older cancellation as expired', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(-500), day(-135), ['revoked_at' => now()->subDays(300)]);
        cycle($this->branch, day(-200), day(-10));

        expect(termReported())
            ->status->toBe('expired')
            ->end_date->toBe(day(-10));
    });

    it('reports a cancellation that came after the live cycle ran out', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(-400), day(-30));
        cycle($this->branch, day(-29), day(336), ['revoked_at' => now()->subDays(3)]);

        expect(termReported()['status'])->toBe('cancelled');
    });

    it('keeps a cycle in grace over a renewal that was cancelled', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(-365), day(-2), ['grace_ends_at' => day(5)]);
        cycle($this->branch, day(-1), day(364), ['revoked_at' => now()]);

        expect(termReported())
            ->status->toBe('grace')
            ->end_date->toBe(day(-2));
    });

    it('still drops terminated rows where the mapping filters them out', function (): void {
        withCycleSubscription(['cancelled' => null, 'where' => ['deleted_at' => null, 'revoked_at' => null]]);

        cycle($this->branch, day(-465), day(-100));
        cycle($this->branch, day(-100), day(265), ['revoked_at' => now()]);

        expect(termReported())
            ->status->toBe('expired')
            ->end_date->toBe(day(-100));
    });

    it('still reports the row ending last when the mapping does not ask otherwise', function (): void {
        withCycleSubscription(['pick' => null, 'grace' => null, 'deadline' => null]);

        cycle($this->branch, day(-100), day(30));
        cycle($this->branch, day(31), day(396));

        expect(termReported())
            ->end_date->toBe(day(396))
            ->not->toHaveKey('effective_end_date');
    });
});

describe('when access really ends, and why', function (): void {
    beforeEach(fn () => withCycleSubscription());

    it('ends with the term when nothing moves it', function (): void {
        cycle($this->branch, day(-100), day(30));

        expect(termReported())
            ->status->toBe('active')
            ->effective_end_date->toBe(day(30))
            ->ends_because->toBe('term')
            ->grace_ends_at->toBeNull()
            ->payment_deadline->toBeNull();
    });

    it('runs on into free grace after the paid end', function (): void {
        cycle($this->branch, day(-365), day(-2), ['grace_ends_at' => day(5)]);

        expect(termReported())
            ->status->toBe('grace')
            ->end_date->toBe(day(-2))
            ->effective_end_date->toBe(day(5))
            ->ends_because->toBe('grace');
    });

    it('stops at a payment deadline before the paid end', function (): void {
        cycle($this->branch, day(-100), day(200), ['license_expires_at' => day(10)]);

        expect(termReported())
            ->status->toBe('active')
            ->end_date->toBe(day(200))
            ->payment_deadline->toBe(day(10))
            ->effective_end_date->toBe(day(10))
            ->ends_because->toBe('payment_deadline');
    });

    it('calls a passed deadline on a running term suspended, not expired', function (): void {
        // The time was bought and is being withheld for want of payment.
        // School Monitor shows this as overdue.
        cycle($this->branch, day(-100), day(200), ['license_expires_at' => day(-3)]);

        expect(termReported())
            ->status->toBe('suspended')
            ->ends_because->toBe('payment_deadline');
    });

    it('calls a deadline that cuts grace short expired, not suspended', function (): void {
        // Grace is free time. Ending it early withholds nothing bought.
        cycle($this->branch, day(-365), day(-5), [
            'grace_ends_at' => day(10),
            'license_expires_at' => day(-1),
        ]);

        expect(termReported())
            ->status->toBe('expired')
            ->ends_because->toBe('payment_deadline');
    });

    it('still reports a cancellation the product recorded', function (): void {
        withCycleSubscription(['status_map' => ['void' => 'cancelled']]);

        cycle($this->branch, day(-100), day(30), ['status' => 'void']);

        expect(termReported())
            ->status->toBe('cancelled')
            ->effective_end_date->toBe(day(30));
    });

    it('lets the dates overrule any other word in the status column', function (): void {
        cycle($this->branch, day(-100), day(200), ['status' => 'active', 'license_expires_at' => day(-3)]);

        expect(termReported()['status'])->toBe('suspended');
    });

    it('reports a branch\'s own cancellation too', function (): void {
        withBranches();
        withCycleSubscription(['branch' => 'business_branch_id']);

        cycle($this->branch, day(-100), day(30), ['status' => 'cancelled']);

        $client = iterator_to_array(app(ClientResolver::class)->all())[0];

        expect(app(SnapshotBuilder::class)->subscriptionPayloads($client)[0]['status'])->toBe('cancelled');
    });

    it('calls a term that has run out expired, whatever deadline it carried', function (): void {
        cycle($this->branch, day(-400), day(-30), ['license_expires_at' => day(-60)]);

        expect(termReported()['status'])->toBe('expired');
    });

    it('ignores a deadline later than the term it sits on', function (): void {
        cycle($this->branch, day(-100), day(30), ['license_expires_at' => day(90)]);

        expect(termReported())
            ->effective_end_date->toBe(day(30))
            ->ends_because->toBe('term');
    });
});

describe('reading back a product that keeps a cycle per row', function (): void {
    beforeEach(function (): void {
        withCycleLicence();

        $this->current = cycle($this->branch, day(-100), day(200));
    });

    it('is not held at no access by a deadline left on a cycle long over', function (): void {
        cycle($this->branch, day(-500), day(-135), ['license_expires_at' => day(-140)]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeTrue();
    });

    it('says no while the product has switched the client off on its own row', function (): void {
        // School Monitor's manager deactivates a branch on `school_branches`,
        // not on any of its terms, and the school app then lets nobody in.
        withCycleLicence(['client_blocks' => ['table' => 'businesses', 'key' => 'id', 'columns' => ['suspended_at']]]);
        DB::table('businesses')->where('id', $this->business->id)->update(['suspended_at' => now()]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeFalse();
    });

    it('pushes a running term as suspended once the client is switched off on its own row', function (): void {
        withCycleSubscription();
        withCycleLicence(['client_blocks' => ['table' => 'businesses', 'key' => 'id', 'columns' => ['suspended_at']]]);
        DB::table('businesses')->where('id', $this->business->id)->update(['suspended_at' => now()]);

        expect(termReported()['status'])->toBe('suspended');
    });

    it('never writes the switch on the client\'s own row', function (): void {
        withCycleLicence(['client_blocks' => ['table' => 'businesses', 'key' => 'id', 'columns' => ['suspended_at']]]);
        DB::table('businesses')->where('id', $this->business->id)->update(['suspended_at' => now()]);

        cycleApplier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->business->id,
            'grants_access' => true,
        ])));

        expect(DB::table('businesses')->where('id', $this->business->id)->value('suspended_at'))->not->toBeNull();
    });

    it('reads nothing on the client\'s own row unless the mapping names it', function (): void {
        DB::table('businesses')->where('id', $this->business->id)->update(['suspended_at' => now()]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeTrue();
    });

    it('says no while the product has suspended the branch itself', function (): void {
        DB::table('branch_licences')->where('id', $this->current)->update(['suspended_at' => now()]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeFalse();

        DB::table('branch_licences')->where('id', $this->current)->update(['suspended_at' => null]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeTrue();
    });

    it('never writes the suspension, only the ceiling', function (): void {
        DB::table('branch_licences')->where('id', $this->current)->update(['suspended_at' => now()]);

        cycleApplier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->business->id,
            'grants_access' => true,
        ])));

        $row = DB::table('branch_licences')->find($this->current);

        expect($row->suspended_at)->not->toBeNull()
            ->and($row->license_expires_at)->toBeNull();
    });

    it('clears a payment deadline outright on a grant', function (): void {
        DB::table('branch_licences')->where('id', $this->current)->update(['license_expires_at' => day(-1)]);

        cycleApplier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->business->id,
            'grants_access' => true,
        ])));

        expect(DB::table('branch_licences')->where('id', $this->current)->value('license_expires_at'))->toBeNull();
    });

    it('leaves a revoked cycle alone', function (): void {
        $revoked = cycle($this->branch, day(-300), day(300), ['revoked_at' => now()]);

        cycleApplier()->apply(LicenceState::fromPayload(licencePayload([
            'external_id' => (string) $this->business->id,
            'grants_access' => false,
        ])));

        expect(DB::table('branch_licences')->where('id', $revoked)->value('license_expires_at'))->toBeNull()
            ->and(DB::table('branch_licences')->where('id', $this->current)->value('license_expires_at'))->not->toBeNull();
    });

    it('answers no idea when every cycle has run out', function (): void {
        DB::table('branch_licences')->where('id', $this->current)->update(['end_date' => day(-5)]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeNull();
    });

    it('is not held back by a deadline on a renewal that has not begun', function (): void {
        cycle($this->branch, day(201), day(566), ['license_expires_at' => day(-1)]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeTrue();
    });

    it('is not held back by a suspension stamped on a renewal that has not begun', function (): void {
        cycle($this->branch, day(201), day(566), ['suspended_at' => now()]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeTrue();
    });

    it('answers no idea when no cycle has begun, whatever ceiling it carries', function (): void {
        DB::table('branch_licences')->where('id', $this->current)->delete();

        cycle($this->branch, day(10), day(375), ['license_expires_at' => day(-1)]);
        cycle($this->branch, day(376), day(740));

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeNull();
    });

    it('says no when the product suspended the next cycle to begin', function (): void {
        DB::table('branch_licences')->where('id', $this->current)->delete();

        cycle($this->branch, day(10), day(375), ['suspended_at' => now()]);
        cycle($this->branch, day(376), day(740));

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeFalse();
    });

    it('looks only at the next cycle to begin for a suspension', function (): void {
        DB::table('branch_licences')->where('id', $this->current)->delete();

        cycle($this->branch, day(10), day(375));
        cycle($this->branch, day(376), day(740), ['suspended_at' => now()]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeNull();
    });

    it('still asks every running cycle when the mapping does not say when they begin', function (): void {
        withCycleLicence(['term_starts' => null]);

        cycle($this->branch, day(201), day(566), ['license_expires_at' => day(-1)]);

        expect(cycleApplier()->grantsAccess((string) $this->business->id))->toBeFalse();
    });

    it('pushes the cycle running today as active while a renewal carries a passed deadline', function (): void {
        withCycleSubscription();

        cycle($this->branch, day(201), day(566), ['license_expires_at' => day(-1)]);

        expect(termReported()['status'])->toBe('active');
    });
});
